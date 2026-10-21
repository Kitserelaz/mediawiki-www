#!/usr/bin/env python3
"""Test the real Redis queue/chron lifecycle in a disposable, private farm."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--image', default=os.getenv('MW_IMAGE', 'cloudwiki-php:local'))
args = parser.parse_args()
project = 'cloudwiki-jobchron-test-' + uuid.uuid4().hex[:8]
claimant = project + '-claimant'
env = dict(os.environ, MW_IMAGE=args.image, JOBCHRON_IMAGE=args.image, MW_ENV='development')
command = ['docker', 'compose', '-p', project, '-f', 'compose.yaml',
           '-f', 'docker/tests/jobchron.compose.yaml']


def compose(*parts, check=True):
    result = subprocess.run(command + list(parts), cwd=ROOT, env=env,
                            text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if check and result.returncode:
        raise RuntimeError(result.stdout + result.stderr)
    return result


def probe(action='stats', *options):
    result = compose('run', '--rm', '-T', '--no-deps', 'init', 'php',
                     'maintenance/run.php', '/opt/jobchron-test.php', '--wiki=testwiki',
                     '--action=' + action, *options)
    return json.loads(result.stdout.strip().splitlines()[-1])


def wait_for(predicate, description, timeout=45):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if predicate():
            return
        time.sleep(0.5)
    raise AssertionError('Timed out: ' + description)


def healthy():
    return compose('exec', '-T', 'jobchron', 'php', '/opt/farm/jobchron-health.php',
                   check=False).returncode == 0


def drain():
    compose('run', '--rm', '-T', '--no-deps', 'init', 'php', 'maintenance/run.php',
            'runJobs', '--wiki=testwiki', '--type=null', '--maxjobs=1', '--maxtime=5')
    assert probe() == {'ready': 0, 'delayed': 0, 'acquired': 0, 'abandoned': 0}


try:
    print('Initializing disposable farm: ' + project, flush=True)
    compose('up', '-d', '--wait', 'database', 'redis', 'memcached')
    compose('run', '--rm', '-T', '--no-deps', 'init', 'php', '/opt/farm/manage.php', 'fresh')

    state = probe('enqueue', '--delay=4')
    assert state['delayed'] == 1 and state['ready'] == 0, state
    compose('up', '-d', 'jobchron')
    wait_for(healthy, 'successful chron maintenance heartbeat')
    wait_for(lambda: probe()['ready'] == 1, 'delayed job release')
    drain()
    print('Delayed NullJob released and executed.', flush=True)

    probe('enqueue')
    compose('run', '-d', '--no-deps', '--name', claimant, 'init', 'php',
            'maintenance/run.php', '/opt/jobchron-test.php', '--wiki=testwiki',
            '--action=claim-and-wait')
    wait_for(lambda: probe()['acquired'] == 1, 'worker claim')
    subprocess.run(['docker', 'kill', claimant], check=True, stdout=subprocess.DEVNULL)
    wait_for(lambda: probe()['ready'] == 1, 'recovery after worker SIGKILL')
    drain()
    print('Interrupted claim recovered and executed.', flush=True)

    probe('enqueue')
    probe('claim')
    wait_for(lambda: probe()['ready'] == 1, 'first retry')
    probe('claim')
    wait_for(lambda: probe()['abandoned'] == 1, 'retry exhaustion')
    assert probe()['ready'] == 0
    print('Retry exhaustion abandoned the job without an endless retry loop.', flush=True)

    compose('stop', 'jobchron')
    probe('enqueue', '--delay=4')
    compose('restart', 'redis')
    wait_for(lambda: compose('exec', '-T', 'redis', 'redis-cli', 'ping',
                             check=False).stdout.strip() == 'PONG', 'Redis restart')
    assert probe()['delayed'] == 1
    compose('start', 'jobchron')
    wait_for(lambda: probe()['ready'] == 1, 'release after Redis restart')
    # The abandoned control job is intentionally still present.
    compose('run', '--rm', '-T', '--no-deps', 'init', 'php', 'maintenance/run.php',
            'runJobs', '--wiki=testwiki', '--type=null', '--maxjobs=1', '--maxtime=5')
    assert probe()['ready'] == 0 and probe()['delayed'] == 0
    print('Redis restart preserved delayed work and maintenance resumed.', flush=True)

    compose('stop', 'redis')
    compose('exec', '-T', 'jobchron', 'php', '-r',
            "file_put_contents('/tmp/farm-jobchron-heartbeat', (string)(time()-121));")
    time.sleep(2)
    assert not healthy(), 'Chron must be unhealthy when successful maintenance stops'
    compose('start', 'redis')
    wait_for(healthy, 'heartbeat recovery after Redis reconnect', timeout=50)
    print('Heartbeat detects failed maintenance and recovers after Redis reconnect.', flush=True)

    compose('stop', '-t', '10', 'jobchron')
    code = compose('ps', '-a', '--format', 'json', 'jobchron').stdout.strip()
    status = json.loads(code)
    if isinstance(status, list):
        status = status[0]
    assert status['ExitCode'] == 0, status
    print('Chron exits cleanly on SIGTERM. All lifecycle checks passed.', flush=True)
except BaseException:
    print(compose('logs', '--no-color', '--tail=60', 'jobchron', check=False).stdout,
          flush=True)
    raise
finally:
    subprocess.run(['docker', 'rm', '-f', claimant], stdout=subprocess.DEVNULL,
                   stderr=subprocess.DEVNULL)
    compose('down', '-v', '--remove-orphans', check=False)
