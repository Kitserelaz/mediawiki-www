#!/usr/bin/env python3
"""Build once with persistent layer caches; share pinned images with all test jobs."""
import argparse
import json
import os
from pathlib import Path
import subprocess

SERVICES = {'farm': ('php', 'web'), 'counter': ('counter',)}


def run(args):
    subprocess.run(args, check=True)


def build(group, directory):
    directory.mkdir(parents=True, exist_ok=True)
    registry = os.environ['GITHUB_REPOSITORY'].lower()
    trusted = os.environ['GITHUB_EVENT_NAME'] != 'pull_request' and os.environ['GITHUB_REF'] == 'refs/heads/main'
    manifest = {}
    for service in SERVICES[group]:
        reference = 'ghcr.io/' + registry + '-' + service + ':ci-' + os.environ['GITHUB_SHA']
        metadata = directory / (service + '-metadata.json')
        scope = 'cloudwiki-' + group + '-amd64'
        args = ['docker', 'buildx', 'build', '--platform', 'linux/amd64', '--build-arg', 'SOURCE_REVISION=' + os.environ['GITHUB_SHA'],
                '--cache-from', 'type=gha,version=2,scope=' + scope, '--cache-to', 'type=gha,version=2,scope=' + scope + ',mode=max',
                '--metadata-file', str(metadata)]
        if service == 'counter':
            args += ['-f', 'Moe-Counter/Dockerfile']
        else:
            args += ['-f', 'docker/mediawiki/Dockerfile', '--target', service]
        if trusted:
            args += ['--push', '-t', reference]
        else:
            args += ['--output', 'type=docker,dest=' + str(directory / (service + '.tar')), '-t', 'cloudwiki-' + service + ':local']
        args += ['Moe-Counter' if service == 'counter' else '.']
        run(['python3', 'scripts/ci-build-progress.py', '--job', 'build (' + group + ')', '--phase', service + ' image', '--', *args])
        manifest[service] = {'image': reference.split(':ci-')[0] + '@' + json.loads(metadata.read_text())['containerimage.digest']} if trusted else {'archive': service + '.tar'}
        metadata.unlink()
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
        images = {}
        for path in args.directory.glob('*.json'):
            images.update({name: value['image'] for name, value in json.loads(path.read_text()).items()})
        assert set(images) == {'php', 'web', 'counter'}
        Path('release.json').write_text(json.dumps({'commit': os.environ['GITHUB_SHA'], 'images': images}) + '\n')


if __name__ == '__main__':
    main()
