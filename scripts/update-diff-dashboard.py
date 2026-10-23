#!/usr/bin/env python3
"""Publish allowlisted, public release information; never publish config values."""
import fcntl
import importlib.util
import json
import os
import statistics
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime
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
    token = STATE / 'diff/github-token'
    if token.is_file():
        req.add_header('Authorization', 'Bearer ' + token.read_text().strip())
    with urllib.request.urlopen(req, timeout=15) as response:
        return json.load(response)


def repository_report(current, previous, old, now, fetch=request):
    key = [current, previous]
    if old.get('key') == key and now - old.get('fetched_at', 0) < 300:
        return old
    try:
        pending = comparison(fetch('/compare/' + current + '...main'))
        result = {'key': key, 'fetched_at': now, 'pending': pending}
        if previous:
            result['released'] = comparison(fetch('/compare/' + previous + '...' + current))
        return result
    except Exception:
        if old.get('key') == key:
            return {**old, 'stale': True}
        return {'key': key, 'fetched_at': 0, 'stale': True}


def seconds(start, end):
    try:
        return max(0, (datetime.fromisoformat(end.replace('Z', '+00:00')) -
                       datetime.fromisoformat(start.replace('Z', '+00:00'))).total_seconds())
    except (ValueError, TypeError, AttributeError):
        return None


def job_report(job):
    fields = ('id', 'name', 'status', 'conclusion', 'started_at', 'completed_at')
    result = {key: job.get(key) for key in fields}
    result['steps'] = [{key: step.get(key) for key in
                       ('number', 'name', 'status', 'conclusion', 'started_at', 'completed_at')}
                      for step in job.get('steps', [])]
    return result


def build_activity(checks, run):
    activities = []
    for check in checks:
        if not check.get('name', '').startswith('CloudWiki build: '):
            continue
        try:
            data = json.loads(check.get('output', {}).get('summary', ''))
            if str(data.get('run_id')) != str(run['id']) or data.get('run_attempt') != run.get('run_attempt', 1):
                continue
            activities.append({key: data.get(key) for key in
                               ('job', 'phase', 'started_at', 'heartbeat_at', 'last_output_at', 'status')})
        except (ValueError, TypeError):
            continue
    return activities


def pipeline_report(old, now, fetch=request, interval=60):
    # CI has its own cache: a five-minute source diff must not hide build progress.
    if now - max(old.get('checked_at', 0), old.get('attempted_at', 0)) < interval:
        return old
    result = dict(old)
    try:
        sha = fetch('/git/ref/heads/main')['object']['sha']
        runs = fetch('/actions/workflows/docker.yml/runs?branch=main&head_sha=' + sha + '&per_page=1').get('workflow_runs', [])
        run = next((r for r in runs if r.get('head_sha') == sha), None)
        if not run:
            return {'checked_at': now, 'poll_interval': interval, 'head_sha': sha, 'status': 'waiting_for_run', 'jobs': []}
        result = {key: run.get(key) for key in
                  ('id', 'head_sha', 'status', 'conclusion', 'run_attempt', 'created_at', 'run_started_at', 'updated_at')}
        result['checked_at'] = now
        result['poll_interval'] = interval
        result['jobs'] = [job_report(j) for j in fetch('/actions/runs/' + str(run['id']) + '/jobs?filter=latest&per_page=100').get('jobs', [])]
        try:
            checks = fetch('/commits/' + sha + '/check-runs?per_page=100').get('check_runs', [])
            result['activity'] = build_activity(checks, run)
            result['activity_available'] = True
        except Exception:
            result['activity'] = old.get('activity', []) if old.get('id') == run['id'] else []
            result['activity_available'] = False
        # A rolling sample of successful runs, refreshed at most once per hour.
        history = old.get('history', {})
        if now - history.get('checked_at', 0) >= 3600:
            try:
                successful = fetch('/actions/workflows/docker.yml/runs?branch=main&status=success&per_page=5').get('workflow_runs', [])
                samples = {}
                with ThreadPoolExecutor(max_workers=5) as pool:
                    reports = list(pool.map(lambda past: fetch('/actions/runs/' + str(past['id']) + '/jobs?filter=latest&per_page=100'), successful))
                for past_jobs in reports:
                    for job in past_jobs.get('jobs', []):
                        if job.get('conclusion') != 'success':
                            continue
                        for name, value in [(job['name'], seconds(job.get('started_at'), job.get('completed_at')))] + [
                            (job['name'] + ' / ' + step['name'], seconds(step.get('started_at'), step.get('completed_at')))
                            for step in job.get('steps', []) if step.get('conclusion') == 'success']:
                            if value is not None:
                                samples.setdefault(name, []).append(value)
                history = {'checked_at': now, 'durations': {name: {'median': statistics.median(values), 'samples': len(values)} for name, values in samples.items()}}
            except Exception:
                pass
        result['history'] = history
        return result
    except Exception:
        # Never advance the successful check time on a failed GitHub request.
        return {**old, 'stale': True, 'attempted_at': now}


def deployment_report(state):
    try:
        data = json.loads((state / 'deployment-progress.json').read_text())
        return {key: data.get(key) for key in ('commit', 'status', 'stage', 'started_at',
                'stage_started_at', 'heartbeat_at', 'updated_at', 'reason')}
    except (OSError, ValueError):
        return None


def main():
    directory = STATE / 'diff'
    spec = importlib.util.spec_from_file_location('deploy_guard', '/usr/local/libexec/cloudwiki-deploy-production.py')
    guard = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(guard)
    # A deployment must not stop CI/heartbeat updates. Reuse the last consistent
    # config/release snapshot while its exclusive lock is held.
    with (STATE / 'deploy.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_SH | fcntl.LOCK_NB)
        except BlockingIOError:
            public = json.loads((directory / 'public/changes.json').read_text())
            release = {'commit': public['deployed_commit'], 'previous_commit': public.get('previous_commit')}
            local, worktree, live = public['config'], public.get('working_tree', {}), public.get('live_editing', False)
            snapshot_at = public.get('snapshot_at', public['generated_at'])
        else:
            initial = directory / 'initial-release.json'
            if not initial.exists():
                commit = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()
                write_json(initial, {'commit': commit, 'previous_commit': None})
            last = STATE / 'last-success.json'
            release = json.loads((last if last.exists() else initial).read_text())
            local, worktree = config_report(guard, ROOT, STATE), working_tree_report(ROOT)
            try:
                resolved = guard.Runner(ROOT).run(['docker', 'compose', '--env-file', str(ROOT / '.env.production'), 'config', '--environment'], capture=True)
                settings = dict(line.split('=', 1) for line in resolved.splitlines() if '=' in line)
            except Exception:
                settings = {}
            live = settings.get('MW_CODE_MODE') == 'bind' and settings.get('MW_BIND_STRATEGY') == 'live'
            if live:
                head = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()
                if head != release['commit']:
                    release = {'commit': head, 'previous_commit': None}
            snapshot_at = time.time()
    cache_path = directory / 'repository-cache.json'
    cache = json.loads(cache_path.read_text()) if cache_path.exists() else {}
    now = time.time()
    cache = repository_report(release['commit'], release.get('previous_commit'), cache, now)
    write_json(cache_path, cache)
    pipeline_path = directory / 'pipeline-cache.json'
    pipeline_cache = json.loads(pipeline_path.read_text()) if pipeline_path.exists() else {}
    pipeline = pipeline_report(pipeline_cache, now, interval=60 if (directory / 'github-token').is_file() else 600)
    write_json(pipeline_path, pipeline)
    public = {'generated_at': now, 'snapshot_at': snapshot_at, 'repository': WEB, 'deployed_commit': release['commit'],
              'previous_commit': release.get('previous_commit'), 'config': local,
              'live_editing': live, 'working_tree': worktree,
              'source_updated_at': cache.get('fetched_at'), 'source_stale': cache.get('stale', False),
              'pending': cache.get('pending'), 'released': cache.get('released'),
              'pipeline': pipeline, 'deployment': deployment_report(STATE)}
    write_json(directory / 'public' / 'changes.json', public, 0o644)


if __name__ == '__main__':
    main()
