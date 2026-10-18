#!/usr/bin/env python3
"""Verify Redis durability and worker discovery across an existing and newly created wiki."""
import os, subprocess, time
from pathlib import Path
root = Path(__file__).resolve().parents[2]
prefix = os.getenv('DOCKER_FIXTURE_PREFIX', 'docker')
wikis = ['testwiki', prefix + 'smokewiki']
def compose(*args):
    return subprocess.check_output(['docker','compose',*args], cwd=root, text=True).strip()
def queue(wiki, enqueue=False):
    args = ['run','--rm','--no-deps','--user','www-data','-v',str(root/'docker/tests/jobs.php')+':/opt/jobs-test.php:ro','init','php','maintenance/run.php','/opt/jobs-test.php','--wiki='+wiki]
    if enqueue: args.append('--enqueue')
    return int(compose(*args).splitlines()[-1])
try:
    compose('stop','worker','scheduler')
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
    compose('up','-d','worker','scheduler')
