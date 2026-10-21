#!/usr/bin/env python3
"""Check each manifest's registration in an isolated development Compose farm.

Registration is one acceptance gate, not proof that every feature works. Requires
the source-overlay Compose configuration and an initialized disposable farm.
"""
import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
import hashlib
import json
import os
from pathlib import Path
import subprocess
import time
import uuid

ROOT = Path(__file__).resolve().parent.parent


def source_fingerprint(folder):
    source = hashlib.sha256()
    # Include implementation, assets and bundled PHP dependencies. A matching
    # manifest alone cannot justify reusing evidence after a source edit.
    paths = []
    for directory, folders, names in os.walk(folder):
        folders[:] = [name for name in folders if name not in
                      {'.git', 'node_modules', '__pycache__', '.phpunit.cache'}]
        paths.extend(Path(directory) / name for name in names)
    for path in sorted(paths):
        if path.is_file():
            source.update(path.relative_to(folder).as_posix().encode() + b'\0')
            source.update(b'link:' + os.readlink(path).encode() if path.is_symlink() else path.read_bytes())
            source.update(b'\0')
    return source.hexdigest()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--env-file', default='.env.upgrade')
    parser.add_argument('--report-directory', type=Path, required=True)
    parser.add_argument('--manifest', action='append', help='Relative to mediawiki/; repeat to select components')
    parser.add_argument('--workers', type=int, default=2, choices=range(1, 5))
    parser.add_argument('--timeout', type=int, default=120)
    parser.add_argument('--resume', action='store_true')
    parser.add_argument('--gate', choices=['registration', 'skin-render'], default='registration')
    parser.add_argument('--immutable', action='store_true', help='Test built images without development source mounts')
    args = parser.parse_args()
    directory = args.report_directory.resolve()
    directory.mkdir(parents=True, exist_ok=True)
    manifests = sorted(set((ROOT / 'mediawiki/extensions').glob('*/extension*.json')) |
                       set((ROOT / 'mediawiki/extensions').glob('*/*/extension*.json')) |
                       {ROOT / 'mediawiki/extensions/SocialProfile/SocialProfile.php',
                        ROOT / 'mediawiki/extensions/Validator/Validator.php'} |
                       set((ROOT / 'mediawiki/skins').glob('*/skin.json')))
    if args.gate == 'skin-render':
        manifests = [file for file in manifests if file.name == 'skin.json']
    if args.manifest:
        wanted = set(args.manifest)
        manifests = [file for file in manifests if file.relative_to(ROOT / 'mediawiki').as_posix() in wanted]
        if len(manifests) != len(wanted):
            parser.error('One or more selected manifests do not exist')
    compose = ['docker', 'compose', '--env-file', args.env_file, '-f', 'compose.yaml']
    if not args.immutable:
        compose += ['-f', 'compose.development.yaml', '-f', 'docker/tests/upgrade-source.compose.yaml']
    else:
        compose += ['-f', 'docker/tests/upgrade-isolated.compose.yaml']
    configuration = json.loads(subprocess.check_output(compose + ['--profile', 'tools', 'config', '--format', 'json'], cwd=ROOT))
    image_name = configuration['services']['init']['image']
    image_id = subprocess.check_output(['docker', 'image', 'inspect', image_name, '--format', '{{.Id}}'],
                                       cwd=ROOT, text=True).strip()
    report_file = directory / 'results.json'
    results = {}
    previous_report = json.loads(report_file.read_text()) if args.resume and report_file.exists() else None
    farm_sources = ['docker/mediawiki/bootstrap.php', 'docker/mediawiki/runtime.php',
                    'mediawiki/LocalSettings.php', 'mediawiki/SkyWikiFunctions.php',
                    'mediawiki/FarmSiteConfiguration.php',
                    'mediawiki/ConfigSettings.php', 'mediawiki/GlobalSettings.php',
                    'mediawiki/GlobalExtensions.php', 'mediawiki/ManageWikiExtensions.php',
                    'mediawiki/ManageWikiSettings.php', 'mediawiki/ManageWikiNamespaces.php',
                    'docker/tests/catalogue-settings.php', 'docker/tests/catalogue-registration.php',
                    'docker/tests/catalogue-classes.php',
                    'docker/tests/catalogue-skin-render.php', 'scripts/check-catalogue.py']
    farm_digest = hashlib.sha256()
    for name in farm_sources:
        farm_digest.update(name.encode() + b'\0' + (ROOT / name).read_bytes() + b'\0')
    environment = {
        'gate': args.gate,
        'mode': 'immutable-image' if args.immutable else 'isolated-development-source-overlay',
        'image': image_name,
        'image_id': image_id,
        'composer_lock_sha256': hashlib.sha256((ROOT / 'mediawiki/composer.lock').read_bytes()).hexdigest(),
        'upgrade_targets_sha256': hashlib.sha256((ROOT / 'docs/upgrade-targets.json').read_bytes()).hexdigest(),
        'farm_sources_sha256': farm_digest.hexdigest(),
    }
    if previous_report and previous_report.get('environment') == environment:
        results = {entry['manifest']: entry for entry in previous_report['components']}

    def save():
        report = {'schema_version': 1, 'gate': args.gate, 'environment': environment,
                  'expected_manifests': len(manifests), 'components': sorted(results.values(), key=lambda entry: entry['manifest'])}
        temporary = directory / 'results.tmp'
        temporary.write_text(json.dumps(report, indent=2) + '\n')
        temporary.replace(report_file)

    def check(file):
        relative = file.relative_to(ROOT / 'mediawiki').as_posix()
        digest = hashlib.sha256(file.read_bytes()).hexdigest()
        source_digest = source_fingerprint(file.parent)
        container = 'farm-catalogue-' + uuid.uuid4().hex[:16]
        log = directory / (relative.replace('/', '_') + '.log')
        script = 'catalogue-registration.php' if args.gate == 'registration' else 'catalogue-skin-render.php'
        command = compose + ['run', '--rm', '--no-deps', '--name', container, '--user', 'www-data',
                             '-e', 'MW_CONFIG_FILE=/opt/catalogue-settings.php',
                             '-e', 'MW_CATALOGUE_COMPONENT=' + relative,
                             '-v', str(ROOT / 'docker/tests/catalogue-settings.php') + ':/opt/catalogue-settings.php:ro',
                             '-v', str(ROOT / 'docker/tests/catalogue-classes.php') + ':/opt/catalogue-classes.php:ro',
                             '-v', str(ROOT / 'docker/tests' / script) + ':/opt/' + script + ':ro',
                             'init', 'php', 'maintenance/run.php', '/opt/' + script, '--wiki=testwiki']
        started = time.monotonic()
        status = 'failed'
        with log.open('w') as output:
            process = subprocess.Popen(command, cwd=ROOT, stdout=output, stderr=subprocess.STDOUT)
            try:
                returncode = process.wait(timeout=args.timeout)
            except subprocess.TimeoutExpired:
                subprocess.run(['docker', 'stop', '--time', '5', container], stdout=output, stderr=subprocess.STDOUT, timeout=20)
                process.kill()
                process.wait()
                returncode = -1
                status = 'timeout'
        evidence = None
        for line in log.read_text(errors='replace').splitlines():
            try:
                candidate = json.loads(line)
                if isinstance(candidate, dict) and candidate.get('manifest') == relative:
                    evidence = candidate
            except ValueError:
                continue
        marker = 'registration' if args.gate == 'registration' else 'skin_render'
        if returncode == 0 and evidence and evidence.get(marker) == 'passed':
            status = 'passed'
        return {'manifest': relative, 'manifest_sha256': digest, 'source_sha256': source_digest, 'status': status,
                'returncode': returncode, 'seconds': round(time.monotonic() - started, 2),
                'log': log.name, 'evidence': evidence}

    pending = []
    for file in manifests:
        relative = file.relative_to(ROOT / 'mediawiki').as_posix()
        previous = results.get(relative)
        digest = hashlib.sha256(file.read_bytes()).hexdigest()
        if not previous or previous['status'] != 'passed' or previous['manifest_sha256'] != digest or previous.get('source_sha256') != source_fingerprint(file.parent):
            pending.append(file)
    with ThreadPoolExecutor(max_workers=args.workers) as pool:
        for future in as_completed([pool.submit(check, file) for file in pending]):
            result = future.result()
            results[result['manifest']] = result
            save()
            print(result['status'], result['manifest'], flush=True)
    save()
    failed = [entry['manifest'] for entry in results.values() if entry['status'] != 'passed']
    print(json.dumps({'checked': len(results), 'failed': failed, 'report': str(report_file)}))
    return 1 if failed else 0


if __name__ == '__main__':
    raise SystemExit(main())
