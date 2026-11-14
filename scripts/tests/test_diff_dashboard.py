import importlib.util
import json
from pathlib import Path
import subprocess
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

    def test_local_diffs_publish_tracked_edits_and_skip_production_only_contents(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            def git(*args):
                subprocess.run(['git', '-C', d, *args], check=True)

            git('init', '-q')
            (root / 'mediawiki').mkdir()
            settings = root / 'mediawiki/LocalSettings.php'
            settings.write_text('<?php\n$option = false;\n')
            (root / 'mediawiki/deleted.php').write_text('removed source\n')
            (root / 'mediawiki/renamed.php').write_text('rename source\n')
            (root / 'mediawiki/PrivateSettings.php').write_text('placeholder\n')
            (root / 'mediawiki/link').symlink_to('old-private-target')
            git('add', '.')
            git('-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-qm', 'Initial')

            settings.write_text('<?php\n$option = true;\n')
            git('add', 'mediawiki/LocalSettings.php')
            with settings.open('a') as file:
                file.write('$other = true;\n')
            (root / 'mediawiki/deleted.php').unlink()
            git('mv', 'mediawiki/renamed.php', 'mediawiki/moved.php')
            (root / 'mediawiki/PrivateSettings.php').write_text('private-settings-value\n')
            (root / 'mediawiki/link').unlink()
            (root / 'mediawiki/link').symlink_to('new-private-target')
            (root / 'mediawiki/untracked.php').write_text('private-uncommitted-value\n')
            production_env = root / '.env.production'
            production_env.write_text('private-env-value\n')
            guard = Mock(CONFIG_BASELINE='accepted-local-config.json')
            (root / guard.CONFIG_BASELINE).write_text(json.dumps({'files': {
                str(settings): 'accepted-settings', str(production_env): 'accepted-env',
            }}))
            guard.file_fingerprint.return_value = 'changed'

            def patch(filename, previous=None):
                return m.local_patch(root, filename, previous)

            working_tree = m.working_tree_report(root, patch)
            config = m.config_report(guard, root, root, patch)
            self.assertEqual(working_tree['status'], 'changed')
            files = {item['filename']: item for item in working_tree['files']}
            live_patch = files['mediawiki/LocalSettings.php']
            self.assertEqual(live_patch['additions'], 2)
            self.assertEqual(live_patch['deletions'], 1)
            self.assertIn('-$option = false;\n+$option = true;\n+$other = true;', live_patch['patch'])
            self.assertIn('-removed source', files['mediawiki/deleted.php']['patch'])
            self.assertEqual(files['mediawiki/moved.php']['previous_filename'], 'mediawiki/renamed.php')
            self.assertEqual(files['mediawiki/moved.php']['status'], 'renamed')
            self.assertIn(live_patch, config['files'])
            self.assertIn({'filename': '.env.production', 'status': 'modified'}, config['files'])
            for report in (working_tree, config):
                self.assertNotRegex(json.dumps(report), 'private-(uncommitted-value|settings-value|env-value|target)')

    def test_initial_release_does_not_invent_previous_comparison(self):
        fetch = Mock(side_effect=[{'total_commits': 2, 'commits': [], 'files': []}, {'workflow_runs': []}])
        result = m.repository_report('abc', None, {}, 1000, fetch)
        self.assertNotIn('released', result)
        self.assertEqual(fetch.call_count, 1)

    def test_live_fast_forward_keeps_last_successful_release_as_comparison_base(self):
        from unittest.mock import patch

        recorded = {'commit': 'last-success', 'previous_commit': 'older'}
        with patch.object(m.subprocess, 'check_output', return_value='live-head\n'), \
                patch.object(m.subprocess, 'run') as git:
            git.return_value.returncode = 0
            self.assertEqual(m.live_release(Path('/var/www'), recorded),
                             {'commit': 'live-head', 'previous_commit': 'last-success'})
            git.return_value.returncode = 1
            self.assertEqual(m.live_release(Path('/var/www'), recorded),
                             {'commit': 'live-head', 'previous_commit': None})

    def test_pipeline_tracks_exact_main_commit_and_keeps_only_public_fields(self):
        run = {'id': 42, 'head_sha': 'new', 'status': 'in_progress', 'run_attempt': 2}
        fetch = Mock(side_effect=[{'object': {'sha': 'new'}}, {'workflow_runs': [run]},
            {'jobs': [{'id': 7, 'name': 'integration', 'status': 'in_progress', 'runner_name': 'private',
                       'steps': [{'name': 'Build images', 'status': 'in_progress', 'secret': 'private'}]},
                      {'id': 8, 'name': 'CloudWiki build: integration / Runtime images', 'status': 'in_progress'}]},
            {'check_runs': [{'name': 'CloudWiki build: integration / Runtime images', 'output': {'summary': json.dumps({
                'run_id': '42', 'run_attempt': 2, 'job': 'integration', 'phase': 'Runtime images',
                'heartbeat_at': 995, 'last_output_at': 990, 'token': 'private'})}}]}])
        result = m.pipeline_report({'history': {'checked_at': 999}}, 1000, fetch)
        self.assertEqual(result['head_sha'], 'new')
        self.assertEqual(len(result['jobs']), 1)
        self.assertIn('head_sha=new', fetch.call_args_list[1].args[0])
        self.assertEqual(result['activity'][0]['last_output_at'], 990)
        self.assertNotIn('private', json.dumps(result))
        self.assertEqual(result['checked_at'], 1000)

    def test_wrong_commit_run_is_not_presented_as_current(self):
        fetch = Mock(side_effect=[{'object': {'sha': 'new'}}, {'workflow_runs': [{'id': 1, 'head_sha': 'old'}]}])
        result = m.pipeline_report({}, 1000, fetch)
        self.assertEqual(result['status'], 'waiting_for_run')
        self.assertNotIn('id', result)

    def test_heartbeat_from_other_run_or_retry_is_ignored(self):
        checks = [{'name': 'CloudWiki build: test', 'output': {'summary': json.dumps(data)}} for data in [
            {'run_id': '42', 'run_attempt': 1}, {'run_id': '43', 'run_attempt': 2}, {'run_id': '42', 'run_attempt': 2}]]
        self.assertEqual(len(m.build_activity(checks, {'id': 42, 'run_attempt': 2})), 1)

    def test_pipeline_refresh_is_independent_and_failed_poll_never_advances_freshness(self):
        old = {'id': 42, 'checked_at': 900, 'status': 'in_progress'}
        fail = Mock(side_effect=OSError('private network detail'))
        result = m.pipeline_report(old, 1000, fail)
        self.assertTrue(result['stale'])
        self.assertEqual(result['checked_at'], 900)
        self.assertNotIn('private', json.dumps(result))
        fetch = Mock()
        self.assertEqual(m.pipeline_report(old, 950, fetch), old)
        fetch.assert_not_called()

    def test_successful_history_uses_matching_job_and_step_durations(self):
        past = {'id': 10}
        job = {'name': 'architectures (linux/arm64)', 'conclusion': 'success',
               'started_at': '2026-10-23T00:00:00Z', 'completed_at': '2026-10-23T00:10:00Z',
               'steps': [{'name': 'Build PHP image', 'conclusion': 'success',
                          'started_at': '2026-10-23T00:00:00Z', 'completed_at': '2026-10-23T00:05:00Z'}]}
        fetch = Mock(side_effect=[{'object': {'sha': 'new'}}, {'workflow_runs': [{'id': 42, 'head_sha': 'new'}]},
                                 {'jobs': []}, {'check_runs': []}, {'workflow_runs': [past]}, {'jobs': [job]}])
        result = m.pipeline_report({}, 10000, fetch)
        self.assertEqual(result['history']['durations'][job['name']]['median'], 600)
        self.assertEqual(result['history']['durations'][job['name'] + ' / Build PHP image']['median'], 300)

    def test_deployment_report_never_publishes_unapproved_metadata(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            (root / 'deployment-progress.json').write_text(json.dumps({'commit': 'abc', 'stage': 'backup',
                'heartbeat_at': 900, 'registry_token': 'private', 'command_output': 'private'}))
            result = m.deployment_report(root)
            self.assertEqual(result['stage'], 'backup')
            self.assertNotIn('private', json.dumps(result))


if __name__ == '__main__':
    unittest.main()
