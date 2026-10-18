#!/usr/bin/env python3
"""Wait for every local rehearsal hostname to finish certificate issuance."""
import argparse
import subprocess
import time

parser = argparse.ArgumentParser()
parser.add_argument('--domain', default='localhost')
parser.add_argument('--timeout', type=int, default=120)
args = parser.parse_args()
pending = {f'{host}.{args.domain}' for host in ['meta', 'login', 'test', 'auth', 'counter']}
deadline = time.monotonic() + args.timeout
while pending and time.monotonic() < deadline:
    for host in sorted(pending):
        result = subprocess.run([
            'curl', '--noproxy', '*', '--resolve', f'{host}:443:127.0.0.1',
            '--insecure', '--silent', '--output', '/dev/null', '--max-time', '3',
            f'https://{host}/',
        ], capture_output=True)
        if result.returncode == 0:
            pending.remove(host)
    if pending:
        time.sleep(2)
if pending:
    raise SystemExit('HTTPS did not become ready: ' + ', '.join(sorted(pending)))
print('All five local HTTPS hostnames are ready.')
