import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

UPGRADER = Path(__file__).resolve().parents[1] / 'upgrade-component.py'


class UpgradeComponentTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.repo = self.root / 'upstream'
        self.repo.mkdir()
        self.git('init', '-q')
        (self.repo / 'extension.json').write_text('{"name":"Example"}\n')
        self.base_content = ''.join(f'line {n}\n' for n in range(20))
        (self.repo / 'Example.php').write_text(self.base_content)
        self.commit()
        self.baseline = self.git('rev-parse', 'HEAD').strip()
        self.component = self.root / 'mediawiki/extensions/Example'
        shutil.copytree(self.repo, self.component, ignore=shutil.ignore_patterns('.git'))

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.repo), *args], text=True)

    def commit(self):
        self.git('add', '.')
        self.git('-c', 'user.name=Upgrade test', '-c', 'user.email=upgrade-test@example.invalid', 'commit', '-qm', 'test fixture')

    def run_upgrade(self, *extra):
        return subprocess.run([sys.executable, str(UPGRADER), str(self.component), str(self.repo),
                               '--baseline', self.baseline, '--workspace', str(self.root),
                               '--report', str(self.root / 'report.json'), *extra], capture_output=True, text=True)

    def test_preserves_added_translation_and_new_upstream_messages(self):
        for folder in [self.component, self.repo]:
            (folder / 'i18n').mkdir()
        (self.component / 'i18n/new.json').write_text('{"message":"Local $1"}')
        (self.repo / 'i18n/new.json').write_text('{"message":"Upstream $1","added":"New message"}')
        self.commit()
        result = self.run_upgrade('--keep-local-translations')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads((self.component / 'i18n/new.json').read_text()),
                         {'message': 'Local $1', 'added': 'New message'})

    def test_added_translation_placeholder_conflict_preserves_original_tree(self):
        for folder in [self.component, self.repo]:
            (folder / 'i18n').mkdir()
        original = '{"message":"Local $1"}'
        (self.component / 'i18n/new.json').write_text(original)
        (self.repo / 'i18n/new.json').write_text('{"message":"Changed $2"}')
        self.commit()
        result = self.run_upgrade('--keep-local-translations')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((self.component / 'i18n/new.json').read_text(), original)
        self.assertFalse((self.root / 'report.json').exists())

    def test_local_translation_may_omit_new_optional_message_parameters(self):
        for folder in [self.component, self.repo]:
            (folder / 'i18n').mkdir()
        (self.component / 'i18n/new.json').write_text('{"message":"Local error"}')
        (self.repo / 'i18n/new.json').write_text('{"message":"Invalid $1; allowed $2"}')
        self.commit()
        result = self.run_upgrade('--keep-local-translations')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads((self.component / 'i18n/new.json').read_text()), {'message':'Local error'})

    def test_keeps_modified_translation_removed_upstream_in_nested_module(self):
        folder = self.repo / 'Provider/i18n'
        folder.mkdir(parents=True)
        (folder / 'old.json').write_text('{"message":"Original"}')
        self.commit()
        self.baseline = self.git('rev-parse', 'HEAD').strip()
        local = self.component / 'Provider/i18n/old.json'
        local.parent.mkdir(parents=True)
        local.write_text('{"message":"Local wording"}')
        (folder / 'old.json').unlink()
        self.commit()
        result = self.run_upgrade('--keep-local-translations')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(local.read_text()), {'message': 'Local wording'})

    def test_keeps_local_edits_and_additions_alongside_upstream_changes(self):
        (self.component / 'Example.php').write_text(self.base_content.replace('line 19', 'local final line'))
        (self.component / 'Local.php').write_text('local addition\n')
        (self.repo / 'Example.php').write_text(self.base_content.replace('line 0\n', 'upstream first line\n'))
        self.commit()
        result = self.run_upgrade()
        self.assertEqual(result.returncode, 0, result.stderr)
        content = (self.component / 'Example.php').read_text()
        self.assertIn('upstream first line', content)
        self.assertIn('local final line', content)
        self.assertEqual((self.component / 'Local.php').read_text(), 'local addition\n')
        self.assertEqual(len(json.loads((self.root / 'report.json').read_text())['preserved_changes']), 2)

    def test_conflicting_source_edit_leaves_component_unchanged(self):
        local = self.base_content.replace('line 0\n', 'local first line\n')
        (self.component / 'Example.php').write_text(local)
        (self.repo / 'Example.php').write_text(self.base_content.replace('line 0\n', 'upstream first line\n'))
        self.commit()
        result = self.run_upgrade()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((self.component / 'Example.php').read_text(), local)
        self.assertFalse((self.root / 'report.json').exists())

    def test_refuses_paths_outside_the_component_roots(self):
        self.component = self.repo
        result = self.run_upgrade()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((self.repo / 'Example.php').read_text(), self.base_content)


if __name__ == '__main__':
    unittest.main()
