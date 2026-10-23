import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('reload_live', Path(__file__).parents[1] / 'reload-live-code.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class ReloadRequestTests(unittest.TestCase):
    def test_symlink_requests_never_touch_their_target(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            target = root / 'private'
            target.write_text('must not change')
            request = root / 'reload'
            request.symlink_to(target)
            self.assertFalse(m.consume_request(request))
            self.assertFalse(request.exists())
            self.assertEqual(target.read_text(), 'must not change')

    def test_request_contents_are_ignored(self):
        with tempfile.TemporaryDirectory() as d:
            request = Path(d) / 'reload'
            request.write_text('run arbitrary command')
            self.assertTrue(m.consume_request(request))
            self.assertFalse(m.consume_request(request))
