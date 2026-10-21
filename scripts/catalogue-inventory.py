#!/usr/bin/env python3
"""Capture component provenance and source fingerprints without reading secrets.

The initial capture is immutable. Validation results belong in a separate report;
a manifest's minimum version alone is not proof of runtime compatibility.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parent.parent
EXCLUDED = {'.git', 'vendor', 'node_modules', '.phpunit.cache', '__pycache__'}


def git(path, *args):
    result = subprocess.run(['git', '-C', str(path), *args], text=True,
                            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    return result.stdout.strip() if result.returncode == 0 else None


def fingerprint(path):
    digest = hashlib.sha256()
    count = 0
    for folder, dirs, files in os.walk(path):
        dirs[:] = sorted(d for d in dirs if d not in EXCLUDED)
        for name in sorted(files):
            file = Path(folder) / name
            if name in {'.DS_Store', '.phpunit.result.cache'}:
                continue
            relative = file.relative_to(path).as_posix()
            digest.update(relative.encode() + b'\0')
            if file.is_symlink():
                digest.update(b'link:' + os.readlink(file).encode())
            else:
                with file.open('rb') as stream:
                    for chunk in iter(lambda: stream.read(1024 * 1024), b''):
                        digest.update(chunk)
            digest.update(b'\0')
            count += 1
    return {'sha256': digest.hexdigest(), 'files': count}


def capture(supplemental_only=False):
    components = []
    for kind, manifest in [('extensions', 'extension.json'), ('skins', 'skin.json')]:
        parent = ROOT / 'mediawiki' / kind
        if supplemental_only:
            paths = [path for path in sorted(parent.iterdir()) if path.is_dir() and not (path / manifest).exists()]
        else:
            paths = [file.parent for file in sorted(parent.glob('*/' + manifest))]
        for path in paths:
            file = path / manifest
            data = json.loads(file.read_text()) if file.exists() else {}
            composer = path / 'composer.json'
            entry = {
                'path': path.relative_to(ROOT).as_posix(),
                'name': data.get('name', path.name), 'type': kind,
                'version': data.get('version'), 'upstream_url': data.get('url'),
                'requires': data.get('requires', {}),
                'composer_require': json.loads(composer.read_text()).get('require', {}) if composer.exists() else {},
                'source': fingerprint(path),
            }
            if not file.exists():
                entry['nonstandard_manifests'] = {
                    file.name: json.loads(file.read_text())
                    for file in sorted(path.glob('extension*.json'))
                }
                entry['legacy_entrypoint'] = (path / (path.name + '.php')).exists()
            # Do not accidentally report the enclosing farm as a component repo.
            if (path / '.git').exists():
                entry['git'] = {
                    'commit': git(path, 'rev-parse', 'HEAD'),
                    'origin': git(path, 'remote', 'get-url', 'origin'),
                    'changes': git(path, 'status', '--porcelain=v1', '--untracked-files=normal'),
                }
            components.append(entry)
    return {'schema_version': 1, 'components': components}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('output', type=Path)
    parser.add_argument('--supplemental-only', action='store_true', help='Capture legacy/support directories and split manifests omitted by standard inventory')
    args = parser.parse_args()
    report = capture(args.supplemental_only)
    with args.output.open('x') as stream:
        json.dump(report, stream, indent=2)
        stream.write('\n')
    print(f"Recorded {len(report['components'])} components in {args.output}")


if __name__ == '__main__':
    main()
