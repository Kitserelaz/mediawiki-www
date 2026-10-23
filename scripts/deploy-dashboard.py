#!/usr/bin/env python3
"""Restricted dashboard-only deployment; never changes the application checkout."""
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import time

FILES = {'docker/diff/public/index.html': 'public/index.html',
         'docker/diff/public/app.js': 'public/app.js',
         'docker/diff/public/style.css': 'public/style.css',
         'scripts/update-diff-dashboard.py': 'update.py'}


def validate(payload):
    if not isinstance(payload, dict) or set(payload) != {'commit'} or not isinstance(payload['commit'], str) or not re.fullmatch('[a-f0-9]{40}', payload['commit']):
        raise ValueError('Only an exact dashboard commit is accepted')
    return payload['commit']


def deploy(commit, root, state, guard):
    runner = guard.Runner(root)
    guard.check_local_config(root, state, runner)
    if runner.run(['git', 'config', '--get', 'remote.origin.url'], capture=True) != guard.REPOSITORY:
        raise RuntimeError('Unexpected production Git origin')
    runner.run(['git', 'fetch', '--no-tags', 'origin', 'main'])
    if runner.run(['git', 'rev-parse', 'FETCH_HEAD'], capture=True) != commit:
        print('Skipping a superseded dashboard deployment')
        return
    # Only fixed public dashboard sources can be exported. No extraction paths,
    # executable installer, shell command, or configuration is supplied by CI.
    contents = {source: subprocess.check_output(['git', 'show', commit + ':' + source], cwd=root)
                for source in FILES}
    compile(contents['scripts/update-diff-dashboard.py'], 'dashboard-updater', 'exec')
    directory = state / 'diff'
    backup = state / 'dashboard-releases' / (commit + '-' + str(time.time_ns()))
    backup.mkdir(parents=True, mode=0o700)
    for source, relative in FILES.items():
        target = directory / relative
        shutil.copy2(target, backup / target.name)
        fd, name = tempfile.mkstemp(dir=target.parent, prefix='.' + target.name)
        try:
            with os.fdopen(fd, 'wb') as out:
                out.write(contents[source])
            os.chmod(name, 0o644 if relative.startswith('public/') else 0o600)
            os.replace(name, target)
        finally:
            if os.path.exists(name):
                os.unlink(name)
    guard.atomic_private_write(directory / 'dashboard-release.json', json.dumps({'commit': commit, 'installed_at': time.time()}) + '\n')
    print('Dashboard deployed: ' + commit)


def main():
    if os.geteuid() != 0:
        raise RuntimeError('Run through the restricted production SSH entry point')
    body = sys.stdin.read(1025)
    if len(body) > 1024:
        raise ValueError('Dashboard request is too large')
    commit = validate(json.loads(body))
    state = Path('/var/lib/cloudwiki')
    spec = importlib.util.spec_from_file_location('guard', '/usr/local/libexec/cloudwiki-deploy-production.py')
    guard = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(guard)
    with (state / 'deploy.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        deploy(commit, Path('/var/www'), state, guard)
    subprocess.run(['systemctl', 'start', '--no-block', 'cloudwiki-diff.service'], check=True)


if __name__ == '__main__':
    try:
        main()
    except Exception:
        # Fixed errors only; never include command output or operator config.
        print('Dashboard deployment failed; inspect the private server log', file=sys.stderr)
        sys.exit(1)
