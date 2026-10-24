#!/usr/bin/env python3
"""Source publication excludes runtime data and mirrors edits and deletions."""
import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('source', Path(__file__).parents[1] / 'mediawiki/provisioner-source.py')
source = importlib.util.module_from_spec(spec)
spec.loader.exec_module(source)


class MirrorTests(unittest.TestCase):
    def test_edits_deletions_and_runtime_data(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            incoming, live = root / 'incoming', root / 'live'
            incoming.mkdir()
            live.mkdir()
            (incoming / 'LocalSettings.php').write_text('new config')
            (incoming / 'PrivateSettings.php').write_text('never copy credentials')
            (incoming / '.env').write_text('never copy credentials')
            (incoming / 'new.php').write_text('new source')
            (live / 'old.php').write_text('removed source')
            (live / 'PrivateSettings.php').write_text('runtime loader')
            (live / 'cw_cache').mkdir()
            (live / 'cw_cache/metadata.json').write_text('live data')
            source.mirror(incoming, live, 'mediawiki')
            self.assertEqual((live / 'LocalSettings.php').read_text(), 'new config')
            self.assertFalse((live / 'old.php').exists())
            self.assertFalse((live / '.env').exists())
            self.assertEqual((live / 'PrivateSettings.php').read_text(), 'runtime loader')
            self.assertEqual((live / 'cw_cache/metadata.json').read_text(), 'live data')
            (incoming / 'new.php').write_text('modified source')
            source.mirror(incoming, live, 'mediawiki')
            self.assertEqual((live / 'new.php').read_text(), 'modified source')
            (incoming / 'new.php').unlink()
            source.mirror(incoming, live, 'mediawiki')
            self.assertFalse((live / 'new.php').exists())

    def test_file_directory_transitions(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            incoming, live = root / 'incoming', root / 'live'
            incoming.mkdir()
            live.mkdir()
            (incoming / 'extension').mkdir()
            (incoming / 'extension/file.php').write_text('new')
            (live / 'extension').write_text('old file')
            source.mirror(incoming, live, 'mediawiki')
            self.assertEqual((live / 'extension/file.php').read_text(), 'new')
            (incoming / 'extension/file.php').unlink()
            (incoming / 'extension').rmdir()
            (incoming / 'extension').write_text('now a file')
            source.mirror(incoming, live, 'mediawiki')
            self.assertEqual((live / 'extension').read_text(), 'now a file')


if __name__ == '__main__':
    unittest.main()
