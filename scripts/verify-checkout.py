#!/usr/bin/env python3
"""Fail early when a checkout cannot supply the complete pinned farm sources."""
import json
import hashlib
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parent.parent


def main():
    entries = subprocess.check_output(['git', 'ls-files', '--stage', '-z'], cwd=ROOT).split(b'\0')
    links = [entry.split(b'\t', 1)[1].decode() for entry in entries if entry.startswith(b'160000 ')]
    if links:
        raise RuntimeError('Unreconstructable Git links remain: ' + ', '.join(links))
    indexed = {entry.split(b'\t', 1)[1].decode() for entry in entries if b'\t' in entry}
    case_paths = {}
    for name in sorted(indexed):
        previous = case_paths.setdefault(name.casefold(), name)
        if previous != name:
            raise RuntimeError('Case-colliding tracked paths: ' + previous + ' / ' + name)
    for name in indexed:
        file = Path(name)
        if file.name == '.env' or (file.name.startswith('.env.') and not
                file.name.endswith(('.example', '.sample'))):
            raise RuntimeError('Local environment file is tracked: ' + name)
        if name.startswith(('docker/secrets/', 'docker/secrets-')) and file.name != '.gitkeep':
            raise RuntimeError('Local credential file is tracked: ' + name)
    baseline = json.loads((ROOT / 'docs/upgrade-catalogue-baseline.json').read_text())
    for component in baseline['components']:
        folder = ROOT / component['path']
        manifest = folder / ('skin.json' if component['type'] == 'skins' else 'extension.json')
        if not manifest.is_file():
            raise RuntimeError('Preserved catalogue manifest is missing: ' + str(manifest))
        if manifest.relative_to(ROOT).as_posix() not in indexed:
            raise RuntimeError('Catalogue source is absent from the index: ' + str(manifest))
    targets = json.loads((ROOT / 'docs/upgrade-targets.json').read_text())
    version = re.search(r"define\( 'MW_VERSION', '([^']+)'", (ROOT / 'mediawiki/includes/Defines.php').read_text())[1]
    if version != targets['core']['version']:
        raise RuntimeError('Core source differs from the pinned target')
    for name in ['mediawiki/vendor/autoload.php', 'mediawiki/composer.lock',
                 'mediawiki/FarmSiteConfiguration.php',
                 'mediawiki/extensions/CreateWiki/extension.json',
                 'mediawiki/extensions/ManageWiki/extension.json']:
        if name not in indexed or not (ROOT / name).is_file():
            raise RuntimeError('Required build input is missing: ' + name)
    for report in (ROOT / 'docs').glob('upgrade-*-source.json'):
        for patch in json.loads(report.read_text()).get('vendor_patches', []):
            path = ROOT / patch['path']
            if hashlib.sha256(path.read_bytes()).hexdigest() != patch['patched_sha256']:
                raise RuntimeError('Required vendor compatibility patch is missing: ' + patch['path'])
    supplied = json.loads((ROOT / 'docs/upgrade-vendored-build-inputs.json').read_text())
    for entry in supplied['files']:
        name = entry['path']
        if name not in indexed or not (ROOT / name).is_file():
            raise RuntimeError('Required vendored build input is not tracked: ' + name)
        if hashlib.sha256((ROOT / name).read_bytes()).hexdigest() != entry['sha256']:
            raise RuntimeError('Vendored build input changed; refresh its provenance: ' + name)
    for pattern in ['extensions/*/vendor/autoload.php', 'skins/*/vendor/autoload.php']:
        for path in (ROOT / 'mediawiki').glob(pattern):
            if path.relative_to(ROOT).as_posix() not in indexed:
                raise RuntimeError('Supplied component dependencies are not tracked: ' + str(path))
    # A case-insensitive host can conceal a broken Linux PSR-4 checkout.
    autoload = (ROOT / 'mediawiki/autoload.php').read_text()
    for path in re.findall(r"__DIR__ \. '(/includes/[^']+\.php)'", autoload):
        name = 'mediawiki' + path
        if name not in indexed:
            raise RuntimeError('Core autoloader path is not indexed with exact case: ' + name)
    print(f'Checkout supplies core {version}, {len(baseline["components"])} preserved catalogue entries, and ordinary tracked dependencies.')


if __name__ == '__main__':
    main()
