#!/usr/bin/env python3
"""Create fresh digit-prefixed public/private/UI wikis and verify provisioning.

Run against the local development stack. Unlike reused smoke fixtures, every
invocation exercises creation. The uniquely named test wikis remain inspectable.
"""
import argparse
import json
import os
from pathlib import Path
import secrets
import subprocess
import time

ROOT = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--report', type=Path, default=Path('/tmp/wiki-creation-run.json'))
args = parser.parse_args()
configuration = json.loads(subprocess.check_output(
    ['docker', 'compose', '--profile', 'tools', 'config', '--format', 'json'], cwd=ROOT, text=True))
environment = configuration['services']['init']['environment']
if environment.get('MW_ENV') != 'development' or environment.get('MW_DOMAIN') != 'localhost':
    parser.error('Creation tests require the localhost development stack')

# Numeric prefixes reproduce the former mismatch between CreateWiki's validator
# and the worker/provision/management scripts' identifier validation.
prefix = '9creation' + secrets.token_hex(4)
wikis = [prefix + suffix for suffix in ['smokewiki', 'privatewiki', 'uismokewiki']]
report = {'prefix': prefix, 'wikis': wikis, 'status': 'running'}
args.report.write_text(json.dumps(report, indent=2) + '\n')
env = dict(os.environ, DOCKER_FIXTURE_PREFIX=prefix)
print('Fresh fixture prefix: ' + prefix, flush=True)
try:
    subprocess.run(['docker', 'compose', 'run', '--rm', '--no-deps', '-e',
                    'DOCKER_FIXTURE_PREFIX=' + prefix, 'init', 'php',
                    'maintenance/run.php', '/opt/farm/test-fixtures.php', '--wiki=metawiki'],
                   cwd=ROOT, env=env, check=True)
    subprocess.run(['node', 'node_modules/@playwright/test/cli.js', 'test',
                    '--config', 'playwright.config.js', 'auth.spec.js', '--grep',
                    'browser login propagates|new public and private'],
                   cwd=ROOT / 'docker/tests', env=env, check=True)
    deadline = time.monotonic() + 90
    while True:
        pending = subprocess.run(
            ['docker', 'compose', 'exec', '-T', 'php', 'php', '-r',
             'foreach (array_slice($argv, 1) as $wiki) { '
             'if (is_file("/var/www/mediawiki/cw_cache/.provision-" . $wiki)) { exit(1); } } '
             'require "/opt/farm/dispatch.php"; $r=FarmDispatch::connect(); '
             'foreach (array_slice($argv,1) as $wiki) { if ($r->hExists(FarmDispatch::PROVISION_PENDING,$wiki)) { exit(1); } }',
             *wikis], cwd=ROOT)
        if pending.returncode == 0:
            break
        if time.monotonic() >= deadline:
            raise RuntimeError('Fresh wiki provisioning markers did not clear')
        time.sleep(1)
    invocations = json.loads(subprocess.check_output(
        ['docker', 'compose', 'exec', '-T', 'php', 'php', '-r',
         '$counts=[]; foreach(array_slice($argv,1) as $wiki) { '
         '$log=file_get_contents("/var/www/mediawiki/cw_cache/.provision-".$wiki.".log"); '
         '$counts[$wiki]=preg_match_all("/MediaWiki [^\\r\\n]+ Updater/",$log); '
         'if($counts[$wiki]!==1) { throw new RuntimeException("Expected one updater for ".$wiki); } } '
         'echo json_encode($counts);', *wikis], cwd=ROOT, text=True))
    report['updater_invocations'] = invocations
    dispatch_log = subprocess.check_output(['docker','compose','logs','--no-color','provisioner'],cwd=ROOT,text=True)
    attempts = {wiki:dispatch_log.count('Dispatch provision: '+wiki+'\n') for wiki in wikis}
    assert all(count == 1 for count in attempts.values()), attempts
    report['dispatch_attempts'] = attempts
    report['status'] = 'passed'
    report['checks'] = ['public/private CreateWiki creation', 'browser-form creation',
                        'public API readiness', 'private API/file denial',
                        'cross-wiki login/logout', 'all provisioning markers cleared',
                        'exactly one updater invocation per fresh wiki']
    print('Fresh digit-prefixed wiki creation and provisioning passed.', flush=True)
except BaseException:
    report['status'] = 'failed'
    raise
finally:
    args.report.write_text(json.dumps(report, indent=2) + '\n')
