"""Exercise Compose merging and source release export without starting services."""
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]


def load(name, filename):
    loader = importlib.machinery.SourceFileLoader(name, str(ROOT / 'scripts' / filename))
    spec = importlib.util.spec_from_loader(name, loader)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


selector = load('compose_selector', 'compose')
releases = load('source_release', 'source-release.py')


def source_fixture(directory):
    for name in ['mediawiki/index.php', 'mediawiki/LocalSettings.php', 'docker/mediawiki/private.php',
                 'docker/mediawiki/entrypoint.sh']:
        file = directory / name
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text('<?php // fixture\n')
    for name in ['mediawiki/resources', 'mediawiki/extensions', 'mediawiki/skins']:
        (directory / name).mkdir(parents=True, exist_ok=True)
        (directory / name / 'fixture.js').write_text('// fixture\n')


class SourceModeTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name).resolve()
        self.source = self.path / 'source with spaces'
        self.source.mkdir()
        source_fixture(self.source)
        releases.prepare_mountpoints(self.source)
        self.environment = self.path / 'farm.env'

    def configuration(self, mode, source=None, production=False):
        self.environment.write_text('MW_CODE_MODE=' + mode + '\nMW_ENV=' + ('production' if production else 'development') +
                                    '\nMW_DOMAIN=example.test\nMW_PUBLIC_HOSTS=meta.example.test\nACME_EMAIL=admin@example.test\n' +
                                    ('COMPOSE_FILE=compose.yaml:compose.production.yaml\n' if production else 'COMPOSE_FILE=compose.yaml\n') +
                                    'MW_SOURCE_DIRECTORY=' + json.dumps(str(source or self.source)) + '\n')
        with patch.dict(os.environ, {}, clear=True):
            # Preserve executable lookup while excluding the caller's Compose settings.
            os.environ['PATH'] = os.defpath + ':/opt/homebrew/bin:/usr/local/bin'
            args, env = selector.command(['--env-file', str(self.environment), '--profile', 'tools', 'config', '--format', 'json'])
            return json.loads(subprocess.check_output(args, cwd=ROOT, env=env, text=True))

    def test_image_mode_has_no_host_php_mounts(self):
        config = self.configuration('image')
        for service in ['php', 'worker', 'provisioner', 'scheduler', 'init']:
            self.assertFalse(any(v['target'] == '/var/www/mediawiki' for v in config['services'][service]['volumes']))

    def test_bind_mode_covers_all_php_services_and_preserves_data_volumes(self):
        config = self.configuration('bind')
        for service in ['php', 'worker', 'provisioner', 'scheduler', 'init']:
            volumes = {v['target']: v for v in config['services'][service]['volumes']}
            self.assertTrue(volumes['/var/www/mediawiki']['read_only'])
            self.assertEqual(Path(volumes['/var/www/mediawiki']['source']), self.source / 'mediawiki')
            self.assertTrue(volumes['/opt/farm']['read_only'])
            for target in ['/var/www/mediawiki/images', '/var/www/mediawiki/cache', '/var/www/mediawiki/cw_cache']:
                self.assertEqual(volumes[target]['type'], 'volume')
                self.assertFalse(volumes[target].get('read_only', False))
        self.assertEqual(config['services']['jobchron']['volumes'][0]['target'], '/opt/farm')
        web = {v['target']: v for v in config['services']['web']['volumes']}
        for tree in ['resources', 'skins', 'extensions']:
            mount = web['/var/www/mediawiki/' + tree]
            self.assertTrue(mount['read_only'])
            self.assertEqual(Path(mount['source']), self.source / 'mediawiki' / tree)

    def test_unknown_mode_is_rejected(self):
        with self.assertRaises(ValueError):
            self.configuration('oops')

    def test_production_refuses_live_working_tree(self):
        with self.assertRaisesRegex(ValueError, 'versioned release'):
            self.configuration('bind', production=True)

    def test_production_keeps_public_configuration_and_requires_release_marker(self):
        source = self.path / ('a' * 40)
        self.source.rename(source)
        (source / 'release.json').write_text(json.dumps({'commit': 'a' * 40}))
        config = self.configuration('bind', source, production=True)
        self.assertIn('0.0.0.0', [p.get('host_ip', '0.0.0.0') for p in config['services']['web']['ports']])
        self.assertEqual(config['services']['php']['environment']['MW_ENV'], 'production')

    def test_export_uses_tracked_commit_and_does_not_ship_untracked_secrets(self):
        repository = self.path / 'repo'
        repository.mkdir()
        source_fixture(repository)
        (repository / 'mediawiki/resources/public.js').write_text('original')
        def git(*args):
            return subprocess.check_output(['git', *args], cwd=repository, text=True).strip()
        git('init', '-q')
        git('add', '.')
        git('-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-qm', 'Fixture')
        commit = git('rev-parse', 'HEAD')
        (repository / 'mediawiki/resources/public.js').write_text('uncommitted')
        (repository / 'mediawiki/private-secret.txt').write_text('must not ship')
        output = self.path / 'releases' / commit
        releases.export_release(commit, output, repository)
        self.assertEqual((output / 'mediawiki/resources/public.js').read_text(), 'original')
        self.assertFalse((output / 'mediawiki/private-secret.txt').exists())
        self.assertEqual(json.loads((output / 'release.json').read_text())['commit'], commit)
        self.assertTrue((output / 'mediawiki/docker/mediawiki').is_dir())
        releases.export_release(commit, output, repository)


if __name__ == '__main__':
    unittest.main()
