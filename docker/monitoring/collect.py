#!/usr/bin/env python3
"""Read the existing admin-agent snapshot and publish bounded Prometheus gauges."""
import argparse
import http.client
import json
import math
import os
from pathlib import Path
import re
import socket
import tempfile
import time

SOCKET = '/run/cloudwiki-admin-agent/agent.sock'
OUTPUT = '/var/lib/cloudwiki-monitoring/textfile/services.prom'
STATES = {'running': 1, 'active': 1, 'exited': 0, 'inactive': 0, 'failed': 0,
          'dead': 0, 'restarting': 0, 'paused': 0, 'created': 0, 'missing': -1,
          'unknown': -2, 'ambiguous': -3}
HEALTH = {'healthy': 1, 'unhealthy': 0, 'starting': 2, None: -1}
HELP = {
    'cloudwiki_collector_success': 'Whether the service snapshot is fresh and error free.',
    'cloudwiki_snapshot_timestamp_seconds': 'Unix time of the source service snapshot.',
    'cloudwiki_collection_timestamp_seconds': 'Unix time of the collector run.',
    'cloudwiki_service_state': 'Service state: running 1, stopped 0, missing -1, unknown -2, ambiguous -3.',
    'cloudwiki_service_health': 'Health: healthy 1, unhealthy 0, starting 2, unconfigured -1, unknown -2.',
    'cloudwiki_service_cpu_percent': 'CPU percentage where one core equals 100 percent.',
    'cloudwiki_service_memory_bytes': 'Current service memory usage in bytes.',
    'cloudwiki_service_uptime_seconds': 'Current service uptime in seconds.',
    'cloudwiki_service_restarts': 'Restart count since service or container creation.',
}

class UnixConnection(http.client.HTTPConnection):
    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(4)
        self.sock.connect(SOCKET)

def number(value):
    return isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(value)

def render(snapshot, now):
    lines = []
    declared = set()
    def metric(name, value, labels=''):
        if number(value):
            if name not in declared:
                lines.extend([f'# HELP {name} {HELP[name]}', f'# TYPE {name} gauge'])
                declared.add(name)
            lines.append(f'{name}{labels} {value}')
    services = snapshot.get('services') if isinstance(snapshot, dict) else None
    valid = isinstance(services, dict) and number(services.get('collectedAt')) and isinstance(services.get('items'), list)
    stamp = services['collectedAt'] if valid else 0
    fresh = valid and 0 <= now - stamp <= 60
    success = fresh and not services.get('errors')
    metric('cloudwiki_collector_success', int(bool(success)))
    metric('cloudwiki_snapshot_timestamp_seconds', stamp)
    metric('cloudwiki_collection_timestamp_seconds', now)
    if not fresh:
        return '\n'.join(lines) + '\n'
    seen = set()
    for item in services['items']:
        name, kind = item.get('name'), item.get('kind')
        if not isinstance(name, str) or not re.fullmatch(r'[a-zA-Z0-9_.-]{1,80}', name) or kind not in ('container', 'host'):
            raise ValueError('Invalid service labels')
        key = (name, kind)
        if key in seen:
            raise ValueError('Duplicate service')
        seen.add(key)
        labels = '{service=' + json.dumps(name) + ',kind=' + json.dumps('unit' if kind == 'host' else kind) + '}'
        state = STATES.get(item.get('state'), -2)
        metric('cloudwiki_service_state', state, labels)
        metric('cloudwiki_service_health', HEALTH.get(item.get('health'), -2), labels)
        for field, suffix in [('cpuPercent', 'cpu_percent'), ('memoryBytes', 'memory_bytes'),
                              ('uptimeSeconds', 'uptime_seconds'), ('restarts', 'restarts')]:
            value = item.get(field)
            if number(value) and value >= 0:
                metric('cloudwiki_service_' + suffix, value, labels)
    return '\n'.join(lines) + '\n'

def atomic_write(output, content):
    output = Path(output)
    output.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix='.services-', dir=output.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, 0o644)
        os.replace(temporary, output)
    finally:
        Path(temporary).unlink(missing_ok=True)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', default=OUTPUT)
    args = parser.parse_args()
    now = time.time()
    try:
        connection = UnixConnection('localhost', timeout=4)
        try:
            connection.request('GET', '/snapshot')
            response = connection.getresponse()
            if response.status != 200:
                raise ValueError('Snapshot unavailable')
            raw = response.read(1024 * 1024 + 1)
            if len(raw) > 1024 * 1024:
                raise ValueError('Snapshot too large')
            content = render(json.loads(raw), now)
        finally:
            connection.close()
    except Exception:
        content = render({}, now)
        print('Admin-agent snapshot unavailable or invalid; publishing unknown state', flush=True)
    atomic_write(args.output, content)

if __name__ == '__main__':
    main()
