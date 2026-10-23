#!/usr/bin/env python3
"""Install dashboard assets. Proxy configuration changes require operator review."""
import argparse
import os
from pathlib import Path
import shutil
import subprocess
import tempfile


def copy(source, target, mode=0o600):
    fd, name = tempfile.mkstemp(dir=target.parent, prefix='.' + target.name)
    os.close(fd)
    try:
        shutil.copyfile(source, name)
        os.chmod(name, mode)
        os.replace(name, target)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def install(source, destination, infrastructure=False):
    destination.mkdir(mode=0o700, parents=True, exist_ok=True)
    public = destination / 'public'
    public.mkdir(mode=0o755, exist_ok=True)
    for name in ('index.html', 'style.css', 'app.js'):
        copy(source / 'docker/diff/public' / name, public / name, 0o644)
    copy(source / 'scripts/update-diff-dashboard.py', destination / 'update.py')
    if infrastructure:
        for name in ('Caddyfile', 'compose.yaml'):
            copy(source / 'docker/diff' / name, destination / name)
        for name in ('cloudwiki-diff.service', 'cloudwiki-diff.timer'):
            copy(source / 'docker/diff' / name, Path('/etc/systemd/system') / name, 0o644)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, default=Path('/var/www'))
    parser.add_argument('--infrastructure', action='store_true', help='Install operator-reviewed proxy and timer configuration')
    args = parser.parse_args()
    if os.geteuid() != 0:
        parser.error('Run locally on the production server through sudo')
    install(args.source, Path('/var/lib/cloudwiki/diff'), args.infrastructure)
    if args.infrastructure:
        subprocess.run(['systemctl', 'daemon-reload'], check=True)
        subprocess.run(['systemctl', 'enable', '--now', 'cloudwiki-diff.timer'], check=True)
    subprocess.run(['systemctl', 'start', '--no-block', 'cloudwiki-diff.service'], check=True)


if __name__ == '__main__':
    main()
