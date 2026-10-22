#!/usr/bin/env python3
"""Interleaved fresh-wiki benchmark; never changes the main compose project."""
import json
import os
from pathlib import Path
import statistics
import subprocess
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]
project = 'cloudwiki-provision-test-speed-' + uuid.uuid4().hex[:8]
prefix = '9provision' + uuid.uuid4().hex[:6]
env = dict(os.environ, MW_ENV='development', MW_PROVISION_SINGLE_PASS='0')
base = ['docker', 'compose', '-p', project, '-f', 'compose.yaml',
        '-f', 'docker/tests/provisioning.compose.yaml']
output = Path(os.getenv('PROVISION_SPEED_REPORT', '/tmp/provisioning-speed.json'))
variants = os.getenv('PROVISION_SPEED_VARIANTS', 'public,schema').split(',')
assert set(variants) <= {'public', 'schema'} and variants
samples = int(os.getenv('PROVISION_SPEED_SAMPLES', '4'))
assert samples >= 2
report = {'project': project, 'status': 'running', 'cases': [],
          'measurement': 'Redis finished_ms minus started_ms; provisioning only',
          'order': 'One discarded warm-up pair per variant, then alternating paired order',
          'single_pass_pipeline': 'production coordinator', 'main_stack_modified': False}
snapshots = {}


def compose(*args, check=True):
    result = subprocess.run(base + list(args), cwd=ROOT, env=env, text=True,
                            capture_output=True, errors='replace', timeout=240)
    if check and result.returncode:
        raise RuntimeError(result.stdout + result.stderr)
    return result


def save():
    output.write_text(json.dumps(report, indent=2) + '\n')


def run_case(mode, variant, pair, warmup):
    compose('stop', 'provisioner')
    env['MW_PROVISION_SINGLE_PASS'] = '1' if mode == 'single' else '0'
    wiki = prefix + mode + variant + str(pair) + 'wiki'
    compose('run', '--rm', '-T', '--no-deps', 'init', 'php', 'maintenance/run.php',
            '/opt/provisioning-test.php', '--wiki=metawiki', '--action=create',
            '--target=' + wiki, '--variant=' + variant)
    compose('run', '--rm', '-T', '--no-deps', '--user', 'www-data', 'init', 'php',
            'maintenance/run.php', '/opt/farm/prepare-wiki.php',
            '--wiki=metawiki', '--target=' + wiki)
    compose('run', '--rm', '-T', '--no-deps', 'init', 'php', '-r',
            '@unlink("/var/www/mediawiki/cw_cache/config-".$argv[1].".php");', wiki)
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
    result = compose('run', '--rm', '-T', '--no-deps', 'init', 'php',
                     'maintenance/run.php', '/opt/provisioning-test.php',
                     '--wiki=' + wiki, '--action=inspect', '--checkuser')
    snapshot = json.loads(result.stdout.splitlines()[-1])
    assert snapshot['language'] == 'en'
    assert snapshot['anonymous_read'] is True
    if variant == 'schema':
        assert snapshot['uploads'] is False
        assert {'Cargo', 'Linter'} <= set(snapshot['extensions'])
        assert 'cargo_tables' in snapshot['tables']
    if variant not in snapshots:
        snapshots[variant] = snapshot
    reference = snapshots[variant]
    assert snapshot['tables'] == reference['tables'], 'Schema mismatch: ' + wiki
    assert snapshot['extensions'] == reference['extensions'], 'Extensions mismatch: ' + wiki
    row = {'mode': mode, 'variant': variant, 'pair': pair, 'warmup': warmup,
           'wiki': wiki, 'duration_ms': int(state['finished_ms']) - int(state['started_ms']),
           'table_count': len(snapshot['tables']), 'checkuser': snapshot['checkuser'],
           'update_log_difference': {
               'only_reference': sorted(set(reference['updatelog_keys']) - set(snapshot['updatelog_keys'])),
               'only_case': sorted(set(snapshot['updatelog_keys']) - set(reference['updatelog_keys']))}}
    report['cases'].append(row)
    save()
    print(('WARMUP' if warmup else 'MEASURED') + f' {variant} pair {pair} {mode}: '
          + str(row['duration_ms']) + ' ms; schemas/settings/CheckUser passed', flush=True)


try:
    print('Initializing ' + project, flush=True)
    config = json.loads(compose('--profile', 'tools', 'config', '--format', 'json').stdout)
    image = config['services']['init']['image']
    identity = subprocess.run(['docker', 'image', 'inspect', image, '--format',
                               '{{.Id}} {{.Architecture}}'], check=True,
                              capture_output=True, text=True).stdout.split()
    # Pin the image for every fresh container, even if another task retags it.
    env['MW_IMAGE'] = identity[0]
    report['image_id'], report['architecture'] = identity
    compose('up', '-d', '--wait', 'database', 'redis', 'memcached')
    compose('run', '--rm', '-T', '--no-deps', 'init', 'php', '/opt/farm/manage.php', 'fresh')
    for variant in variants:
        for pair in range(samples + 1):
            for mode in (['two', 'single'] if pair % 2 == 0 else ['single', 'two']):
                run_case(mode, variant, pair, pair == 0)
    report['summary'] = {}
    for variant in variants:
        summary = {}
        for mode in ['two', 'single']:
            values = [c['duration_ms'] for c in report['cases']
                      if c['variant'] == variant and c['mode'] == mode and not c['warmup']]
            summary[mode] = {'samples_ms': values, 'median_ms': statistics.median(values),
                             'mean_ms': statistics.mean(values), 'min_ms': min(values),
                             'max_ms': max(values)}
        summary['median_reduction_percent'] = 100 * (1 - summary['single']['median_ms'] / summary['two']['median_ms'])
        summary['paired_savings_ms'] = [
            next(c['duration_ms'] for c in report['cases'] if c['variant'] == variant and c['pair'] == pair and c['mode'] == 'two') -
            next(c['duration_ms'] for c in report['cases'] if c['variant'] == variant and c['pair'] == pair and c['mode'] == 'single')
            for pair in range(1, samples + 1)]
        report['summary'][variant] = summary
    report['status'] = 'passed'
    print(json.dumps(report['summary'], indent=2), flush=True)
except BaseException:
    report['status'] = 'failed'
    print(compose('logs', '--no-color', '--tail=25', 'provisioner', check=False).stdout, flush=True)
    raise
finally:
    # Preserve slow-run diagnostics before the disposable volume is removed.
    with tempfile.TemporaryDirectory(prefix='provision-speed-logs-') as directory:
        for case in report['cases']:
            if case['duration_ms'] < 3000:
                continue
            log = Path(directory) / (case['wiki'] + '.log')
            copied = compose('cp', 'provisioner:/var/www/mediawiki/cw_cache/.provision-' +
                             case['wiki'] + '.log', str(log), check=False)
            if copied.returncode == 0:
                case['updater_log_tail'] = log.read_text(errors='replace').splitlines()[-20:]
    cleanup = compose('down', '-v', '--remove-orphans', check=False)
    report['cleanup_succeeded'] = cleanup.returncode == 0
    save()
