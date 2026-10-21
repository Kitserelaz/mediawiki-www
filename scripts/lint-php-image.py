#!/usr/bin/env python3
"""Run inside the image; verify narrowly documented version guards before linting."""
import hashlib
import json
from pathlib import Path
import subprocess

root = Path('/var/www')
version = int(subprocess.check_output(['php', '-r', 'echo PHP_VERSION_ID;']))
exceptions = json.loads(Path('/opt/php-syntax-exceptions.json').read_text())['exceptions']
skipped = set()
for exception in exceptions:
    if version >= exception['minimum_php_version_id']:
        continue
    source, guard = root / exception['path'], root / exception['guard']
    for path, expected in [(source, exception['sha256']), (guard, exception['guard_sha256'])]:
        if hashlib.sha256(path.read_bytes()).hexdigest() != expected:
            raise RuntimeError('Version-guarded syntax exemption changed; review required: ' + str(path))
    skipped.add(source)
    print('Version-guarded skip:', source, exception['reason'], flush=True)
files = sorted(path for path in (root / 'mediawiki').rglob('*.php') if path.is_file() and path not in skipped)
for start in range(0, len(files), 150):
    subprocess.run(['php', '-l', *map(str, files[start:start + 150])], check=True)
print(f'PHP {version}: syntax passed for {len(files)} files; {len(skipped)} verified version-guarded skips.')
