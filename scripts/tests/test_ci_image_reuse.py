"""Image reuse must preserve inputs, revision checks and the release trust boundary."""
from contextlib import contextmanager
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import threading
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('reuse_images', Path(__file__).parents[1] / 'ci-images.py')
images = importlib.util.module_from_spec(spec)
spec.loader.exec_module(images)

ENV = {'GITHUB_EVENT_NAME': 'push', 'GITHUB_REF': 'refs/heads/main',
       'GITHUB_REPOSITORY': 'Kitserelaz/mediawiki-www', 'GITHUB_SHA': 'a' * 40}
KEY = 'c' * 64
DIGEST = 'sha256:' + 'b' * 64
REPOSITORY = 'ghcr.io/kitserelaz/mediawiki-www-counter'


@contextmanager
def working_directory(path):
    previous = Path.cwd()
    os.chdir(path)
    try:
        yield
    finally:
        os.chdir(previous)


class ImageReuseTests(unittest.TestCase):
    def test_input_fingerprints_include_context_and_builder_recipe(self):
        with tempfile.TemporaryDirectory() as directory, working_directory(directory):
            def git(*args):
                return subprocess.check_output(['git', *args], stderr=subprocess.STDOUT)
            git('init', '-q'); git('config', 'user.name', 'Test'); git('config', 'user.email', 'test@example.invalid')
            Path('Moe-Counter').mkdir(); Path('scripts').mkdir()
            Path('Moe-Counter/index.js').write_text('counter')
            Path('scripts/ci-images.py').write_text('recipe')
            Path('wiki.php').write_text('wiki')
            git('add', '.'); git('commit', '-qm', 'initial')
            counter = images.input_key('counter'); farm = images.input_key('php')
            Path('wiki.php').write_text('changed wiki')
            with self.assertRaises(subprocess.CalledProcessError):
                images.input_key('php')
            git('commit', '-qam', 'wiki update')
            self.assertEqual(counter, images.input_key('counter'))
            self.assertNotEqual(farm, images.input_key('php'))
            Path('Moe-Counter/index.js').write_text('changed counter')
            git('commit', '-qam', 'counter update')
            self.assertNotEqual(counter, images.input_key('counter'))
            counter = images.input_key('counter')
            Path('scripts/ci-images.py').write_text('new recipe')
            git('commit', '-qam', 'recipe update')
            self.assertNotEqual(counter, images.input_key('counter'))

    def test_registry_cache_is_pinned_and_validated_before_reuse(self):
        manifest = json.dumps({'digest': DIGEST})
        image = json.dumps({'architecture': 'amd64', 'os': 'linux',
                            'config': {'Labels': {images.INPUT_LABEL: KEY}}})
        with patch.object(images.subprocess, 'check_output', side_effect=[manifest, image]) as inspect:
            self.assertEqual(images.reusable_image(REPOSITORY, KEY), REPOSITORY + '@' + DIGEST)
        self.assertIn(REPOSITORY + '@' + DIGEST, inspect.call_args_list[1].args[0])

    def test_cache_mismatch_wrong_platform_and_missing_cache_fall_back_to_build(self):
        for arch, key in [('arm64', KEY), ('amd64', 'd' * 64)]:
            with self.subTest(arch=arch, key=key), patch.object(images.subprocess, 'check_output', side_effect=[
                    json.dumps({'digest': DIGEST}), json.dumps({'architecture': arch, 'os': 'linux',
                    'config': {'Labels': {images.INPUT_LABEL: key}}})]):
                self.assertIsNone(images.reusable_image(REPOSITORY, KEY))
        for error in [subprocess.CalledProcessError(1, ['inspect']), subprocess.TimeoutExpired(['inspect'], 30)]:
            with patch.object(images.subprocess, 'check_output', side_effect=error):
                self.assertIsNone(images.reusable_image(REPOSITORY, KEY))
        with patch.object(images.subprocess, 'check_output', return_value='{}'):
            self.assertIsNone(images.reusable_image(REPOSITORY, KEY))

    def test_oci_index_uses_the_verified_amd64_config(self):
        image = {'linux/amd64': {'architecture': 'amd64', 'os': 'linux',
                                'config': {'Labels': {images.INPUT_LABEL: KEY}}}}
        with patch.object(images.subprocess, 'check_output', side_effect=[json.dumps({'digest': DIGEST}), json.dumps(image)]):
            self.assertEqual(images.reusable_image(REPOSITORY, KEY), REPOSITORY + '@' + DIGEST)

    def test_reuse_preserves_tested_layers_but_stamps_candidate_revision(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, ENV), \
                patch.object(images, 'input_key', return_value=KEY), \
                patch.object(images, 'reusable_image', return_value=REPOSITORY + '@' + DIGEST):
            def build(command):
                dockerfile = Path(command[command.index('-f') + 1]).read_text()
                self.assertEqual(dockerfile, 'FROM ' + REPOSITORY + '@' + DIGEST + '\n'
                                 'LABEL org.opencontainers.image.revision="' + ENV['GITHUB_SHA'] + '"\n')
                self.assertIn('--push', command)
                Path(command[command.index('--metadata-file') + 1]).write_text(json.dumps({'containerimage.digest': DIGEST}))
            with patch.object(images, 'run', side_effect=build):
                images.build('counter', Path(directory))
            descriptor = json.loads((Path(directory) / 'counter.json').read_text())['counter']
            self.assertEqual(descriptor, {'image': REPOSITORY + '@' + DIGEST, 'input_key': KEY})

    def test_farm_builds_start_together_on_the_shared_builder(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, ENV), \
                patch.object(images, 'input_key', return_value=KEY), \
                patch.object(images, 'reusable_image', return_value=None):
            barrier = threading.Barrier(2)
            def build(command):
                barrier.wait(timeout=10)
                Path(command[command.index('--metadata-file') + 1]).write_text(json.dumps({'containerimage.digest': DIGEST}))
            with patch.object(images, 'run', side_effect=build):
                images.build('farm', Path(directory))
            self.assertEqual(set(json.loads((Path(directory) / 'farm.json').read_text())), {'php', 'web'})

    def descriptors(self, directory):
        items = {name: {'image': 'ghcr.io/kitserelaz/mediawiki-www-' + name + '@' + DIGEST,
                        'input_key': KEY} for name in ['php', 'web', 'counter']}
        (Path(directory) / 'all.json').write_text(json.dumps(items))
        return items

    def test_only_release_promotes_tested_inputs_and_manifest_keeps_exact_digests(self):
        with tempfile.TemporaryDirectory() as directory, working_directory(directory), patch.dict(os.environ, ENV):
            items = self.descriptors(directory)
            with patch.object(images, 'run') as run:
                images.release(Path(directory))
            self.assertEqual(run.call_count, 3)
            self.assertTrue(all('tested-inputs-' + KEY in c.args[0][-2] for c in run.call_args_list))
            manifest = json.loads(Path('release.json').read_text())
            self.assertEqual(manifest, {'commit': ENV['GITHUB_SHA'], 'images': {n: i['image'] for n, i in items.items()}})

    def test_failed_promotion_does_not_discard_a_tested_release(self):
        with tempfile.TemporaryDirectory() as directory, working_directory(directory), patch.dict(os.environ, ENV):
            self.descriptors(directory)
            with patch.object(images, 'run', side_effect=subprocess.CalledProcessError(1, ['create'])):
                images.release(Path(directory))
            self.assertTrue(Path('release.json').is_file())

    def test_invalid_or_untrusted_release_cannot_promote_cache(self):
        with tempfile.TemporaryDirectory() as directory, working_directory(directory), patch.dict(os.environ, ENV):
            items = self.descriptors(directory)
            items['counter']['image'] = 'ghcr.io/other/counter@' + DIGEST
            Path('all.json').write_text(json.dumps(items))
            with patch.object(images, 'run') as run, self.assertRaises(ValueError):
                images.release(Path(directory))
            run.assert_not_called()
            self.descriptors(directory)
            with patch.dict(os.environ, {'GITHUB_EVENT_NAME': 'pull_request'}), \
                    patch.object(images, 'run') as run, self.assertRaises(ValueError):
                images.release(Path(directory))
            run.assert_not_called()
