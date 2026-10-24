"""Dummy browser credentials are opt-in and never replace existing credentials."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).parents[2]
spec = importlib.util.spec_from_file_location('farm_secrets', ROOT / 'scripts/farm_secrets.py')
secrets = importlib.util.module_from_spec(spec)
spec.loader.exec_module(secrets)


class BrowserSecretsTests(unittest.TestCase):
    def prepare(self, directory):
        path = Path(directory)
        # Secret generation itself is already covered by OpenSSL; avoid generating
        # RSA keys when checking the isolated fixture configuration.
        (path / 'oauth-private.key').write_text('fixture private key')
        (path / 'oauth-public.key').write_text('fixture public key')
        return path

    def test_default_setup_does_not_enable_discord(self):
        with tempfile.TemporaryDirectory() as directory:
            path = self.prepare(directory)
            secrets.create_secrets(path)
            data = json.loads((path / 'farm.json').read_text())
            self.assertNotIn('discord_client_id', data)
            self.assertNotIn('discord_client_secret', data)

    def test_explicit_fixture_setup_is_enabled_and_idempotent(self):
        with tempfile.TemporaryDirectory() as directory:
            path = self.prepare(directory)
            secrets.create_secrets(path, discord_browser_fixtures=True)
            original = (path / 'farm.json').read_bytes()
            data = json.loads(original)
            for key, value in secrets.DISCORD_BROWSER_FIXTURES.items():
                self.assertEqual(data[key], value)
            secrets.create_secrets(path, discord_browser_fixtures=True)
            self.assertEqual((path / 'farm.json').read_bytes(), original)

    def test_existing_credentials_are_never_replaced_to_enable_fixtures(self):
        for configured in [{}, {'discord_client_id': 'existing', 'discord_client_secret': 'existing'}]:
            with self.subTest(configured=bool(configured)), tempfile.TemporaryDirectory() as directory:
                path = self.prepare(directory)
                file = path / 'farm.json'
                file.write_text(json.dumps({'db_password': 'preserve-existing', **configured}))
                original = file.read_bytes()
                with self.assertRaisesRegex(ValueError, 'existing credentials were preserved'):
                    secrets.create_secrets(path, discord_browser_fixtures=True)
                self.assertEqual(file.read_bytes(), original)

    def test_setup_rejects_fixture_mode_outside_ci_before_writing_anything(self):
        env = dict(os.environ, CI='false')
        result = subprocess.run(['python3', str(ROOT / 'scripts/setup-dev.py'), '--discord-browser-fixtures'],
                                env=env, capture_output=True, text=True)
        self.assertEqual(result.returncode, 2)
        self.assertIn('only allowed in explicit CI development setup', result.stderr)
