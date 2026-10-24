#!/usr/bin/env python3
"""Explain and regress OAuth's two-pass versus one-pass update-log difference."""
import json
import os
from pathlib import Path
import subprocess
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]
project = 'cloudwiki-provision-test-oauth-' + uuid.uuid4().hex[:8]
prefix = '9provision' + uuid.uuid4().hex[:6]
env = dict(os.environ, MW_ENV='development', MW_PROVISION_SINGLE_PASS='0', MW_S3_ENABLED='0')
base = ['docker', 'compose', '-p', project, '-f', 'compose.yaml',
        '-f', 'docker/tests/provisioning.compose.yaml']
report = {'project': project, 'status': 'running', 'cases': [],
          'single_pass_pipeline': 'production coordinator', 'main_stack_modified': False}


def compose(*args, check=True):
    result = subprocess.run(base + list(args), cwd=ROOT, env=env, text=True,
                            capture_output=True, errors='replace', timeout=240)
    if check and result.returncode:
        raise RuntimeError(result.stdout + result.stderr)
    return result


def probe(wiki, bootstrap=False, exercise=False):
    args = ['run', '--rm', '-T', '--no-deps', '-e',
            'MW_BOOTSTRAP_DB=' + (wiki if bootstrap else ''), 'init', 'php',
            'maintenance/run.php', '/opt/oauth-provisioning-test.php', '--wiki=' + wiki]
    if exercise:
        args.append('--exercise')
    return json.loads(compose(*args).stdout.splitlines()[-1])


try:
    config = json.loads(compose('--profile', 'tools', 'config', '--format', 'json').stdout)
    image = config['services']['init']['image']
    identity = subprocess.run(['docker', 'image', 'inspect', image, '--format', '{{.Id}}'],
                              check=True, capture_output=True, text=True).stdout.strip()
    env['MW_IMAGE'] = identity
    report['image_id'] = identity
    print('Initializing ' + project, flush=True)
    compose('up', '-d', '--wait', 'database', 'redis', 'memcached')
    compose('run', '--rm', '-T', '--no-deps', 'init', 'php', '/opt/farm/manage.php', 'fresh')
    central = probe('metawiki')['databases']['metawiki']
    report['central_baseline'] = central
    assert len(central['timestamp_patch_markers']) == 2
    snapshots = {}
    for mode in ['two', 'single']:
        compose('stop', 'provisioner')
        env['MW_PROVISION_SINGLE_PASS'] = '1' if mode == 'single' else '0'
        wiki = prefix + mode + 'oauthwiki'
        compose('run', '--rm', '-T', '--no-deps', 'init', 'php', 'maintenance/run.php',
                '/opt/provisioning-test.php', '--wiki=metawiki', '--action=create',
                '--target=' + wiki, '--variant=public')
        compose('run', '--rm', '-T', '--no-deps', '--user', 'www-data', 'init', 'php',
                'maintenance/run.php', '/opt/farm/prepare-wiki.php', '--wiki=metawiki', '--target=' + wiki)
        before = probe(wiki, bootstrap=True)
        assert before['oauth_database'] == wiki and not before['shared_virtual_domain']
        assert not before['databases'][wiki]['timestamp_patch_markers']
        compose('up', '-d', '--no-deps', '--force-recreate', 'provisioner')
        until = time.monotonic() + 90
        while time.monotonic() < until:
            state = json.loads(compose('exec', '-T', 'redis', 'redis-cli', '--json',
                                      'HGETALL', 'global:farm:provision-status:' + wiki).stdout)
            if state.get('state') == 'complete':
                break
            time.sleep(.3)
        else:
            raise RuntimeError('Provisioning stalled: ' + wiki)
        after = probe(wiki, exercise=True)
        assert after['oauth_database'] == 'metawiki' and after['shared_virtual_domain']
        assert after['central_wiki'] == 'metawiki'
        assert after['databases']['metawiki'] == central, 'Central OAuth schema/markers/row counts changed'
        local = after['databases'][wiki]
        assert local['tables'] == before['databases'][wiki]['tables'], 'Local schema/rows changed'
        assert len(local['timestamp_patch_markers']) == (2 if mode == 'two' else 0)
        assert all(c['COLUMN_TYPE'] == 'binary(14)' for c in local['timestamp_columns'])
        snapshots[mode] = local
        row = {'mode': mode, 'before': before, 'after': after}
        report['cases'].append(row)
        if mode == 'single':
            # Repeat the normal updater, then add only bootstrap to isolate causality.
            compose('run', '--rm', '-T', '--no-deps', '-e', 'MW_PROVISION_SINGLE_PASS=1',
                    'init', 'php', 'maintenance/run.php', 'update', '--quick', '--wiki=' + wiki,
                    '--conf=/opt/provision-single-settings.php')
            repeated = probe(wiki)
            assert repeated['databases'] == after['databases'], 'Normal rerun changed OAuth state'
            row['normal_updater_rerun'] = repeated
            compose('run', '--rm', '-T', '--no-deps', '-e', 'MW_BOOTSTRAP_DB=' + wiki,
                    'init', 'php', 'maintenance/run.php', 'update', '--quick', '--wiki=' + wiki)
            added = probe(wiki)
            assert added['databases'][wiki]['tables'] == local['tables']
            assert added['databases'][wiki]['timestamp_columns'] == local['timestamp_columns']
            assert added['databases'][wiki]['timestamp_patch_markers'] == snapshots['two']['timestamp_patch_markers']
            assert added['databases']['metawiki'] == central
            row['after_adding_bootstrap_only'] = added
        print('PASS ' + mode + ': routing, schema, markers, OAuth 1/2 consumer/acceptance timestamp round-trips', flush=True)
    assert snapshots['two']['tables'] == snapshots['single']['tables']
    assert snapshots['two']['timestamp_columns'] == snapshots['single']['timestamp_columns']
    report['status'] = 'passed'
except BaseException:
    report['status'] = 'failed'
    print(compose('logs', '--tail=20', 'provisioner', check=False).stdout, flush=True)
    raise
finally:
    report['cleanup_succeeded'] = compose('down', '-v', '--remove-orphans', check=False).returncode == 0
    Path('/tmp/oauth-provisioning.json').write_text(json.dumps(report, indent=2) + '\n')
