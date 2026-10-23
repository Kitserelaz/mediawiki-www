#!/usr/bin/env python3
"""Publish allowlisted, public release information; never publish config values."""
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import urllib.request

REPO = 'Kitserelaz/mediawiki-www'
WEB = 'https://github.com/' + REPO
API = 'https://api.github.com/repos/' + REPO
STATE = Path('/var/lib/cloudwiki')
ROOT = Path('/var/www')


def write_json(path, data, mode=0o600):
    fd, temporary = tempfile.mkstemp(dir=path.parent, prefix='.' + path.name)
    try:
        with os.fdopen(fd, 'w') as out:
            json.dump(data, out)
            out.write('\n')
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def comparison(data):
    """Only public source fields; ignore arbitrary extra upstream metadata."""
    return {
        'total_commits': data.get('total_commits', 0),
        'commits': [{'sha': c['sha'], 'message': c['commit']['message'],
                     'date': c['commit']['committer']['date']} for c in data.get('commits', [])],
        'files': [{key: f[key] for key in ('filename', 'previous_filename', 'status', 'additions',
                                         'deletions', 'patch') if key in f} for f in data.get('files', [])],
    }


def config_report(guard, root, state):
    baseline = state / guard.CONFIG_BASELINE
    if not baseline.exists():
        return {'status': 'unknown', 'files': [], 'message': 'No accepted baseline'}
    expected = json.loads(baseline.read_text())['files']
    actual = {path: guard.file_fingerprint(Path(path)) for path in expected}
    # Avoid parsing edited configuration, including invalid YAML/dotenv.
    if actual == expected:
        try:
            actual = guard.capture_local_config(root, guard.Runner(root))
        except Exception:
            return {'status': 'unknown', 'files': [], 'message': 'Configuration inventory unavailable'}
    files = []
    for path in sorted(expected.keys() | actual.keys()):
        if expected.get(path) == actual.get(path):
            continue
        try:
            label = str(Path(path).relative_to(root))
        except ValueError:
            label = Path(path).name
        files.append({'filename': label, 'status': 'added' if path not in expected else
                      'deleted' if actual.get(path) is None else 'modified'})
    return {'status': 'changed' if files else 'clean', 'files': files}


def working_tree_report(root):
    raw = subprocess.check_output(['git', '-C', str(root), 'status', '--porcelain=v1', '-z',
                                   '--untracked-files=all', '--', 'mediawiki']).decode('utf-8', 'replace')
    entries = iter(raw.split('\0'))
    files = []
    for entry in entries:
        if not entry:
            continue
        code, filename = entry[:2], entry[3:]
        status = ('added' if code == '??' or 'A' in code else 'deleted' if 'D' in code else
                  'renamed' if 'R' in code else 'modified')
        item = {'filename': filename, 'status': status}
        if 'R' in code or 'C' in code:
            next(entries, None)  # Consume the original name; never read file contents.
        files.append(item)
    return {'status': 'changed' if files else 'clean', 'files': files}


def request(endpoint):
    req = urllib.request.Request(API + endpoint, headers={
        'Accept': 'application/vnd.github+json', 'User-Agent': 'cloudwiki-release-dashboard',
        'X-GitHub-Api-Version': '2022-11-28'})
    with urllib.request.urlopen(req, timeout=25) as response:
        return json.load(response)


def repository_report(current, previous, old, now, fetch=request):
    key = [current, previous]
    if old.get('key') == key and now - old.get('fetched_at', 0) < 300:
        return old
    try:
        pending = comparison(fetch('/compare/' + current + '...main'))
        runs = fetch('/actions/runs?branch=main&per_page=1').get('workflow_runs', [])
        run = runs[0] if runs else None
        result = {'key': key, 'fetched_at': now, 'pending': pending,
                  'pipeline': {k: run[k] for k in ('id', 'head_sha', 'status', 'conclusion')}
                  if run else None}
        if previous:
            result['released'] = comparison(fetch('/compare/' + previous + '...' + current))
        return result
    except Exception:
        if old.get('key') == key:
            return {**old, 'stale': True}
        return {'key': key, 'fetched_at': 0, 'stale': True}


def main():
    directory = STATE / 'diff'
    spec = importlib.util.spec_from_file_location('deploy_guard', '/usr/local/libexec/cloudwiki-deploy-production.py')
    guard = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(guard)
    # Read a consistent deployment/config state, without holding deployment up for network calls.
    with (STATE / 'deploy.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_SH | fcntl.LOCK_NB)
        initial = directory / 'initial-release.json'
        if not initial.exists():
            commit = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()
            write_json(initial, {'commit': commit, 'previous_commit': None})
        last = STATE / 'last-success.json'
        release = json.loads((last if last.exists() else initial).read_text())
        local = config_report(guard, ROOT, STATE)
        worktree = working_tree_report(ROOT)
        try:
            resolved = guard.Runner(ROOT).run(['docker', 'compose', '--env-file', str(ROOT / '.env.production'),
                                               'config', '--environment'], capture=True)
            settings = dict(line.split('=', 1) for line in resolved.splitlines() if '=' in line)
        except Exception:
            settings = {}  # Still publish known drift if the edited Compose file is invalid.
        live = settings.get('MW_CODE_MODE') == 'bind' and settings.get('MW_BIND_STRATEGY') == 'live'
        if live:
            head = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()
            if head != release['commit']:
                release = {'commit': head, 'previous_commit': None}
    cache_path = directory / 'repository-cache.json'
    cache = json.loads(cache_path.read_text()) if cache_path.exists() else {}
    now = time.time()
    cache = repository_report(release['commit'], release.get('previous_commit'), cache, now)
    write_json(cache_path, cache)
    public = {'generated_at': now, 'repository': WEB, 'deployed_commit': release['commit'],
              'previous_commit': release.get('previous_commit'), 'config': local,
              'live_editing': live, 'working_tree': worktree,
              'source_updated_at': cache.get('fetched_at'), 'source_stale': cache.get('stale', False),
              'pending': cache.get('pending'), 'released': cache.get('released'),
              'pipeline': cache.get('pipeline')}
    write_json(directory / 'public' / 'changes.json', public, 0o644)


if __name__ == '__main__':
    try:
        main()
    except BlockingIOError:
        pass  # A deployment owns the lock. Keep the previous complete report.
