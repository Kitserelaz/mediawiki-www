"""Native maintenance must not race the Caddy admin socket."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('native_control', ROOT / 'deploy/native/control.py')
native = importlib.util.module_from_spec(spec)
spec.loader.exec_module(native)


class MaintenanceTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.state = self.root / 'state'
        self.state.mkdir()
        self.run_dir = self.root / 'run'
        self.calls = []
        for name, value in [('STATE', self.state), ('RUN', self.run_dir)]:
            patcher = patch.object(native, name, value)
            patcher.start()
            self.addCleanup(patcher.stop)
        patcher = patch.object(native, 'run', side_effect=lambda args, **kw: self.calls.append(list(args)))
        patcher.start()
        self.addCleanup(patcher.stop)

    def reloads(self):
        return [call for call in self.calls if call[:3] == ['systemctl', 'reload', 'cloudwiki-caddy.service']]

    def test_maintenance_starts_caddy_and_reloads_after_the_socket_exists(self):
        with patch.object(native, 'wait_ready', return_value=True) as ready:
            native.maintenance(True)
        ready.assert_called_with(native.CADDY_ADMIN, 30)
        self.assertEqual((self.state / 'native-maintenance.caddy').read_text(),
                         'respond "CloudWiki is undergoing maintenance. Please retry shortly." 503\n')
        self.assertEqual(self.calls, [['systemctl', 'start', 'cloudwiki-caddy.service'],
                                      ['systemctl', 'reload', 'cloudwiki-caddy.service']])

    def test_maintenance_clears_the_snippet(self):
        with patch.object(native, 'wait_ready', return_value=True):
            native.maintenance(False)
        self.assertEqual((self.state / 'native-maintenance.caddy').read_text(), '# online\n')

    def test_reload_retries_after_a_transient_failure(self):
        attempts = []
        def run(args, **kw):
            if args[:2] == ['systemctl', 'reload']:
                attempts.append(args)
                if len(attempts) < 3: raise RuntimeError('systemctl failed (exit 1)')
            return b''
        with patch.object(native, 'run', side_effect=run), \
             patch.object(native, 'wait_ready', return_value=True), \
             patch('time.sleep'):
            native.reload_caddy()
        self.assertEqual(len(attempts), 3)

    def test_reload_reports_a_persistent_failure(self):
        with patch.object(native, 'run', side_effect=RuntimeError('systemctl failed (exit 1)')) as run, \
             patch.object(native, 'wait_ready', return_value=True), \
             patch('time.sleep'):
            with self.assertRaisesRegex(RuntimeError, 'systemctl failed'):
                native.reload_caddy()
        self.assertEqual(run.call_count, 5)

    def test_missing_socket_is_reported_before_reload(self):
        with patch.object(native, 'wait_ready', return_value=False), \
             patch.object(native, 'run') as run:
            with self.assertRaisesRegex(RuntimeError, 'socket did not become ready'):
                native.reload_caddy()
        run.assert_not_called()

    def test_wait_ready_handles_an_existing_and_a_missing_path(self):
        socket = self.root / 'admin.sock'
        self.assertFalse(native.wait_ready(socket, 0.1))
        socket.touch()
        self.assertTrue(native.wait_ready(socket, 0.1))


if __name__ == '__main__':
    unittest.main()
