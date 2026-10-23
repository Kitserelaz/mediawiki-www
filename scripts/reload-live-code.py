#!/usr/bin/env python3
"""Consume a fixed reload request. No supplied command or argument is executed."""
import fcntl
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import time

STATE = Path('/var/lib/cloudwiki')
SERVICES = ('php', 'worker', 'provisioner', 'jobchron', 'scheduler')


def consume_request(path):
    try:
        mode = path.lstat().st_mode
    except FileNotFoundError:
        return False
    path.unlink()  # Never follow a symlink or read any editor-supplied content.
    return stat.S_ISREG(mode)


def main():
    control = STATE / 'editor/control'
    with (STATE / 'deploy.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if not consume_request(control / 'reload'):
            return
        # A compromised editor cannot request a different service, path or command.
        result = subprocess.run(['docker', 'compose', '--env-file', '/var/www/.env.production',
                                 'restart', *SERVICES], cwd='/var/www', capture_output=True)
        # Keep raw command output private; the editor only receives a fixed status.
        fd, temporary = tempfile.mkstemp(dir=STATE / 'editor', prefix='.reload-status-')
        with os.fdopen(fd, 'w') as out:
            json.dump({'completed_at': time.time(), 'status': 'reloaded' if result.returncode == 0 else 'failed'}, out)
        os.chmod(temporary, 0o644)
        os.replace(temporary, control / 'status.json')
        if result.returncode:
            raise RuntimeError('Runtime reload failed; inspect service health locally')


if __name__ == '__main__':
    main()
