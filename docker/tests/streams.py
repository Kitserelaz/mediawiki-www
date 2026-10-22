#!/usr/bin/env python3
"""Exercise durable stream dispatch in an automatically removed private farm."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--image', default='cloudwiki-php:local')
parser.add_argument('--report', type=Path, default=Path('/tmp/streams-tests.json'))
args = parser.parse_args()
project = 'cloudwiki-stream-test-' + uuid.uuid4().hex[:8]
env = dict(os.environ, MW_IMAGE=args.image, JOBCHRON_IMAGE=args.image, MW_ENV='development')
base = ['docker', 'compose', '-p', project, '-f', 'compose.yaml', '-f', 'docker/tests/streams.compose.yaml']
STREAM = 'global:jobqueue:dispatch'
PENDING = 'global:jobqueue:dispatch-pending'
QUEUE = '["null","testwiki"]'
READY = 'testwiki:jobqueue:null:l-unclaimed'
GROUP = 'farm-dispatch-v1'
checks = []
completed = False


def compose(*parts, check=True):
    result = subprocess.run(base + list(parts), cwd=ROOT, env=env, text=True, capture_output=True, errors='replace')
    if check and result.returncode:
        raise RuntimeError(result.stdout + result.stderr)
    return result


def redis(*parts):
    result = compose('exec', '-T', 'redis', 'redis-cli', '--json', *map(str, parts))
    return json.loads(result.stdout)


def wait_for(predicate, description, timeout=60):
    until = time.monotonic() + timeout
    while time.monotonic() < until:
        if predicate():
            return
        time.sleep(0.3)
    raise AssertionError('Timed out: ' + description)


def passed(name):
    checks.append(name)
    print('PASS: ' + name, flush=True)


def enqueue(*options, check=True):
    return compose('exec', '-T', 'provisioner', 'php', 'maintenance/run.php',
                   '/opt/streams-test.php', '--wiki=testwiki', *options, check=check)


def drained():
    return (redis('LLEN', READY) == 0 and redis('HLEN', 'testwiki:jobqueue:null:h-data') == 0
            and redis('HGET', PENDING, QUEUE) is None)


def start_workers():
    compose('up', '-d', '--wait', 'worker', 'provisioner')


try:
    print('Initializing disposable stream farm: ' + project, flush=True)
    compose('up', '-d', '--wait', 'database', 'redis', 'memcached')
    compose('run', '--rm', '-T', '--no-deps', 'init', 'php', '/opt/farm/manage.php', 'fresh')
    compose('up', '-d', '--wait', 'jobchron', 'worker', 'provisioner')
    wait_for(lambda: redis('HLEN', PENDING) == 0, 'initial queues drained')

    compose('stop', 'worker')
    enqueue('--count=31')
    event = redis('HGET', PENDING, QUEUE)
    assert event and redis('LLEN', READY) == 31
    assert len(redis('XRANGE', STREAM, event, event)) == 1
    compose('up', '-d', '--wait', 'worker')
    wait_for(drained, 'coalesced queue and bounded batch continuation')
    passed('31 jobs coalesced into a durable signal; batch continuation drained all jobs')

    compose('stop', 'worker')
    enqueue()
    event = redis('HGET', PENDING, QUEUE)
    delivery = redis('XREADGROUP', 'GROUP', GROUP, 'crashed-consumer', 'COUNT', 1,
                     'STREAMS', STREAM, '>')
    assert delivery and event in json.dumps(delivery), delivery
    compose('up', '-d', '--wait', 'worker')
    wait_for(drained, 'unacknowledged delivery recovery')
    passed('unacknowledged event reclaimed after a consumer disappeared')

    compose('stop', 'jobchron')
    enqueue('--delay=4')
    assert redis('ZCARD', 'testwiki:jobqueue:null:z-delayed') == 1
    compose('up', '-d', '--wait', 'jobchron')
    wait_for(drained, 'delayed job release and stream wake-up')
    passed('delayed release atomically woke a blocked worker')

    compose('stop', 'worker', 'jobchron')
    enqueue()
    compose('exec', '-T', 'provisioner', 'php', 'maintenance/run.php',
            '/opt/jobchron-test.php', '--wiki=testwiki', '--action=claim')
    assert redis('ZCARD', 'testwiki:jobqueue:null:z-claimed') == 1
    compose('up', '-d', '--wait', 'worker', 'jobchron')
    wait_for(drained, 'expired claim recovery and retry signal')
    passed('expired job claim recycled and dispatched without scanning wikis')

    # Keep the deliberately short claim-expiry policy out of long-running lease
    # tests. Allow Docker command latency without racing job completion.
    compose('stop', 'jobchron')
    compose('up', '-d', '--wait', '--scale', 'worker=2', 'worker')
    enqueue('--usleep=15000000')
    wait_for(lambda: redis('ZCARD', 'testwiki:jobqueue:null:z-claimed') == 1, 'slow job acquired')
    time.sleep(2.1)
    event = redis('HGET', PENDING, QUEUE)
    assert event
    pending = redis('XPENDING', STREAM, GROUP, event, event, 1)
    assert pending and pending[0][2] < 2000 and pending[0][3] == 1, pending
    wait_for(drained, 'slow job acknowledgment')
    compose('up', '-d', '--wait', '--scale', 'worker=1', 'worker')
    passed('lease heartbeats prevented another worker reclaiming active work')

    enqueue('--usleep=15000000')
    wait_for(lambda: redis('ZCARD', 'testwiki:jobqueue:null:z-claimed') == 1, 'job running')
    enqueue('--count=31')
    wait_for(drained, 'jobs arriving while a signal is in flight')
    passed('jobs arriving during execution were not stranded by signal coalescing')
    compose('up', '-d', '--wait', 'jobchron')

    compose('stop', 'worker')
    enqueue()
    event = redis('HGET', PENDING, QUEUE)
    compose('restart', 'redis')
    wait_for(lambda: compose('exec', '-T', 'redis', 'redis-cli', 'PING', check=False).returncode == 0,
             'Redis restart')
    assert redis('XRANGE', STREAM, event, event)
    compose('up', '-d', '--wait', 'worker')
    wait_for(drained, 'persisted signal after Redis restart')
    passed('Redis restart retained the stream event and queued payload')

    compose('restart', 'redis')
    wait_for(lambda: compose('exec', '-T', 'worker', 'php', '/opt/farm/dispatch-health.php', 'jobs',
                             check=False).returncode == 0, 'blocked consumer reconnect')
    enqueue()
    wait_for(drained, 'work after reconnect')
    passed('blocked consumers reconnected and resumed after Redis restart')

    wait_for(lambda: redis('HLEN', PENDING) == 0, 'empty dispatch backlog')
    before = compose('logs', '--no-color', 'worker').stdout.count('Dispatch jobs:')
    time.sleep(3)
    assert 'cmd=xreadgroup' in compose('exec', '-T', 'redis', 'redis-cli', 'CLIENT', 'LIST').stdout
    assert compose('logs', '--no-color', 'worker').stdout.count('Dispatch jobs:') == before
    passed('idle consumers blocked in Redis without launching empty PHP batches')

    compose('stop', 'worker')
    redis('RENAME', STREAM, STREAM + ':saved-test')
    redis('SET', STREAM, 'wrong-type')
    failure = enqueue(check=False)
    assert failure.returncode != 0 and redis('LLEN', READY) == 0
    assert redis('HLEN', 'testwiki:jobqueue:null:h-data') == 0
    redis('DEL', STREAM)
    redis('RENAME', STREAM + ':saved-test', STREAM)
    compose('up', '-d', '--wait', 'worker')
    passed('invalid stream key rejected enqueue before mutating job payloads')

    before = redis('XLEN', 'global:farm:dispatch-dead')
    redis('XADD', STREAM, '*', 'queue', 'malformed', 'ready', 'wrong')
    wait_for(lambda: redis('XLEN', 'global:farm:dispatch-dead') > before, 'invalid event quarantine')
    passed('malformed events quarantined without stopping normal consumers')

    compose('stop', 'provisioner')
    compose('exec', '-T', 'worker', 'php', '-r',
            'require "/opt/farm/dispatch.php"; FarmDispatch::provision(FarmDispatch::connect(), "testwiki");')
    marker = compose('exec', '-T', 'worker', 'php', '-r',
                     'exit(is_file("/var/www/mediawiki/cw_cache/.provision-testwiki") ? 1 : 0);', check=False)
    assert marker.returncode == 0
    compose('up', '-d', '--wait', 'provisioner')
    wait_for(lambda: redis('HGET', 'global:farm:provision-status:testwiki', 'state') == 'complete',
             'provision signal without producer marker')
    assert redis('HGET', 'global:farm:provision-pending', 'testwiki') is None
    passed('durable provisioning event recovered even without a producer filesystem marker')

    compose('stop', '-t', '5', 'worker', 'provisioner')
    for service in ['worker', 'provisioner']:
        statuses = [json.loads(line) for line in compose('ps', '-a', '--format', 'json', service).stdout.splitlines() if line]
        assert all(item['ExitCode'] == 0 for item in statuses), statuses
    passed('blocking consumers exited cleanly on SIGTERM')
    completed = True
    print('All stream dispatch lifecycle checks passed.', flush=True)
except BaseException:
    for service in ['worker', 'provisioner', 'jobchron']:
        print(compose('logs', '--no-color', '--tail=10', service, check=False).stdout, flush=True)
    raise
finally:
    args.report.write_text(json.dumps({'project': project, 'passed_checks': checks,
                                     'count': len(checks), 'status': 'passed' if completed else 'failed'}, indent=2) + '\n')
    for service in ['worker', 'provisioner', 'jobchron']:
        Path('/tmp/streams-' + service + '.log').write_text(compose('logs', '--no-color', service, check=False).stdout)
    compose('down', '-v', '--remove-orphans', check=False)
