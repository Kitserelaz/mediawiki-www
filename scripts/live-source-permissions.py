#!/usr/bin/env python3
"""Grant the isolated editor UID source access after Git replaces file inodes."""
from pathlib import Path
import subprocess


def main():
    source = Path('/var/www/mediawiki')
    subprocess.run(['setfacl', '-R', '-m', 'u:10001:rwX', str(source)], check=True)
    directories = [str(source)] + [str(p) for p in source.rglob('*') if p.is_dir() and not p.is_symlink()]
    for offset in range(0, len(directories), 200):
        subprocess.run(['setfacl', '-m', 'd:u:10001:rwx', *directories[offset:offset + 200]], check=True)


if __name__ == '__main__':
    main()
