#!/usr/bin/env python3
"""Stream a build unchanged and publish timing metadata, never its log contents."""
import argparse
import json
import os
import signal
import subprocess
import sys
import threading
import time
import urllib.request


class Activity:
    def __init__(self, job, phase):
        self.data = {'job': job, 'phase': phase, 'run_id': os.environ.get('GITHUB_RUN_ID'),
                     'run_attempt': int(os.environ.get('GITHUB_RUN_ATTEMPT', '1')),
                     'started_at': time.time(), 'last_output_at': None, 'status': 'running'}
        self.token = os.environ.get('CLOUDWIKI_PROGRESS_TOKEN')
        self.enabled = bool(self.token and os.environ.get('GITHUB_EVENT_NAME') in ('push', 'workflow_dispatch')
                            and os.environ.get('GITHUB_REF') == 'refs/heads/main')
        self.check_id = None

    def publish(self, status='running', code=0):
        if not self.enabled:
            return
        self.data.update(heartbeat_at=time.time(), status=status)
        payload = {'name': 'CloudWiki build: ' + self.data['job'] + ' / ' + self.data['phase'],
                   'head_sha': os.environ['GITHUB_SHA'],
                   'status': 'in_progress' if status == 'running' else 'completed',
                   'output': {'title': self.data['phase'], 'summary': json.dumps(self.data)}}
        if self.check_id:
            payload.pop('head_sha')
        if status != 'running':
            payload['conclusion'] = 'success' if code == 0 else 'failure'
        suffix = '/' + str(self.check_id) if self.check_id else ''
        request = urllib.request.Request('https://api.github.com/repos/' + os.environ['GITHUB_REPOSITORY'] + '/check-runs' + suffix,
            data=json.dumps(payload).encode(), method='PATCH' if self.check_id else 'POST',
            headers={'Authorization': 'Bearer ' + self.token, 'Accept': 'application/vnd.github+json',
                     'Content-Type': 'application/json', 'X-GitHub-Api-Version': '2022-11-28'})
        try:
            with urllib.request.urlopen(request, timeout=10) as response:
                self.check_id = json.load(response)['id']
        except Exception:
            # Reporting must never change whether a real build passes or fails.
            print('::warning::Build activity reporting is unavailable; build output remains in this job log.', flush=True)
            self.enabled = False


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--job', required=True)
    parser.add_argument('--phase', required=True)
    parser.add_argument('command', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    command = args.command[1:] if args.command[:1] == ['--'] else args.command
    if not command:
        parser.error('A build command is required')
    activity = Activity(args.job, args.phase)
    activity.publish()
    environment = dict(os.environ)
    environment.pop('CLOUDWIKI_PROGRESS_TOKEN', None)
    try:
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, env=environment, start_new_session=True)
    except OSError:
        activity.publish('completed', 127)
        raise
    def forward(signum, frame):
        os.killpg(process.pid, signum)
    signal.signal(signal.SIGTERM, forward)
    signal.signal(signal.SIGINT, forward)
    def output():
        while True:
            chunk = os.read(process.stdout.fileno(), 8192)
            if not chunk:
                return
            activity.data['last_output_at'] = time.time()
            sys.stdout.buffer.write(chunk)
            sys.stdout.buffer.flush()
    reader = threading.Thread(target=output, daemon=True)
    reader.start()
    while process.poll() is None:
        try:
            process.wait(timeout=30)
        except subprocess.TimeoutExpired:
            activity.publish()
    reader.join(timeout=5)
    code = process.returncode
    activity.publish('completed', code)
    return code if code >= 0 else 128 - code


if __name__ == '__main__':
    sys.exit(main())
