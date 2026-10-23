import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('wildcard_tls', Path(__file__).parents[1] / 'wildcard-tls.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class WildcardTLSChecks(unittest.TestCase):
    def test_proxy_install_preserves_unaccepted_live_settings(self):
        local, env, new = '/var/www/mediawiki/LocalSettings.php', '/var/www/.env.production', '/var/lib/cloudwiki/wildcard/Caddyfile'
        accepted = {local: 'accepted-settings', env: 'old-env'}
        before = {local: 'user-live-settings', env: 'old-env'}
        after = {local: 'newer-live-settings', env: 'wildcard-env', new: 'wildcard-route'}
        result = m.approved_proxy_baseline(accepted, before, after, {env, new})
        self.assertEqual(result[local], 'accepted-settings')
        self.assertEqual(result[env], 'wildcard-env')
        self.assertEqual(result[new], 'wildcard-route')

    def test_proxy_install_refuses_unrelated_secret_changes(self):
        path = '/var/www/docker/secrets-production/farm.json'
        with self.assertRaisesRegex(RuntimeError, 'Unrelated configuration changed'):
            m.approved_proxy_baseline({path: 'old'}, {path: 'old'}, {path: 'new'}, set())

    def test_missing_new_proxy_file_is_never_accepted(self):
        with self.assertRaisesRegex(RuntimeError, 'Required proxy configuration is missing'):
            m.approved_proxy_baseline({}, {}, {'/new': None}, {'/new'})

    def test_existing_unaccepted_secret_is_not_silently_accepted(self):
        path, env = '/private/credential', '/var/www/.env.production'
        result = m.approved_proxy_baseline({path: 'accepted', env: 'old'}, {path: 'changed', env: 'old'},
                                          {path: 'changed', env: 'new'}, {env})
        self.assertEqual(result[path], 'accepted')

    def test_dns_credential_is_in_inventory_only_for_the_tools_service(self):
        import json
        import os
        import subprocess
        from unittest.mock import patch
        root = Path(__file__).parents[2]
        with patch.dict(os.environ, {'PATH': os.defpath + ':/opt/homebrew/bin:/usr/local/bin'}, clear=True):
            data = json.loads(subprocess.check_output(['docker', 'compose', '-f', str(root / 'compose.yaml'),
                '-f', str(root / 'docker/wildcard/compose.yaml'), '--profile', '*', 'config',
                '--no-env-resolution', '--format', 'json'], text=True))
        path = '/var/lib/cloudwiki/wildcard/credentials/cloudflare.ini'
        self.assertTrue(any(v.get('source') == path for v in data['services']['tls-renewal']['volumes']))
        self.assertIn('tls-tools', data['services']['tls-renewal']['profiles'])
        for name, service in data['services'].items():
            if name != 'tls-renewal':
                self.assertFalse(any(v.get('source') == path for v in service.get('volumes', [])))

    def test_compose_overlay_is_added_once_without_changing_other_settings(self):
        source = 'COMPOSE_FILE=compose.yaml:compose.production.yaml\nMW_CODE_MODE=bind\n'
        overlay = Path('/var/lib/cloudwiki/wildcard/compose.yaml')
        once = m.compose_environment(source, overlay)
        self.assertEqual(m.compose_environment(once, overlay), once)
        self.assertIn('MW_CODE_MODE=bind\n', once)
        self.assertEqual(once.count(str(overlay)), 1)


if __name__ == '__main__':
    unittest.main()
