#!/usr/bin/env python3
"""Exercise concurrent creators and creator death using bundled production code.

Build first: docker build --platform linux/amd64 --target php -f
docker/mediawiki/Dockerfile -t cloudwiki-php:provision-acceptance .
Then: python3 docker/tests/provision-acceptance.py --image cloudwiki-php:provision-acceptance
"""
import argparse
from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts'))
from farm_secrets import create_secrets

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--image', required=True)
parser.add_argument('--report', type=Path, default=Path('/tmp/provision-production-acceptance.json'))
args = parser.parse_args()
project = 'cloudwiki-acceptance-' + uuid.uuid4().hex[:8]
prefix = '9provision' + uuid.uuid4().hex[:8]
report = {'project': project, 'status': 'running', 'cases': [], 'main_stack_modified': False}
creators = []
env = dict(os.environ)
temporary = tempfile.TemporaryDirectory(prefix='provision-acceptance-')
directory = Path(temporary.name)
base = ['docker', 'compose', '--env-file', str(directory / 'test.env'), '-p', project,
        '-f', 'compose.yaml', '-f', 'docker/tests/provision-acceptance.compose.yaml']


def command(argv, timeout=600, check=True):
    result = subprocess.run(argv, cwd=ROOT, env=env, capture_output=True, text=True, timeout=timeout)
    if check and result.returncode:
        raise RuntimeError(' '.join(argv[:8]) + '\n' + result.stdout + result.stderr)
    return result


def compose(*argv, **kwargs):
    return command(base + list(argv), **kwargs).stdout.strip()


def probe(action, wiki='', *extra):
    output = compose('exec', '-T', 'provisioner', 'php',
                     '/acceptance/provision-acceptance-probe.php', action, wiki, *extra)
    return json.loads(output.splitlines()[-1])


def wait_for(description, callback, timeout=120):
    until = time.monotonic() + timeout
    while time.monotonic() < until:
        result = callback()
        if result:
            return result
        time.sleep(.2)
    raise AssertionError('Timed out: ' + description)


def start_creator(wiki, private=False, pause=False):
    name = project + '-' + wiki
    creators.append(name)
    argv = ['run', '-d', '--no-deps', '--name', name, 'init', 'php', 'maintenance/run.php',
            '/acceptance/provision-acceptance.php', '--wiki=metawiki', '--target=' + wiki]
    if private:
        argv.append('--private')
    if pause:
        argv.append('--pause')
    compose(*argv)
    return name


def wait_creator(name):
    code = command(['docker', 'wait', name], timeout=180).stdout.strip()
    if code != '0':
        raise AssertionError(name + ' exited ' + code + '\n' + command(['docker', 'logs', name]).stdout)


def check_ready(wiki, private):
    wait_for('provisioning ' + wiki, lambda: probe('state', wiki)['status'].get('state') == 'complete')
    state = probe('state', wiki)
    report['current_fixture'] = {'wiki': wiki, 'state': state}
    assert not state['pending'] and not state['marker'] and not state['receipt'], state
    assert not state['lock_held'] and state['metadata_complete'], state
    assert state['private'] is private, state
    assert state['updater_invocations'] == 1, state
    lists = state['lists']
    assert isinstance(lists['databases'], dict) and isinstance(lists['active'], dict), state
    assert lists['deleted'] is None, state
    assert isinstance(lists['private' if private else 'public'], dict), state
    assert lists['public' if private else 'private'] is None, state
    readiness_started = time.monotonic()
    observed_statuses = []

    def http_ready():
        response = probe('http', wiki)
        observed_statuses.append(response['status'])
        # FPM may briefly retain the old PHP cache lists after CLI publishes them.
        if response['status'] in (404, 503):
            return False
        assert response['status'] == 200, response
        data = json.loads(response['body'])
        if private:
            assert data.get('error', {}).get('code') == 'readapidenied', data
        else:
            assert data.get('query', {}).get('general'), data
        return response

    response = wait_for('FPM cache visibility ' + wiki, http_ready, timeout=15)
    http_readiness_ms = round((time.monotonic() - readiness_started) * 1000)
    data = json.loads(response['body'])
    if private:
        assert data['error']['code'] == 'readapidenied', data
        file_response = probe('http', wiki, 'img_auth.php')
        assert file_response['status'] >= 400, file_response
        assert 'Private acceptance content' not in file_response['body'], file_response
    else:
        assert data['query']['general']['lang'] == 'en', data
    output = compose('run', '--rm', '-T', '--no-deps', '-e', 'MW_JOBCHRON_TEST_ISOLATED=1',
                     'init', 'php', 'maintenance/run.php', '/acceptance/provisioning.php',
                     '--wiki=' + wiki, '--action=inspect', '--checkuser')
    snapshot = json.loads(output.splitlines()[-1])
    assert snapshot['metadata_complete'] and snapshot['anonymous_read'] is not private, snapshot
    assert snapshot['language'] == ('de' if private else 'en'), snapshot
    return {'wiki': wiki, 'private': private, 'state': state,
            'http_statuses_after_provisioning': observed_statuses,
            'http_readiness_ms': http_readiness_ms,
            'api_check': 'anonymous read denied' if private else 'public siteinfo ready',
            'private_file_protected': private,
            'table_count': len(snapshot['tables']), 'schema_sha256': hashlib.sha256(
                json.dumps(snapshot['tables'], sort_keys=True).encode()).hexdigest(),
            'checkuser': snapshot['checkuser']}


try:
    (directory / 'test.env').write_text('')
    create_secrets(directory / 'secrets')
    image = json.loads(command(['docker', 'image', 'inspect', args.image]).stdout)[0]
    env.update(MW_IMAGE=image['Id'], MW_ENV='production', MW_DOMAIN='acceptance.example.test',
               MW_BETA_DOMAIN='beta.acceptance.example.test', MW_REALM='default',
               MW_LEGACY_BRANDING='0', MW_SSL_REQUESTS='0', MW_EMAIL_ENABLED='0',
               MW_NOTIFICATIONS='0', MW_ANALYTICS='0', MW_CACHE_PURGE='0', MW_CAPTCHA='0',
               MW_S3_ENABLED='0', MW_SEARCH='0', MW_REMOTE_REPOSITORIES='0',
               MW_UPLOAD_ROOT='/var/www/mediawiki/images', MW_DUMP_DIRECTORY='/var/www/dumps',
               FARM_SECRETS_DIRECTORY=str(directory / 'secrets'), ADMIN_USER='Admin')
    report['image'] = {'id': image['Id'], 'architecture': image['Architecture'],
                       'os': image['Os'], 'source_revision': image['Config']['Labels'].get(
                           'org.opencontainers.image.revision')}
    print('Initializing isolated production farm: ' + project, flush=True)
    compose('up', '-d', '--wait', 'database', 'redis', 'memcached')
    compose('run', '--rm', '-T', '--no-deps', 'init', 'php', '/opt/farm/manage.php', 'fresh-production')
    compose('up', '-d', '--wait', 'php', 'provisioner')
    containers = compose('ps', '-q', 'php', 'provisioner').splitlines()
    inspections = json.loads(command(['docker', 'inspect', *containers]).stdout)
    for item in inspections:
        assert item['Image'] == image['Id']
        assert 'MW_ENV=production' in item['Config']['Env']
        assert 'MW_CODE_SYNC=1' not in item['Config']['Env']
        assert not any(m['Type'] == 'bind' and
                       (m['Destination'].startswith('/opt/farm') or
                        m['Destination'].startswith('/var/www/mediawiki')) for m in item['Mounts'])
    report['immutable_code'] = 'Image pinned by ID; no application source mounts or sync controller'
    for name in ['provision-metadata.php', 'provision.php', 'runtime.php', 'process.php']:
        actual = compose('exec', '-T', 'provisioner', 'sha256sum', '/opt/farm/' + name).split()[0]
        expected = hashlib.sha256((ROOT / 'docker/mediawiki' / name).read_bytes()).hexdigest()
        assert actual == expected, (name, actual, expected)
        report.setdefault('source_sha256', {})[name] = actual
    for wiki in ['metawiki', 'loginwiki', 'testwiki']:
        compose('run', '--rm', '-T', '--no-deps', 'init', 'php', 'maintenance/run.php',
                '/acceptance/production.php', '--wiki=' + wiki)
    print('PASS immutable image, production initialization, domains and administrator checks', flush=True)

    all_wikis = []
    for wave, pause in [('simultaneous', False), ('metadata_barrier', True)]:
        fixtures = [(prefix + wave.replace('_', '') + str(i) + 'wiki', i % 2 == 1) for i in range(4)]
        with ThreadPoolExecutor(max_workers=4) as pool:
            names = list(pool.map(lambda pair: start_creator(*pair, pause=pause), fixtures))
        if pause:
            for wiki, private in fixtures:
                wait_for('paused creator ' + wiki, lambda wiki=wiki: probe('state', wiki)['paused'])
                state = probe('state', wiki)
                assert state['pending'] and state['lock_held'] and not state['receipt'], state
                assert 'urlshortener' in state['stored_extensions'], state
            with ThreadPoolExecutor(max_workers=4) as pool:
                list(pool.map(lambda pair: command(['docker', 'exec', pair[0], 'php',
                    '/acceptance/provision-acceptance-probe.php', 'continue', pair[1][0]]),
                    zip(names, fixtures)))
        with ThreadPoolExecutor(max_workers=4) as pool:
            list(pool.map(wait_creator, names))
        cases = [check_ready(wiki, private) for wiki, private in fixtures]
        assert all(c['state']['metadata_sources'] in (['creator'], ['repair']) for c in cases), cases
        report['cases'].append({'case': wave, 'status': 'passed', 'wikis': cases})
        all_wikis.extend(fixtures)
        print('PASS ' + wave + ': four concurrent creators, public/private metadata, schemas and access', flush=True)

    wiki = prefix + 'killedprivatewiki'
    name = start_creator(wiki, private=True, pause=True)
    wait_for('creator stopped during preparation', lambda: probe('state', wiki)['paused'])
    wait_for('consumer received the durable event', lambda: probe('state', wiki)['marker'])
    before = probe('state', wiki)
    assert before['pending'] and before['lock_held'] and not before['receipt'], before
    assert before['updater_invocations'] == 0, before
    assert 'urlshortener' in before['stored_extensions'], before
    response = probe('http', wiki)
    assert response['status'] == 503, response
    command(['docker', 'kill', '--signal', 'KILL', name])
    assert command(['docker', 'wait', name]).stdout.strip() == '137'
    recovered = check_ready(wiki, True)
    assert recovered['state']['metadata_sources'] == ['repair'], recovered
    report['cases'].append({'case': 'creator_sigkill_during_metadata', 'status': 'passed',
                            'before_kill': before, 'http_before_kill': 503, 'creator_exit': 137,
                            'recovered': recovered})
    all_wikis.append((wiki, True))
    print('PASS creator SIGKILL: lock released, durable event repaired, one updater, private API/files denied', flush=True)

    # Recheck every shared list after all writers and deferred updates have exited.
    for wiki, private in all_wikis:
        state = probe('state', wiki)
        assert isinstance(state['lists']['databases'], dict), state
        assert isinstance(state['lists']['private' if private else 'public'], dict), state
        assert state['lists']['public' if private else 'private'] is None, state
    log = compose('logs', '--no-color', 'provisioner')
    attempts = {wiki: sum(line.endswith('Dispatch provision: ' + wiki) for line in log.splitlines())
                for wiki, _ in all_wikis}
    assert all(count == 1 for count in attempts.values()), attempts
    report['dispatch_attempts'] = attempts
    report['queue'] = probe('queue')
    assert report['queue'] == {'pending_wikis': 0, 'pending_deliveries': 0}, report['queue']
    report.pop('current_fixture', None)
    report['status'] = 'passed'
except BaseException as error:
    report['status'] = 'failed'
    report['error'] = str(error)
    diagnostics = compose('logs', '--no-color', '--tail=40', 'php', 'provisioner', check=False)
    (args.report.parent / (args.report.stem + '.diagnostics.log')).write_text(diagnostics)
    raise
finally:
    for name in creators:
        command(['docker', 'rm', '-f', name], check=False)
    cleanup = command(base + ['down', '-v', '--remove-orphans'], check=False)
    report['cleanup_succeeded'] = cleanup.returncode == 0
    args.report.write_text(json.dumps(report, indent=2) + '\n')
    temporary.cleanup()
