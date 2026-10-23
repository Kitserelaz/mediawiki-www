#!/usr/bin/env python3
"""Conservative CI routing, including runtime changes still pending in production."""
import json
import os
from pathlib import Path
import re
import subprocess
import urllib.request

DASHBOARD = {'scripts/update-diff-dashboard.py', 'scripts/tests/test_diff_dashboard.py',
             'scripts/tests/dashboard-render.cjs'}


def classify(paths, force=False):
    result = {'runtime': force, 'wiki': force, 'dashboard': False, 'checks': force}
    for path in paths:
        if path.startswith('docker/diff/public/') or path in DASHBOARD:
            result['dashboard'] = result['checks'] = True
        elif path.startswith('docs/') and path.endswith(('.md', '.png', '.svg', '.jpg', '.txt')) or path in {'README.md', 'LICENSE', '.gitignore'}:
            continue
        elif path.startswith('scripts/tests/'):
            result['checks'] = True
        else:
            result['runtime'] = result['checks'] = True
            if not path.startswith('Moe-Counter/'):
                result['wiki'] = True
    return result


def main():
    event = json.loads(Path(os.environ['GITHUB_EVENT_PATH']).read_text())
    kind = os.environ['GITHUB_EVENT_NAME']
    force = kind == 'workflow_dispatch'
    head = os.environ['GITHUB_SHA']
    try:
        if kind == 'pull_request':
            base = event['pull_request']['base']['sha']
            head = event['pull_request']['head']['sha']
        elif kind == 'push' and os.environ['GITHUB_REF'] == 'refs/heads/main':
            # A cancelled older run may contain unshipped runtime changes. Never
            # route solely from the newest push's diff in that case.
            with urllib.request.urlopen('https://diff.cloudwiki.us/changes.json', timeout=15) as response:
                report = json.load(response)
            base = report['deployed_commit']
            if not re.fullmatch('[a-f0-9]{40}', base):
                raise ValueError('Invalid production revision')
        else:
            base = event.get('before')
        if not base or not re.fullmatch('[a-f0-9]{40}', base) or base == '0' * 40:
            raise ValueError('No comparison base')
        subprocess.run(['git', 'merge-base', '--is-ancestor', base, head], check=True, capture_output=True)
        raw = subprocess.check_output(['git', 'diff', '--name-only', '-z', base, head])
        paths = [p for p in raw.decode().split('\0') if p]
        result = classify(paths, force)
    except Exception:
        result = classify([], force=True)  # Missing/stale ancestry must never skip required checks.
    with open(os.environ['GITHUB_OUTPUT'], 'a') as output:
        for name, enabled in result.items():
            output.write(name + '=' + str(enabled).lower() + '\n')
    print('Selected checks: ' + json.dumps(result))


if __name__ == '__main__':
    main()
