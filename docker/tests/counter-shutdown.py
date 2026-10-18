#!/usr/bin/env python3
"""Confirm container SIGTERM flushes a delayed SQLite increment to a named volume."""
import json, os, subprocess, time, uuid
image = os.getenv('COUNTER_IMAGE', 'cloudwiki-counter:local')
name = 'counter-shutdown-' + uuid.uuid4().hex[:10]
volume = name + '-data'
def docker(*args):
    return subprocess.check_output(['docker', *args], text=True).strip()
try:
    docker('volume', 'create', volume)
    docker('run', '-d', '--name', name, '-v', volume + ':/app/data', '-e', 'DB_INTERVAL=3600', image)
    for attempt in range(30):
        ready = subprocess.run(['docker', 'exec', name, 'node', '-e', "fetch('http://127.0.0.1:3000/heart-beat').then(r=>{if(!r.ok)process.exit(1)}).catch(()=>process.exit(1))"], capture_output=True)
        if ready.returncode == 0: break
        time.sleep(1)
    else: raise AssertionError('Counter did not become ready')
    docker('exec', name, 'node', '-e', "fetch('http://127.0.0.1:3000/@shutdown-test').then(r=>{if(!r.ok)process.exit(1)})")
    docker('stop', '--time', '30', name)
    assert docker('inspect', '--format', '{{.State.ExitCode}}', name) == '0'
    result = docker('run', '--rm', '-v', volume + ':/app/data', image, 'node', '-e', "const db=require('./db/sqlite'); db.getNum('shutdown-test').then(r=>{console.log(JSON.stringify(r));db.close()})")
    assert json.loads(result)['num'] == 1, result
    print('Container shutdown flushed the pending counter and preserved SQLite data.')
finally:
    subprocess.run(['docker', 'rm', '-f', name], capture_output=True)
    subprocess.run(['docker', 'volume', 'rm', volume], capture_output=True)
