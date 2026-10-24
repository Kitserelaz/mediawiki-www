#!/usr/bin/env python3
"""Development provisioner source: seed once, publish watched edits between jobs."""
import fcntl
import os
from pathlib import Path
import shutil
import signal
import stat
import subprocess
import sys
import uuid

STAGE = Path('/tmp/farm-source')
LOCK = Path('/tmp/farm-source.lock')
GATE = Path('/tmp/farm-source.gate')
REVISION = Path('/tmp/farm-source.revision')
EXCLUDED = {'images', 'cache', 'cw_cache', 'docker', 'image-imports', 'tmp', 'sitemaps',
            'extensions_old', 'extensions_staging', 'CreateWiki-old', 'cirrus_scripts',
            'tests', 'mw-config', 'PrivateSettings.php'}
EXCLUDED_NAMES = {'.git', 'node_modules', '__pycache__', '.DS_Store',
                  'LocalSettings.php.save', 'SetupCreateWiki.php'}
EXCLUDED_SUFFIXES = ('.key', '.key.pub', '.pem', '.log', '.db', '.rdb', '.tar.gz', '-old.php')


def ignored(relative, tree):
    if tree == 'mediawiki' and relative.parts[0] in EXCLUDED:
        return True
    for part in relative.parts:
        if (part in EXCLUDED_NAMES or part.startswith('.env') or 'secret' in part or
                part.endswith(EXCLUDED_SUFFIXES)):
            return True
    return 'vendor' in relative.parts and any(part in ('tests', 'Tests') for part in relative.parts[1:])


def files(root, tree):
    result = {}
    for directory, directories, names in os.walk(root, followlinks=False):
        parent = Path(directory)
        directories[:] = [name for name in directories
                          if not ignored((parent / name).relative_to(root), tree)]
        for name in names:
            path = parent / name
            relative = path.relative_to(root)
            if not ignored(relative, tree):
                result[relative] = path
    return result


def mirror(source, target, tree):
    """Both trees are container-local during publication, including comparisons."""
    incoming = files(source, tree)
    previous = files(target, tree)
    changed = 0
    for relative, path in incoming.items():
        destination = target / relative
        try:
            before = destination.lstat()
        except (FileNotFoundError, NotADirectoryError):
            before = None
        after = path.lstat()
        symlink = stat.S_ISLNK(after.st_mode)
        if symlink:
            if before and stat.S_ISLNK(before.st_mode) and os.readlink(path) == os.readlink(destination):
                continue
        elif before and stat.S_ISREG(before.st_mode) and (
                before.st_size, before.st_mtime_ns, before.st_mode & 0o777) == (
                after.st_size, after.st_mtime_ns, after.st_mode & 0o777):
            continue
        if before and stat.S_ISDIR(before.st_mode):
            shutil.rmtree(destination)
        try:
            destination.parent.mkdir(parents=True, exist_ok=True)
        except (FileExistsError, NotADirectoryError):
            # Handle file/directory transitions only on changed paths; checking
            # every ancestor of all 87k files makes small publications expensive.
            for parent in reversed(destination.parents):
                if parent == target or target in parent.parents:
                    if parent.is_symlink() or (parent.exists() and not parent.is_dir()):
                        parent.unlink()
            destination.parent.mkdir(parents=True, exist_ok=True)
        if before and stat.S_ISLNK(before.st_mode):
            destination.unlink()
        if symlink:
            destination.symlink_to(os.readlink(path))
        else:
            temporary = destination.with_name(destination.name + '.sync-' + uuid.uuid4().hex)
            try:
                shutil.copy2(path, temporary)
                temporary.replace(destination)
            finally:
                temporary.unlink(missing_ok=True)
        changed += 1
    for relative in previous.keys() - incoming.keys():
        # A former directory may already have been replaced by an incoming file.
        if previous[relative].is_file() or previous[relative].is_symlink():
            previous[relative].unlink()
            changed += 1
    return changed


def publish():
    with GATE.open('a') as gate, LOCK.open('a') as lock:
        # The consumer holds a shared lock while receiving and executing a job.
        # Hold the gate while waiting, preventing the idle consumer from repeatedly
        # reacquiring its shared lock ahead of a queued publisher.
        fcntl.flock(gate, fcntl.LOCK_EX)
        fcntl.flock(lock, fcntl.LOCK_EX)
        changed = mirror(STAGE / 'mediawiki', Path('/var/www/mediawiki'), 'mediawiki')
        changed += mirror(STAGE / 'farm', Path('/opt/farm'), 'farm')
        # PrivateSettings always uses the runtime loader, never a host secret file.
        private = Path('/var/www/mediawiki/PrivateSettings.php')
        if not private.is_symlink():
            private.unlink(missing_ok=True)
            private.symlink_to('/opt/farm/private.php')
        farm = Path('/var/www/mediawiki/docker/mediawiki')
        if not farm.is_symlink():
            if farm.exists():
                shutil.rmtree(farm)
            farm.parent.mkdir(parents=True, exist_ok=True)
            farm.symlink_to('/opt/farm', target_is_directory=True)
        if changed or not REVISION.exists():
            REVISION.write_text(uuid.uuid4().hex)
            print(f'Published provisioner source: {changed} files; consumer reloads between jobs', flush=True)


def reconcile():
    if os.environ.get('MW_ENV') != 'development' or os.environ.get('MW_CODE_SYNC') != '1':
        raise RuntimeError('Source synchronization requires development sync mode')
    for tree in ('mediawiki', 'farm'):
        source = Path('/sync-input') / tree
        if not source.is_dir():
            raise RuntimeError(f'Missing source input: {source}')
        print(f'Reconciling provisioner source: {tree}', flush=True)
        mirror(source, STAGE / tree, tree)
    publish()


def run():
    # No jobs are accepted until the current checkout has been published.
    REVISION.unlink(missing_ok=True)
    reconcile()
    # The PHP process is unprivileged. Only this development sync controller writes code.
    LOCK.chmod(0o666)
    GATE.chmod(0o666)
    child = None
    stopping = False

    def stop(signum, frame):
        nonlocal stopping
        stopping = True
        if child is not None and child.poll() is None:
            child.send_signal(signum)

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    while not stopping:
        child = subprocess.Popen(['php', '/opt/farm/stream-worker.php', 'provision'],
                                 user='www-data', group='www-data', cwd='/var/www/mediawiki')
        status = child.wait()
        if stopping or status != 75:
            return status
    return 0


if __name__ == '__main__':
    if sys.argv[1:] == ['publish']:
        if os.environ.get('MW_ENV') != 'development' or os.environ.get('MW_CODE_SYNC') != '1':
            raise RuntimeError('Source synchronization requires development sync mode')
        publish()
    elif sys.argv[1:] == ['run']:
        sys.exit(run())
    elif sys.argv[1:] == ['reconcile']:
        reconcile()
    else:
        raise SystemExit('Usage: provisioner-source.py run|publish|reconcile')
