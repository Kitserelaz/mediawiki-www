import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock

spec = importlib.util.spec_from_file_location('dashboard', Path(__file__).parents[1] / 'update-diff-dashboard.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class DashboardTests(unittest.TestCase):
    def test_only_allowlisted_public_source_fields_are_published(self):
        result = m.comparison({'total_commits': 1, 'secret': 'hidden', 'commits': [
            {'sha': 'a' * 40, 'author': {'email': 'private'}, 'commit': {
                'message': '<script>alert(1)</script>', 'committer': {'date': '2026-10-23', 'email': 'private'}}}],
            'files': [{'filename': 'a.php', 'patch': '+code', 'contents': 'hidden', 'status': 'modified',
                       'additions': 1, 'deletions': 0}]})
        self.assertNotIn('hidden', json.dumps(result))
        self.assertNotIn('private', json.dumps(result))
        self.assertEqual(result['files'][0]['patch'], '+code')

    def test_config_drift_exposes_names_but_no_values_or_hashes(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            (root / 'accepted-local-config.json').write_text(json.dumps({'files': {str(root / '.env.production'): 'secret-hash'}}))
            guard = Mock(CONFIG_BASELINE='accepted-local-config.json')
            guard.file_fingerprint.return_value = 'new-secret-hash'
            result = m.config_report(guard, root, root)
            self.assertEqual(result, {'status': 'changed', 'files': [{'filename': '.env.production', 'status': 'modified'}]})
            guard.capture_local_config.assert_not_called()
            self.assertNotIn('hash', json.dumps(result))

    def test_inventory_detects_added_credentials(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            (root / 'accepted-local-config.json').write_text('{"files":{}}')
            guard = Mock(CONFIG_BASELINE='accepted-local-config.json')
            guard.capture_local_config.return_value = {str(root / 'secrets/new-key'): 'privatehash'}
            self.assertEqual(m.config_report(guard, root, root)['files'], [{'filename': 'secrets/new-key', 'status': 'added'}])

    def test_missing_baseline_is_unknown(self):
        with tempfile.TemporaryDirectory() as d:
            self.assertEqual(m.config_report(Mock(CONFIG_BASELINE='absent'), Path(d), Path(d))['status'], 'unknown')

    def test_failed_refresh_preserves_only_matching_cached_release(self):
        cached = {'key': ['abc', None], 'fetched_at': 1, 'pending': {'total_commits': 3}}
        fail = Mock(side_effect=OSError('network'))
        self.assertEqual(m.repository_report('abc', None, cached, 1000, fail)['pending']['total_commits'], 3)
        self.assertNotIn('pending', m.repository_report('different', None, cached, 1000, fail))

    def test_fresh_cache_avoids_requests(self):
        cached = {'key': ['abc', None], 'fetched_at': 1000}
        fetch = Mock()
        self.assertEqual(m.repository_report('abc', None, cached, 1100, fetch), cached)
        fetch.assert_not_called()

    def test_live_edits_report_only_names_including_untracked_and_rename(self):
        import subprocess
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            subprocess.run(['git', 'init', '-q', d], check=True)
            (root / 'mediawiki').mkdir()
            file = root / 'mediawiki/file.php'
            file.write_text('initial')
            subprocess.run(['git', '-C', d, 'add', '.'], check=True)
            subprocess.run(['git', '-C', d, '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-qm', 'Initial'], check=True)
            subprocess.run(['git', '-C', d, 'mv', 'mediawiki/file.php', 'mediawiki/renamed.php'], check=True)
            (root / 'mediawiki/new.php').write_text('private-uncommitted-value')
            result = m.working_tree_report(root)
            self.assertEqual(result['status'], 'changed')
            self.assertEqual(len(result['files']), 2)
            self.assertNotIn('private-uncommitted-value', json.dumps(result))
            self.assertIn({'filename': 'mediawiki/renamed.php', 'status': 'renamed'}, result['files'])

    def test_initial_release_does_not_invent_previous_comparison(self):
        fetch = Mock(side_effect=[{'total_commits': 2, 'commits': [], 'files': []}, {'workflow_runs': []}])
        result = m.repository_report('abc', None, {}, 1000, fetch)
        self.assertNotIn('released', result)
        self.assertEqual(fetch.call_count, 2)


if __name__ == '__main__':
    unittest.main()
