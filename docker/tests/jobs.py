#!/usr/bin/env python3
"""Verify Redis durability and worker discovery across an existing and newly created wiki."""
import argparse, json, os, subprocess, time
from pathlib import Path
root = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--env-file')
parser.add_argument('--compose-file', action='append', default=[])
args = parser.parse_args()
command = ['docker', 'compose']
if args.env_file: command += ['--env-file', args.env_file]
for file in args.compose_file: command += ['-f', file]
configuration = json.loads(subprocess.check_output(command + ['--profile', 'tools', 'config', '--format', 'json'], cwd=root))
if configuration['services']['init']['environment']['MW_ENV'] != 'development':
    parser.error('Queue durability checks require a development fixture')
prefix = os.getenv('DOCKER_FIXTURE_PREFIX', 'docker')
wikis = ['testwiki', prefix + 'smokewiki']
def compose(*args):
    return subprocess.check_output([*command,*args], cwd=root, text=True).strip()
def queue(wiki, enqueue=False):
    args = ['run','--rm','--no-deps','--user','www-data','-v',str(root/'docker/tests/jobs.php')+':/opt/jobs-test.php:ro','init','php','maintenance/run.php','/opt/jobs-test.php','--wiki='+wiki]
    if enqueue: args.append('--enqueue')
    return int(compose(*args).splitlines()[-1])
try:
    compose('stop','worker','provisioner','jobchron','scheduler')
    before = {wiki:queue(wiki, True) for wiki in wikis}
    assert all(size >= 1 for size in before.values()), before
    compose('restart','redis')
    for wiki in wikis: assert queue(wiki) == before[wiki], wiki
    compose('up','-d','worker')
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        if all(queue(wiki) == 0 for wiki in wikis): break
        time.sleep(2)
    else: raise AssertionError('Worker did not drain existing/new wiki test jobs')
    print('Redis restart preserved queued jobs; worker drained existing and newly created wiki queues.')
finally:
    compose('up','-d','worker','provisioner','jobchron','scheduler')
