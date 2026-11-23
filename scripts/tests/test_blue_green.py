"""Blue/green routing, classification and fault injection without a live farm."""
import copy
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

import test_deploy_production as legacy

FakeRunner, deployment, request = legacy.FakeRunner, legacy.deployment, legacy.request

ROOT = Path(__file__).resolve().parents[2]
bg = deployment.blue_green()


def fixture(root):
    (root / 'farm.json').write_text('{}')
    caddy = root / 'docker/caddy'
    caddy.mkdir(parents=True)
    for name in ('Caddyfile.production', 'wiki.caddy', 'custom-domains.caddy'):
        (caddy / name).write_text((ROOT / 'docker/caddy' / name).read_text())
    return {
        'name': 'fixture',
        'services': {
            'php': {'image': 'previous-php', 'user': 'www-data',
                    'environment': {'MW_DOMAIN': 'example.test', 'MW_ENV': 'production'},
                    'volumes': [{'type': 'volume', 'source': 'uploads', 'target': '/var/www/mediawiki/images'}],
                    'secrets': [{'source': 'farm', 'target': 'farm.json'}],
                    'depends_on': {'database': {'condition': 'service_healthy'}},
                    'healthcheck': {'test': ['CMD', 'php', '/opt/farm/health.php']}},
            'web': {'image': 'previous-web', 'ports': [{'target': 443, 'published': '443'}],
                    'environment': {'MW_DOMAIN': 'example.test'},
                    'volumes': [bg.bind(caddy / 'Caddyfile.production', '/etc/caddy/Caddyfile'),
                                bg.bind(caddy / 'wiki.caddy', '/etc/caddy/wiki.caddy'),
                                bg.bind(caddy / 'custom-domains.caddy', '/etc/caddy/custom-domains.caddy'),
                                {'type': 'volume', 'source': 'caddy-data', 'target': '/data'},
                                {'type': 'volume', 'source': 'uploads', 'target': '/var/www/mediawiki/images', 'read_only': True}]}
        },
        'volumes': {'uploads': {'name': 'fixture_uploads'}, 'caddy-data': {'name': 'fixture_caddy-data'}},
        'networks': {'default': {'name': 'fixture_default'}},
        'secrets': {'farm': {'file': str(root / 'farm.json')}}
    }


class OnlineRunner(FakeRunner):
    def __init__(self, root, configuration, fail=None):
        super().__init__(root, fail)
        self.configuration = configuration
        self.current = 'c' * 40
        self.changed = 'mediawiki/extensions/SkyWikiMagic/resources/createNewWiki.js'
        self.connections = None

    def run(self, args, **kwargs):
        result = super().run(args, **kwargs)
        if args[:3] == ['git', 'diff', '--raw']:
            return ':100644 100644 ' + '1' * 40 + ' ' + '2' * 40 + ' M\0' + self.changed + '\0'
        if args[:3] == ['git', 'rev-parse', 'HEAD']:
            return self.current
        if args[:2] == ['git', 'checkout']:
            self.current = args[-1]
        if args[:2] == ['docker', 'ps']:
            return '' if self.connections is None else '0123456789ab'
        if 'netstat -tn' in ' '.join(args):
            return str(self.connections)
        if 'curl' in args:
            return ('startup JavaScript' if 'load.php?' in args[-1] else
                    json.dumps({'query': {'general': {'generator': 'MediaWiki 1.46'}}}))
        return result


class ConfigurationTest(unittest.TestCase):
    def test_only_public_asset_paths_qualify(self):
        for path in ('mediawiki/resources/src/module.js',
                     'mediawiki/extensions/SkyWikiMagic/resources/createNewWiki.js',
                     'mediawiki/skins/Citizen/resources/skin.less'):
            self.assertTrue(bg.online_safe([path]), path)
        for path in ('mediawiki/includes/Output.php', 'mediawiki/resources/run.php',
                     'mediawiki/extensions/Foo/extension.json', 'mediawiki/extensions/Foo/sql/schema.sql',
                     'mediawiki/extensions/Foo/resources/composer.json', 'mediawiki/composer.lock',
                     'docker/mediawiki/Dockerfile', 'compose.yaml', 'unknown.js',
                     'mediawiki/resources/.hidden.js', 'mediawiki/resources/../includes/file.js'):
            self.assertFalse(bg.online_safe([path]), path)
        self.assertFalse(bg.online_safe([]))

    def test_slots_share_data_but_never_publish_ports_or_mount_certificates(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            base = fixture(root)
            original = copy.deepcopy(base)
            config = bg.slot_config(base, 'green', request()['images'], root / 'slot')
            self.assertEqual(base, original)
            self.assertEqual(set(config['services']), {'bg-green-web', 'bg-green-php'})
            self.assertTrue(all(value['external'] for value in config['volumes'].values()))
            self.assertEqual(config['networks']['default'], {'external': True, 'name': 'fixture_default'})
            php = config['services']['bg-green-php']
            web = config['services']['bg-green-web']
            self.assertNotIn('depends_on', php)
            self.assertNotIn('ports', web)
            self.assertFalse(any(v['target'] in ('/data', '/config') for v in web['volumes']))
            self.assertEqual(php['secrets'], base['services']['php']['secrets'])
            self.assertEqual(php['labels'][bg.LABEL], 'true')

    def test_versioned_source_keeps_writable_uploads_under_readonly_code(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            config = bg.slot_config(fixture(root), 'blue', request()['images'], root / 'slot', root / 'release')
            volumes = {v['target']: v for v in config['services']['bg-blue-php']['volumes']}
            self.assertTrue(volumes['/var/www/mediawiki']['read_only'])
            self.assertEqual(volumes['/var/www/mediawiki/images']['type'], 'volume')
            self.assertNotIn('read_only', volumes['/var/www/mediawiki/images'])

    def test_gateway_validation_does_not_claim_the_live_edges_fixed_tunnel_ip(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            base = fixture(root)
            base['services']['web']['networks'] = {'default': {}, 'tunnel': {'ipv4_address': '172.31.240.2'}}
            base['networks']['tunnel'] = {'name': 'fixture_tunnel'}
            config = bg.gateway_validation_config(base, root / 'gateway')
            web = config['services']['gateway-validation']
            self.assertEqual(web['networks'], {'default': {}})
            self.assertNotIn('ports', web)
            self.assertEqual(web['entrypoint'], ['caddy'])
            self.assertEqual(web['command'][0], 'validate')
            self.assertTrue(any(v['target'] == '/data' for v in web['volumes']))
            self.assertTrue(all(v['external'] for v in config['volumes'].values()))

    def test_gateway_preserves_operator_wrappers_and_prevents_header_spoofing(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            base = fixture(root)
            wrapper = root / 'operator.caddy'
            wrapper.write_text('import /etc/caddy/Caddyfile.production\nimport /etc/caddy/wildcard.caddy')
            wildcard = root / 'wildcard.caddy'
            wildcard.write_text('https://*.{$MW_DOMAIN} {\n tls /run/cloudwiki-tls/fullchain.pem /run/cloudwiki-tls/privkey.pem\n import wiki\n}\n')
            base['services']['web']['volumes'][0]['target'] = '/etc/caddy/Caddyfile.production'
            base['services']['web']['volumes'] += [bg.bind(wrapper, '/etc/caddy/Caddyfile'), bg.bind(wildcard, '/etc/caddy/wildcard.caddy')]
            files = bg.gateway_files(base, 'green')
            self.assertIn('import /etc/caddy/blue-green/Caddyfile.production', files['Caddyfile'])
            self.assertIn('import /etc/caddy/blue-green/wildcard.caddy', files['Caddyfile'])
            self.assertIn('admin ' + bg.ADMIN, files['Caddyfile.production'])
            self.assertIn('tls /run/cloudwiki-tls/fullchain.pem', files['wildcard.caddy'])
            self.assertIn('bg-green-php:9001', files['custom-domains.caddy'])
            self.assertIn('header_up X-CloudWiki-Route-Wiki {http.vars.cloudwiki_custom_wiki}', files['wiki.caddy'])
            self.assertIn('header_up X-CloudWiki-Client-IP {client_ip}', files['wiki.caddy'])
            self.assertIn('reverse_proxy bg-green-web:8080', files['wiki.caddy'])

    def test_raw_diff_rejects_deleted_assets_and_symlinks(self):
        class Runner:
            def run(self, *_args, **_kwargs):
                return self.raw
        runner = Runner()
        for old_mode, new_mode, status in [('100644', '000000', 'D'), ('100644', '120000', 'T'),
                                          ('120000', '100644', 'T')]:
            runner.raw = f':{old_mode} {new_mode} a b {status}\0mediawiki/resources/file.js\0'
            self.assertFalse(deployment.online_changes(runner, 'old', 'new'))
        runner.raw = ':100644 100644 a b M\0mediawiki/resources/file.js\0'
        self.assertTrue(deployment.online_changes(runner, 'old', 'new'))

    def test_slot_compose_file_is_valid_with_external_farm_resources(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            config = bg.slot_config(fixture(root), 'blue', request()['images'], root / 'backend')
            path = root / 'slot.json'
            path.write_text(json.dumps(config))
            subprocess.run(['docker', 'compose', '-f', str(path), 'config', '--quiet'], check=True)

    def test_coordinated_snapshot_stops_slots_before_backup_and_can_keep_them_stopped(self):
        for keep_stopped in (False, True):
            with self.subTest(keep_stopped=keep_stopped), tempfile.TemporaryDirectory() as tmp:
                root = Path(tmp)
                (root / 'scripts').mkdir()
                (root / 'bin').mkdir()
                (root / 'secrets').mkdir()
                for name in ('farm.json', 'oauth-private.key', 'oauth-public.key'):
                    (root / 'secrets' / name).write_text('fixture')
                (root / 'scripts/snapshot').write_text((ROOT / 'scripts/snapshot').read_text())
                (root / 'scripts/compose').write_text('''import json, os, pathlib, sys
root = pathlib.Path(__file__).resolve().parent.parent
args = sys.argv[1:]
with open(os.environ['TEST_LOG'], 'a') as stream: stream.write(json.dumps(['compose', *args]) + '\\n')
if args[:1] == ['config']:
    print(json.dumps({'name': 'snapshot-fixture', 'secrets': {'farm': {'file': str(root / 'secrets/farm.json')}}}))
''')
                docker = root / 'bin/docker'
                docker.write_text('''#!/usr/bin/env python3
import json, os, sys
with open(os.environ['TEST_LOG'], 'a') as stream: stream.write(json.dumps(['docker', *sys.argv[1:]]) + '\\n')
if sys.argv[1] == 'ps': print('0123456789ab')
''')
                docker.chmod(0o755)
                log = root / 'calls.jsonl'
                environment = dict(os.environ, PATH=str(root / 'bin') + os.pathsep + os.environ['PATH'], TEST_LOG=str(log))
                subprocess.run(['sh', str(root / 'scripts/snapshot'),
                                *(['--keep-stopped'] if keep_stopped else []), 'fixture'],
                               env=environment, check=True, capture_output=True)
                calls = [json.loads(line) for line in log.read_text().splitlines()]
                freeze = next(i for i, call in enumerate(calls) if call[:2] == ['docker', 'stop'])
                backup = next(i for i, call in enumerate(calls) if 'backup' in call)
                self.assertLess(freeze, backup)
                starts = [i for i, call in enumerate(calls) if call[:2] == ['docker', 'start']]
                if keep_stopped:
                    self.assertEqual(starts, [])
                    self.assertFalse(any('up' in call for call in calls))
                else:
                    edge = next(i for i, call in enumerate(calls) if 'up' in call)
                    self.assertLess(starts[0], edge)


class OnlineDeploymentTest(unittest.TestCase):
    accept = legacy.DeploymentTest.accept
    deploy = legacy.DeploymentTest.deploy

    def setUp(self):
        legacy.DeploymentTest.setUp(self)
        self.directory = self.state / 'blue-green'
        self.directory.mkdir()
        self.configuration = fixture(self.root)
        self.runner = OnlineRunner(self.root, self.configuration)
        self.environment += 'MW_ZERO_DOWNTIME=1\nMW_BLUE_GREEN_DIRECTORY=' + str(self.directory) + '\n'
        (self.root / '.env.production').write_text(self.environment)
        deployment.write_caddy_files(self.directory / 'gateway', bg.gateway_files(self.configuration, 'blue'))
        (self.directory / 'compose.json').write_text(json.dumps(bg.gateway_override(self.directory / 'gateway')))
        (self.directory / 'active.json').write_text(json.dumps({'slot': 'blue', 'commit': 'c' * 40, 'schema_commit': 'c' * 40}))
        self.accept(self.runner)

    def test_online_cutover_does_not_stop_edge_data_services_or_workers(self):
        self.assertTrue(self.deploy(self.runner))
        calls = self.runner.calls
        self.assertFalse(any(call[0] in ('scripts/snapshot', 'scripts/farm') or 'stop' in call or 'restart' in call for call in calls))
        ups = [call for call in calls if 'up' in call]
        self.assertEqual(len(ups), 1)
        self.assertIn(str(self.directory / 'green.json'), ups[0])
        reloads = [call for call in calls if 'reload' in call]
        self.assertEqual(len(reloads), 1)
        checks = [i for i, call in enumerate(calls) if 'curl' in call]
        switch = next(i for i, call in enumerate(calls) if call[:2] == ['git', 'checkout'])
        self.assertLess(max(checks), switch)
        record = json.loads((self.state / 'last-success.json').read_text())
        self.assertEqual(record['deployment_mode'], 'online')
        self.assertIsNone(record['backup'])
        self.assertEqual(json.loads((self.directory / 'active.json').read_text())['slot'], 'green')
        self.assertFalse((self.directory / 'pending.json').exists())

    def test_candidate_failure_never_changes_active_route_or_checkout(self):
        self.runner.fail = lambda args: 'curl' in args
        with self.assertRaises(subprocess.CalledProcessError):
            self.deploy(self.runner)
        self.assertFalse(any('reload' in call or call[:2] == ['git', 'checkout'] for call in self.runner.calls))
        self.assertEqual((self.root / '.env.production').read_text(), self.environment)
        self.assertEqual(json.loads((self.directory / 'active.json').read_text())['slot'], 'blue')

    def test_failed_public_health_restores_previous_route_and_environment(self):
        checks = []
        def health(*_args):
            checks.append(True)
            if len(checks) == 1:
                raise RuntimeError('new origin failed')
        with self.assertRaisesRegex(RuntimeError, 'new origin failed'):
            self.deploy(self.runner, health)
        self.assertEqual(len(checks), 2)
        self.assertEqual(len([call for call in self.runner.calls if 'reload' in call]), 2)
        self.assertEqual(self.runner.current, 'c' * 40)
        self.assertEqual((self.root / '.env.production').read_text(), self.environment)
        self.assertEqual(json.loads((self.directory / 'active.json').read_text())['slot'], 'blue')
        self.assertFalse((self.directory / 'pending.json').exists())
        self.assertEqual(json.loads((self.state / 'deployment-progress.json').read_text())['status'], 'rolled_back')

    def test_still_draining_slot_is_not_replaced(self):
        self.runner.connections = 1
        with self.assertRaisesRegex(RuntimeError, 'still draining'):
            self.deploy(self.runner)
        self.assertFalse(any('up' in call or 'reload' in call for call in self.runner.calls))

    def test_online_mode_cannot_bypass_migration_classification(self):
        self.runner.changed = 'mediawiki/extensions/Foo/extension.json'
        payload = {**request(), 'mode': 'online'}
        with self.assertRaisesRegex(RuntimeError, 'approved public-asset'):
            deployment.deploy(payload, self.root, self.state, self.runner, lambda *_: None)
        self.assertFalse(any('up' in call or call[0] == 'scripts/snapshot' for call in self.runner.calls))

    def test_unknown_backend_changes_use_existing_maintenance_path(self):
        self.runner.changed = 'mediawiki/includes/Unknown.php'
        with patch.object(deployment, 'enable_online') as enable:
            self.assertTrue(self.deploy(self.runner))
        self.assertTrue(any(call[0] == 'scripts/snapshot' for call in self.runner.calls))
        self.assertTrue(any(call[0] == 'scripts/farm' and call[-1] == 'update' for call in self.runner.calls))
        enable.assert_called_once()

    def test_interrupted_release_blocks_further_deployments(self):
        (self.directory / 'pending.json').write_text('{}')
        with self.assertRaisesRegex(RuntimeError, '--recover-online'):
            self.deploy(self.runner)
        self.assertFalse(any(call[:2] == ['docker', 'pull'] or 'up' in call for call in self.runner.calls))

    def test_mode_maintenance_keeps_the_backup_and_updater_even_for_assets(self):
        with patch.object(deployment, 'enable_online'):
            self.assertTrue(deployment.deploy({**request(), 'mode': 'maintenance'},
                                             self.root, self.state, self.runner, lambda *_: None))
        self.assertTrue(any(call[0] == 'scripts/snapshot' for call in self.runner.calls))
        self.assertTrue(any(call[0] == 'scripts/farm' and call[-1] == 'update' for call in self.runner.calls))

    def test_online_requires_initial_gateway_conversion(self):
        deployment.disable_online({'MW_BLUE_GREEN_DIRECTORY': str(self.directory)})
        with self.assertRaisesRegex(RuntimeError, 'initialized gateway'):
            deployment.deploy({**request(), 'mode': 'online'}, self.root, self.state,
                              self.runner, lambda *_: None)
        self.assertFalse(any(call[:2] == ['docker', 'pull'] or 'up' in call for call in self.runner.calls))

    def test_recovery_failure_retains_transaction_and_both_slots_for_operator(self):
        self.runner.fail = lambda args: 'reload' in args
        with self.assertRaises(subprocess.CalledProcessError):
            self.deploy(self.runner)
        self.assertTrue((self.directory / 'pending.json').exists())
        self.assertFalse(any('stop' in call for call in self.runner.calls))
        self.runner.fail = None
        deployment.recover_online(self.root, self.state, self.runner,
                                  {'MW_BLUE_GREEN_DIRECTORY': str(self.directory)}, lambda *_: None)
        self.assertFalse((self.directory / 'pending.json').exists())
        self.assertEqual((self.root / '.env.production').read_text(), self.environment)
        self.assertEqual(self.runner.current, 'c' * 40)

    def test_ready_gateway_cannot_hide_a_source_revision_mismatch(self):
        (self.directory / 'active.json').write_text(json.dumps({'slot': 'blue', 'commit': 'd' * 40}))
        with self.assertRaisesRegex(RuntimeError, 'do not match'):
            self.deploy(self.runner)
        self.assertFalse(any(call[:2] == ['docker', 'pull'] for call in self.runner.calls))

    def test_image_mode_rejects_a_live_source_override_before_stopping_anything(self):
        self.runner.configuration['services']['php']['volumes'].append(bg.bind(self.root / 'mediawiki', '/var/www/mediawiki'))
        self.accept(self.runner)
        with self.assertRaisesRegex(RuntimeError, 'bind-mounted application source'):
            self.deploy(self.runner)
        self.assertFalse(any('stop' in call or call[0] == 'scripts/snapshot' for call in self.runner.calls))

    def test_initial_conversion_marks_gateway_ready_only_after_origin_health(self):
        settings = {'MW_BLUE_GREEN_DIRECTORY': str(self.directory), 'MW_DOMAIN': 'example.test'}
        deployment.disable_online(settings)
        checked = []
        def health(*_args):
            self.assertTrue((self.directory / 'compose.json').exists())
            self.assertFalse((self.directory / 'active.json').exists())
            checked.append(True)
        deployment.enable_online(self.root, self.state, self.runner, settings,
                                 request()['images'], 'c' * 40, health=health)
        self.assertEqual(checked, [True])
        self.assertEqual(json.loads((self.directory / 'active.json').read_text())['commit'], 'c' * 40)
        edge_starts = [call for call in self.runner.calls if 'up' in call and call[-1] == 'web']
        self.assertEqual(len(edge_starts), 1)

    def test_initial_config_validation_fails_before_gateway_recreation(self):
        settings = {'MW_BLUE_GREEN_DIRECTORY': str(self.directory)}
        deployment.disable_online(settings)
        self.runner.fail = lambda args: 'run' in args and 'gateway-validation' in args
        with self.assertRaises(subprocess.CalledProcessError):
            deployment.enable_online(self.root, self.state, self.runner, settings,
                                     request()['images'], 'c' * 40, health=lambda *_: None)
        self.assertFalse((self.directory / 'compose.json').exists())
        self.assertFalse(any('up' in call and call[-1] == 'web' for call in self.runner.calls))

    def test_initial_origin_failure_restores_legacy_gateway_configuration(self):
        settings = {'MW_BLUE_GREEN_DIRECTORY': str(self.directory)}
        deployment.disable_online(settings)
        def unhealthy(*_args):
            raise RuntimeError('origin unavailable')
        with patch.object(deployment.time, 'sleep'), self.assertRaisesRegex(RuntimeError, 'origin unavailable'):
            deployment.enable_online(self.root, self.state, self.runner, settings,
                                     request()['images'], 'c' * 40, health=unhealthy)
        self.assertFalse((self.directory / 'compose.json').exists())
        self.assertFalse((self.directory / 'active.json').exists())
        self.assertEqual(len([call for call in self.runner.calls if 'up' in call and call[-1] == 'web']), 2)


if __name__ == '__main__':
    unittest.main()
