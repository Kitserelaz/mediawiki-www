#!/usr/bin/env python3
"""Isolated real-Caddy cutover rehearsal using CI's already-built farm images.

No production configuration, credentials, database, or public certificates.
Checks HTTP continuity, in-flight requests, custom/numeric/auth routes, forwarded
identity, failed reloads and switching back, using the production config builders.
"""
import concurrent.futures
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import threading
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('deployment', ROOT / 'scripts/deploy-production.py')
deployment = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deployment)
bg = deployment.blue_green()


def main():
    project = 'cloudwiki-online-test-' + uuid.uuid4().hex[:10]
    runner = deployment.Runner(ROOT)
    images = {'php': os.getenv('MW_IMAGE', 'cloudwiki-php:local'),
              'web': os.getenv('WEB_IMAGE', 'cloudwiki-web:local')}
    docker = lambda *args: runner.run(['docker', *args], capture=True)
    network = project + '_default'
    with tempfile.TemporaryDirectory(prefix='cloudwiki-online-test-') as temporary:
        directory = Path(temporary).resolve()
        gateway = directory / 'gateway'
        config_dir = directory / 'config'
        config_dir.mkdir()
        (config_dir / 'Caddyfile').write_text('''{
    admin off
    on_demand_tls {
        ask http://127.0.0.1:8081/tls-allow
    }
}
import /etc/caddy/wiki.caddy
import /etc/caddy/custom-domains.caddy
https://meta.example.test, https://login.example.test, https://test.example.test, https://auth.example.test {
    tls internal
    import wiki
}
''')
        (config_dir / 'wiki.caddy').write_text((ROOT / 'docker/caddy/wiki.caddy').read_text())
        custom = (ROOT / 'docker/caddy/custom-domains.caddy').read_text()
        custom = custom.replace('tls {\n        ca {$MW_CUSTOM_DOMAINS_ACME_CA:https://acme-v02.api.letsencrypt.org/directory}', 'tls internal {')
        (config_dir / 'custom-domains.caddy').write_text(custom)
        base = {'name': project, 'services': {
            'php': {'image': images['php'], 'entrypoint': ['php-fpm'], 'command': ['-F'],
                    'volumes': [], 'environment': {},
                    'healthcheck': {'test': ['CMD', 'php', '-r',
                                            '$s=@fsockopen("127.0.0.1",9000);exit($s?0:1);'],
                                    'interval': '1s', 'timeout': '3s', 'retries': 30}},
            'web': {'image': images['web'], 'volumes': [
                bg.bind(config_dir / name, '/etc/caddy/' + name)
                for name in ('Caddyfile', 'wiki.caddy', 'custom-domains.caddy')],
                    'environment': {'MW_DOMAIN': 'example.test', 'MW_BETA_DOMAIN': 'beta.example.test',
                                    'MW_NUMERIC_WIKI_IDS': '1'},
                    'networks': {'default': {}}}},
            'volumes': {}, 'networks': {'default': {'external': True, 'name': network}}}
        try:
            docker('network', 'create', network)
            for slot in bg.SLOTS:
                fixtures = directory / ('fixture-' + slot)
                (fixtures / 'resources').mkdir(parents=True)
                (fixtures / 'resources/proof.js').write_text(slot)
                php = '''<?php
if (($_GET['title'] ?? '') === 'Slow') {
    file_put_contents('/tmp/cloudwiki-slow-started', 'started');
    $deadline = microtime(true) + 12;
    while (!file_exists('/tmp/cloudwiki-slow-release') && microtime(true) < $deadline) { usleep(100000); }
}
if (str_ends_with($_SERVER['SCRIPT_FILENAME'], '/load.php')) { echo 'startup JavaScript'; exit; }
header('Content-Type: application/json');
echo json_encode(['query' => ['general' => ['generator' => 'MediaWiki fixture']],
    'slot' => 'SLOT', 'ip' => $_SERVER['REMOTE_ADDR'],
    'wiki' => $_SERVER['CLOUDWIKI_CUSTOM_WIKI'] ?? '',
    'host' => $_SERVER['CLOUDWIKI_CUSTOM_HOST'] ?? '',
    'https' => $_SERVER['HTTPS'] ?? '', 'uri' => $_SERVER['REQUEST_URI']]);
'''.replace('SLOT', slot)
                for name in ('index.php', 'api.php', 'load.php', 'rest.php', 'img_auth.php',
                             'thumb.php', 'thumb_handler.php', 'opensearch_desc.php'):
                    (fixtures / name).write_text(php)
                lookup = fixtures / 'lookup.php'
                lookup.write_text('''<?php
$uri = $_SERVER['REQUEST_URI'];
if (str_starts_with($uri, '/tls-allow')) { http_response_code(200); exit; }
if (str_starts_with($uri, '/route-numeric')) {
    header('Location: https://meta.example.test/wiki/Main_Page', true, 308); exit;
}
if (($_SERVER['CLOUDWIKI_LOOKUP_HOST'] ?? '') === 'custom.example.test') {
    header('X-CloudWiki-Route-Wiki: fixturewiki');
    header('X-CloudWiki-Route-Host: custom.example.test'); exit;
}
http_response_code(403);
''')
                slot_base = copy.deepcopy(base)
                slot_base['services']['php']['volumes'] = [bg.bind(fixtures, '/var/www/mediawiki'),
                                                           bg.bind(lookup, '/opt/farm/custom-domain-lookup.php')]
                slot_base['services']['web']['volumes'].append(bg.bind(fixtures, '/var/www/mediawiki'))
                deployment.prepare_slot(ROOT, directory, runner, slot_base, slot, images, None)
                deployment.probe_slot(runner, directory, slot, 'example.test')
            deployment.write_caddy_files(gateway, bg.gateway_files(base, 'blue'))
            edge = copy.deepcopy(base)
            edge['services'].pop('php')
            edge['services']['web'].update(bg.gateway_override(gateway)['services']['web'])
            # Preserve the original config mounts alongside the generated directory.
            edge['services']['web']['volumes'] = base['services']['web']['volumes'] + [bg.bind(gateway, bg.MOUNT)]
            edge['services']['web']['ports'] = [{'target': 443, 'host_ip': '127.0.0.1'}]
            edge_file = directory / 'edge.json'
            edge_file.write_text(json.dumps(edge))
            compose = ['docker', 'compose', '-f', str(edge_file)]
            runner.run(compose + ['up', '-d', '--no-deps', '--no-build', '--pull', 'never', 'web'])
            identity = runner.run(compose + ['ps', '-q', 'web'], capture=True)
            port = json.loads(docker('inspect', identity))[0]['NetworkSettings']['Ports']['443/tcp'][0]['HostPort']

            def fetch(path='/api.php', host='meta.example.test', extra=()):
                return runner.run(['curl', '--fail', '--silent', '--show-error', '--insecure',
                                   '--noproxy', '*', '--max-time', '15', '--resolve',
                                   f'{host}:{port}:127.0.0.1', *extra,
                                   f'https://{host}:{port}{path}'], capture=True)

            def reload_to(slot):
                deployment.write_caddy_files(gateway, bg.gateway_files(base, slot))
                runner.run(compose + ['exec', '-T', 'web', 'caddy', 'reload', '--address', bg.ADMIN,
                                      '--config', bg.MOUNT + '/Caddyfile', '--adapter', 'caddyfile'])

            for _ in range(60):
                try:
                    assert json.loads(fetch())['slot'] == 'blue'
                    break
                except (subprocess.CalledProcessError, AssertionError):
                    time.sleep(0.25)
            else:
                raise AssertionError('Gateway did not become ready')
            seen = []
            stop = threading.Event()
            def traffic():
                while not stop.is_set():
                    seen.append(json.loads(fetch())['slot'])
                    time.sleep(0.02)

            with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                ongoing = pool.submit(traffic)
                slow = pool.submit(fetch, '/wiki/Slow')
                try:
                    for _ in range(30):
                        started = subprocess.run(deployment.slot_command(directory, 'blue') +
                                                 ['exec', '-T', 'bg-blue-php', 'test', '-f', '/tmp/cloudwiki-slow-started'],
                                                 capture_output=True)
                        if started.returncode == 0:
                            break
                        time.sleep(0.1)
                    else:
                        raise AssertionError('Long-running request did not reach the previous slot')
                    reload_to('green')
                    assert json.loads(fetch())['slot'] == 'green'
                    try:
                        deployment.prepare_slot(ROOT, directory, runner, base, 'blue', images, None)
                    except RuntimeError as error:
                        assert 'still draining' in str(error), error
                    else:
                        raise AssertionError('A slot with an in-flight request was replaced')
                    runner.run(deployment.slot_command(directory, 'blue') +
                               ['exec', '-T', 'bg-blue-php', 'touch', '/tmp/cloudwiki-slow-release'])
                    assert json.loads(slow.result(timeout=10))['slot'] == 'blue'
                    assert fetch('/resources/proof.js') == 'green'
                    spoof = ('-H', 'X-CloudWiki-Client-IP: 203.0.113.123',
                             '-H', 'X-CloudWiki-Route-Wiki: attacker',
                             '-H', 'X-CloudWiki-Route-Host: attacker.example.test')
                    public = json.loads(fetch(extra=spoof))
                    assert public['ip'] != '203.0.113.123' and public['wiki'] == ''
                    assert public['https'] == 'on'
                    custom = json.loads(fetch(host='custom.example.test', extra=spoof))
                    assert custom['wiki'] == 'fixturewiki' and custom['host'] == 'custom.example.test'
                    auth = json.loads(fetch('/metawiki/w/api.php', 'auth.example.test'))
                    assert auth['slot'] == 'green' and auth['uri'] == '/metawiki/w/api.php'
                    numeric = fetch('/wiki/Main_Page', '123.example.test', ('-o', '/dev/null', '-w', '%{http_code}'))
                    assert numeric == '308', numeric
                    # Invalid configuration must leave the working config serving.
                    (gateway / 'wiki.caddy').write_text('not_a_caddy_directive\n')
                    invalid = subprocess.run(compose + ['exec', '-T', 'web', 'caddy', 'reload', '--address', bg.ADMIN,
                                                       '--config', bg.MOUNT + '/Caddyfile', '--adapter', 'caddyfile'], capture_output=True)
                    assert invalid.returncode != 0
                    assert json.loads(fetch())['slot'] == 'green'
                    reload_to('blue')
                    assert json.loads(fetch())['slot'] == 'blue'
                    time.sleep(0.25)
                finally:
                    stop.set()
                    ongoing.result(timeout=15)
            assert {'blue', 'green'} <= set(seen), seen
            assert runner.run(compose + ['ps', '-q', 'web'], capture=True) == identity
            print(f'PASS: {len(seen)} continuous requests, in-flight drain, asset cutover, custom/auth/numeric routes, '
                  'identity forwarding, failed reload and rollback; gateway container unchanged.')
        finally:
            ids = docker('ps', '-aq', '--filter', 'label=com.docker.compose.project=' + project).split()
            if ids:
                docker('rm', '-f', '-v', *ids)
            subprocess.run(['docker', 'network', 'rm', network], capture_output=True)


if __name__ == '__main__':
    main()
