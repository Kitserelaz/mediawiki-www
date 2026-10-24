#!/usr/bin/env python3
"""Build once with persistent layer caches; share pinned images with all test jobs."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
import os
from pathlib import Path
import subprocess
import re
import tempfile

SERVICES = {'farm': ('php', 'web'), 'counter': ('counter',)}
INPUT_LABEL = 'us.cloudwiki.build-inputs'


def run(args, *, timeout=None):
    subprocess.run(args, check=True, timeout=timeout)


def input_key(service):
    # Counter has its own Docker context. Farm fingerprints the entire repository
    # conservatively, so future Dockerfile COPY inputs cannot escape this check.
    paths = ['Moe-Counter', 'scripts/ci-images.py'] if service == 'counter' else ['.']
    subprocess.run(['git', 'diff', '--exit-code', '--quiet', 'HEAD', '--', *paths], check=True)
    tree = subprocess.check_output(['git', 'ls-tree', '-r', '-z', 'HEAD', '--', *paths])
    return hashlib.sha256(b'cloudwiki-inputs-v1-linux-amd64\0' + service.encode() + b'\0' + tree).hexdigest()


def reusable_image(repository, key):
    """Only release promotion creates these tags, after all required tests pass."""
    reference = repository + ':tested-inputs-' + key
    try:
        manifest = json.loads(subprocess.check_output(
            ['docker', 'buildx', 'imagetools', 'inspect', reference, '--format', '{{json .Manifest}}'],
            text=True, stderr=subprocess.DEVNULL, timeout=30))
        digest = manifest['digest']
        if not re.fullmatch(r'sha256:[0-9a-f]{64}', digest):
            return None
        pinned = repository + '@' + digest
        image = json.loads(subprocess.check_output(
            ['docker', 'buildx', 'imagetools', 'inspect', pinned, '--format', '{{json .Image}}'],
            text=True, stderr=subprocess.DEVNULL, timeout=30))
        # OCI indexes (including builds carrying attestations) use platform keys.
        image = image.get('linux/amd64', image)
        if (image['architecture'] == 'amd64' and image['os'] == 'linux'
                and image['config']['Labels'].get(INPUT_LABEL) == key):
            return pinned
    except (subprocess.SubprocessError, OSError, ValueError, KeyError, TypeError, AttributeError):
        pass  # Missing, invalid or unavailable cache always falls back to a full build.
    return None


def build(group, directory):
    directory.mkdir(parents=True, exist_ok=True)
    registry = os.environ['GITHUB_REPOSITORY'].lower()
    trusted = os.environ['GITHUB_EVENT_NAME'] != 'pull_request' and os.environ['GITHUB_REF'] == 'refs/heads/main'
    def build_service(service):
        reference = 'ghcr.io/' + registry + '-' + service + ':ci-' + os.environ['GITHUB_SHA']
        repository = reference.split(':ci-')[0]
        key = input_key(service)
        metadata = directory / (service + '-metadata.json')
        scope = 'cloudwiki-' + service + '-amd64'
        args = ['docker', 'buildx', 'build', '--platform', 'linux/amd64', '--build-arg', 'SOURCE_REVISION=' + os.environ['GITHUB_SHA'],
                '--cache-from', 'type=gha,version=2,scope=' + scope, '--cache-to', 'type=gha,version=2,scope=' + scope + ',mode=max',
                '--metadata-file', str(metadata), '--label', INPUT_LABEL + '=' + key]
        if service == 'counter':
            args += ['-f', 'Moe-Counter/Dockerfile']
        else:
            args += ['-f', 'docker/mediawiki/Dockerfile', '--target', service]
        if trusted:
            args += ['--push', '-t', reference]
        else:
            args += ['--output', 'type=docker,dest=' + str(directory / (service + '.tar')), '-t', 'cloudwiki-' + service + ':local']
        previous = reusable_image(repository, key) if trusted else None
        with tempfile.TemporaryDirectory(prefix='cloudwiki-image-') as context:
            if previous:
                # Reuse all tested filesystem layers, but preserve production's
                # exact candidate revision-label check with a new image config.
                dockerfile = Path(context) / 'Dockerfile'
                dockerfile.write_text('FROM ' + previous + '\nLABEL org.opencontainers.image.revision="'
                                      + os.environ['GITHUB_SHA'] + '"\n')
                args = ['docker', 'buildx', 'build', '--platform', 'linux/amd64',
                        '--metadata-file', str(metadata), '--push', '-t', reference,
                        '--label', INPUT_LABEL + '=' + key, '-f', str(dockerfile), context]
            else:
                args += ['Moe-Counter' if service == 'counter' else '.']
            run(['python3', 'scripts/ci-build-progress.py', '--job', 'build (' + group + ')',
                 '--phase', service + (' image (tested layers)' if previous else ' image'), '--', *args])
        item = {'image': repository + '@' + json.loads(metadata.read_text())['containerimage.digest'],
                'input_key': key} if trusted else {'archive': service + '.tar'}
        metadata.unlink()
        return service, item

    # One BuildKit builder shares common PHP stages across simultaneous solves.
    with ThreadPoolExecutor(max_workers=len(SERVICES[group])) as executor:
        manifest = dict(executor.map(build_service, SERVICES[group]))
    (directory / (group + '.json')).write_text(json.dumps(manifest) + '\n')


def load(directory, group):
    wanted = SERVICES[group] if group in SERVICES else ('php', 'web', 'counter')
    descriptors = {}
    for file in directory.glob('*.json'):
        descriptors.update(json.loads(file.read_text()))
    for service in wanted:
        item = descriptors[service]
        if 'image' in item:
            run(['docker', 'pull', item['image']])
            run(['docker', 'tag', item['image'], 'cloudwiki-' + service + ':local'])
        else:
            run(['docker', 'load', '--input', str(directory / item['archive'])])


def release(directory):
    if os.environ.get('GITHUB_EVENT_NAME') != 'push' or os.environ.get('GITHUB_REF') != 'refs/heads/main':
        raise ValueError('Only a tested main push can publish a release')
    descriptors = {}
    for path in directory.glob('*.json'):
        descriptors.update(json.loads(path.read_text()))
    assert set(descriptors) == {'php', 'web', 'counter'}
    registry = 'ghcr.io/' + os.environ['GITHUB_REPOSITORY'].lower() + '-'
    for service, item in descriptors.items():
        repository = registry + service
        if not re.fullmatch(re.escape(repository) + r'@sha256:[0-9a-f]{64}', item['image']):
            raise ValueError('Unexpected release image')
        if not re.fullmatch('[0-9a-f]{64}', item['input_key']):
            raise ValueError('Invalid image input key')
    # A cache publication failure must not discard a tested release. Consumers
    # validate the input label and pin the discovered manifest before reuse.
    def promote(entry):
        service, item = entry
        try:
            run(['docker', 'buildx', 'imagetools', 'create', '--tag',
                 registry + service + ':tested-inputs-' + item['input_key'], item['image']], timeout=30)
        except subprocess.SubprocessError:
            print('::warning::Tested image cache promotion failed for ' + service)
    with ThreadPoolExecutor(max_workers=3) as executor:
        list(executor.map(promote, descriptors.items()))
    Path('release.json').write_text(json.dumps({'commit': os.environ['GITHUB_SHA'],
        'images': {name: item['image'] for name, item in descriptors.items()}}) + '\n')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['build', 'load', 'release'])
    parser.add_argument('--group', choices=['farm', 'counter', 'all'], default='all')
    parser.add_argument('--directory', type=Path, required=True)
    args = parser.parse_args()
    if args.action == 'build':
        build(args.group, args.directory)
    elif args.action == 'load':
        load(args.directory, args.group)
    else:
        release(args.directory)


if __name__ == '__main__':
    main()
