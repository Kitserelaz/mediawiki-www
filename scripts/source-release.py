#!/usr/bin/env python3
"""Prepare bind mount points, or export tracked source into a versioned release."""
import argparse
import json
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parent.parent


def prepare_mountpoints(directory):
    for name in ['mediawiki/index.php', 'mediawiki/LocalSettings.php', 'docker/mediawiki/private.php',
                 'docker/mediawiki/entrypoint.sh']:
        if not (directory / name).is_file():
            raise ValueError('Missing required source file: ' + name)
    for name in ['mediawiki/images', 'mediawiki/cache', 'mediawiki/cw_cache', 'mediawiki/docker/mediawiki']:
        (directory / name).mkdir(parents=True, exist_ok=True)
    # A mount placeholder only. The override supplies the runtime secret loader,
    # preserving any existing local PrivateSettings.php without reading it.
    private = directory / 'mediawiki/PrivateSettings.php'
    if not private.exists():
        private.touch()


def export_release(commit, directory, root=ROOT):
    if not re.fullmatch(r'[0-9a-f]{40}', commit) or directory.name != commit:
        raise ValueError('Release directory must be named for a full commit SHA')
    if directory.exists():
        if json.loads((directory / 'release.json').read_text()).get('commit') != commit:
            raise ValueError('Existing release directory does not match its commit')
        return
    directory.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='.preparing-', dir=directory.parent) as temporary:
        staging = Path(temporary).resolve()
        process = subprocess.Popen(['git', 'archive', '--format=tar', commit, 'mediawiki', 'docker/mediawiki'],
                                   cwd=root, stdout=subprocess.PIPE)
        try:
            with tarfile.open(fileobj=process.stdout, mode='r|') as archive:
                for member in archive:
                    target = (staging / member.name).resolve()
                    if not target.is_relative_to(staging) or not (member.isfile() or member.isdir()):
                        raise ValueError('Unsafe entry in source archive')
                    archive.extract(member, staging)
            if process.wait() != 0:
                raise RuntimeError('Could not export release source')
        finally:
            process.stdout.close()
            if process.poll() is None:
                process.kill()
                process.wait()
        prepare_mountpoints(staging)
        (staging / 'release.json').write_text(json.dumps({'commit': commit}) + '\n')
        staging.chmod(0o755)
        staging.rename(directory)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--directory', type=Path, default=ROOT)
    parser.add_argument('--commit')
    parser.add_argument('--repository', type=Path, default=ROOT)
    args = parser.parse_args()
    directory = args.directory.resolve()
    if args.commit:
        export_release(args.commit, directory, args.repository.resolve())
    else:
        prepare_mountpoints(directory)
    print('Source mounts prepared: ' + str(directory))


if __name__ == '__main__':
    main()
