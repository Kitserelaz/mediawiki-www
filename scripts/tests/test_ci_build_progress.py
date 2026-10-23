"""The activity wrapper must preserve build output and failures without reporting secrets."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).parents[1] / 'ci-build-progress.py'
spec = importlib.util.spec_from_file_location('activity', SCRIPT)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class ActivityTests(unittest.TestCase):
    def test_wrapper_preserves_exit_status_and_output_when_reporting_disabled(self):
        env = dict(os.environ, GITHUB_EVENT_NAME='pull_request')
        result = subprocess.run([sys.executable, str(SCRIPT), '--job', 'test', '--phase', 'test', '--',
                                 sys.executable, '-c', 'print("build output");raise SystemExit(7)'],
                                env=env, capture_output=True, text=True)
        self.assertEqual(result.returncode, 7)
        self.assertIn('build output', result.stdout)

    def test_heartbeat_payload_contains_timing_but_no_logs_or_token(self):
        env = {'CLOUDWIKI_PROGRESS_TOKEN': 'private-token', 'GITHUB_EVENT_NAME': 'push',
               'GITHUB_REF': 'refs/heads/main', 'GITHUB_SHA': 'a' * 40, 'GITHUB_REPOSITORY': 'owner/repo',
               'GITHUB_RUN_ID': '42', 'GITHUB_RUN_ATTEMPT': '2'}
        with patch.dict(os.environ, env), patch.object(m.urllib.request, 'urlopen') as network:
            network.return_value.__enter__.return_value.read.return_value = b'{"id":123}'
            activity = m.Activity('integration', 'Runtime images')
            activity.data['last_output_at'] = 123
            activity.publish()
            payload = json.loads(network.call_args.args[0].data)
            self.assertNotIn('private-token', json.dumps(payload))
            summary = json.loads(payload['output']['summary'])
            self.assertEqual(summary['last_output_at'], 123)
            self.assertEqual(summary['run_attempt'], 2)
            self.assertIn('heartbeat_at', summary)
            activity.publish('completed', 7)
            self.assertEqual(json.loads(network.call_args.args[0].data)['conclusion'], 'failure')
