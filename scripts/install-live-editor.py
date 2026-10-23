#!/usr/bin/env python3
"""Operator installation of the explicitly requested live production editor."""
import argparse
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tempfile
import time

ROOT = Path('/var/www')
STATE = Path('/var/lib/cloudwiki')
IMAGE = 'codercom/code-server@sha256:72ab24b5680ee597dd647b30d82625752f40109b5f61bef90ffbf1ea1f02dbb8'


def run(args, **kwargs):
    return subprocess.run(args, cwd=ROOT, check=True, **kwargs)


def load(path, name):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def install_file(source, target, mode=0o600):
    # All parents are operator-owned; the editor cannot replace installed utilities.
    fd, temporary = tempfile.mkstemp(dir=target.parent, prefix='.' + target.name)
    os.close(fd)
    try:
        shutil.copyfile(source, temporary)
        os.chmod(temporary, mode)
        os.replace(temporary, target)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def https(host, path='/', *extra):
    return run(['curl', '--fail', '--silent', '--show-error', '--max-time', '15',
                '--resolve', host + ':443:127.0.0.1', *extra,
                'https://' + host + path], capture_output=True, text=True).stdout


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, default=Path(__file__).resolve().parent.parent)
    args = parser.parse_args()
    if os.geteuid() != 0:
        parser.error('Run on the production host through sudo')
    source = args.source.resolve()
    old_guard = load(Path('/usr/local/libexec/cloudwiki-deploy-production.py'), 'old_guard')
    guard = load(source / 'scripts/deploy-production.py', 'new_guard')
    env = ROOT / '.env.production'
    wrapper = STATE / 'diff/Caddyfile'
    editor = STATE / 'editor'
    with (STATE / 'deploy.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        old_guard.check_local_config(ROOT, STATE, old_guard.Runner(ROOT))
        guard.check_worktree(guard.Runner(ROOT))
        if editor.exists():
            raise RuntimeError('Editor state already exists; review it before reinstalling')
        if shutil.disk_usage(ROOT).free < 5 * 1024 ** 3:
            raise RuntimeError('At least 5 GiB of free storage is required')
        previous_env, previous_wrapper = env.read_text(), wrapper.read_text()
        editor.mkdir(mode=0o700)
        guard.atomic_private_write(editor / 'previous.env', previous_env)
        guard.atomic_private_write(editor / 'previous.Caddyfile', previous_wrapper)
        home, control = editor / 'home', editor / 'control'
        for directory in (home, control):
            directory.mkdir(mode=0o700)
            os.chown(directory, 10001, 10001)
        user_settings = home / '.local/share/code-server/User'
        user_settings.mkdir(parents=True)
        (user_settings / 'settings.json').write_text(json.dumps({
            'files.autoSave': 'off', 'files.watcherExclude': {'**/vendor/**': True, '**/node_modules/**': True},
            'telemetry.telemetryLevel': 'off'}))
        for path in home.rglob('*'):
            os.chown(path, 10001, 10001)
        for name, entry in [('passwd', 'cloudwiki-editor:x:10001:10001:CloudWiki editor:/home/coder:/bin/bash'),
                            ('group', 'cloudwiki-editor:x:10001:')]:
            original = run(['docker', 'run', '--rm', '--entrypoint', 'cat', IMAGE, '/etc/' + name],
                           capture_output=True, text=True).stdout
            guard.atomic_private_write(editor / name, original.rstrip('\n') + '\n' + entry + '\n')
            (editor / name).chmod(0o644)
        password = secrets.token_urlsafe(32)
        guard.atomic_private_write(editor / 'password', password)
        # The installed image supplies Argon2; no package download or password on argv.
        hashed = run(['docker', 'run', '--rm', '-i', '--entrypoint', '/usr/lib/code-server/lib/node', IMAGE,
                      '-e', "let p='';process.stdin.on('data',b=>p+=b);process.stdin.on('end',()=>require('/usr/lib/code-server/node_modules/argon2').hash(p).then(h=>process.stdout.write(h)));"],
                     input=password, text=True, capture_output=True).stdout
        if not hashed.startswith('$argon2'):
            raise RuntimeError('Password hashing failed')
        guard.atomic_private_write(editor / 'config.yaml', 'bind-addr: 0.0.0.0:8080\nauth: password\nhashed-password: ' + json.dumps(hashed) + '\ncert: false\ndisable-telemetry: true\ncookie-suffix: cloudwiki-code\n')
        os.chown(editor / 'config.yaml', 0, 10001)
        (editor / 'config.yaml').chmod(0o640)
        for name in ('Caddyfile', 'compose.yaml', 'php-live.ini', 'reload-code'):
            install_file(source / 'docker/editor' / name, editor / name, 0o755 if name == 'reload-code' else 0o644)
        install_file(source / 'compose.bind.yaml', editor / 'compose.bind.yaml', 0o644)
        run(['python3', str(source / 'scripts/source-release.py'), '--directory', str(ROOT)])
        run(['python3', str(source / 'scripts/live-source-permissions.py')])
        # Store the explicit bind overlay in COMPOSE_FILE so old and new checkout tools agree.
        settings = dict(line.split('=', 1) for line in previous_env.splitlines() if '=' in line and not line.startswith('#'))
        files = settings['COMPOSE_FILE'].strip('"').split(':')
        files = [str(ROOT / name) for name in files]
        files += [str(editor / 'compose.bind.yaml'), str(editor / 'compose.yaml')]
        replacement = {'COMPOSE_FILE': ':'.join(files), 'MW_CODE_MODE': 'bind', 'MW_BIND_STRATEGY': 'live',
                       'MW_SOURCE_DIRECTORY': str(ROOT), 'CODE_SERVER_IMAGE': IMAGE}
        lines = previous_env.splitlines()
        # Preserve comments and unrelated settings; replace every duplicate assignment.
        lines = [line.partition('=')[0] + '=' + replacement[line.partition('=')[0]]
                 if line.partition('=')[0] in replacement else line for line in lines]
        seen = {line.partition('=')[0] for line in lines}
        lines += [key + '=' + value for key, value in replacement.items() if key not in seen]
        pending = editor / 'pending.env'
        guard.atomic_private_write(pending, '\n'.join(lines) + '\n')
        guard.atomic_private_write(wrapper, previous_wrapper + '\nimport /etc/caddy/editor.caddy\n')
        new_compose = ['docker', 'compose', '--env-file', str(pending)]
        frozen = False
        snapshot = 'before-live-editor-' + str(time.time_ns())
        try:
            run(new_compose + ['config', '--quiet'])
            # Exercise the editor's real mounts before stopping any wiki service.
            run(new_compose + ['up', '-d', '--no-deps', '--no-build', '--wait', '--wait-timeout', '90', 'code'])
            run(new_compose + ['exec', '-T', 'code', 'sh', '-ec',
                'test $(id -u) = 10001; test -w /var/www/mediawiki/index.php; '
                'test ! -w /var/www/mediawiki/PrivateSettings.php; '
                'test ! -w /etc/passwd; test ! -e /var/run/docker.sock; '
                'test ! -e /run/secrets/farm.json; test ! -e /var/www/.env.production; '
                'test ! -e /var/www/.git'])
            run(new_compose + ['stop', 'code'])
            run(new_compose + ['run', '--rm', '--no-deps', '--entrypoint', 'caddy', 'web',
                               'validate', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile'])
            print('Taking a coordinated recovery snapshot before enabling live source', flush=True)
            frozen = True
            run(['scripts/snapshot', '--env-file', str(env), '--keep-stopped', snapshot])
            guard.check_worktree(guard.Runner(ROOT))
            guard.atomic_private_write(env, pending.read_text())
            compose = ['docker', 'compose', '--env-file', str(env)]
            run(compose + ['up', '-d', '--no-build', '--wait', '--wait-timeout', '180'])
            guard.probe(ROOT, guard.Runner(ROOT))
            https('code.cloudwiki.us', '/login', '--retry', '10', '--retry-all-errors', '--retry-delay', '2', '--output', '/dev/null')
            login = https('code.cloudwiki.us', '/', '--dump-header', '-', '--output', '/dev/null')
            if '302' not in login or '/login' not in login:
                raise RuntimeError('Unauthenticated editor did not redirect to login')
            with tempfile.TemporaryDirectory(dir=editor, prefix='.auth-check-') as temporary:
                cookies = str(Path(temporary) / 'cookies')
                headers = https('code.cloudwiki.us', '/login', '--data-urlencode', 'password@' + str(editor / 'password'),
                                '--header', 'Origin: https://code.cloudwiki.us', '--cookie-jar', cookies,
                                '--dump-header', '-', '--output', '/dev/null')
                if '302' not in headers or 'set-cookie:' not in headers.lower():
                    raise RuntimeError('Editor password login failed')
                page = https('code.cloudwiki.us', '/?folder=/var/www/mediawiki', '--cookie', cookies)
                if 'workbench' not in page.lower():
                    raise RuntimeError('Authenticated editor workbench failed')
            for name, target in [('deploy-production.py', 'cloudwiki-deploy-production.py'),
                                 ('live-source-permissions.py', 'cloudwiki-live-permissions.py'),
                                 ('reload-live-code.py', 'cloudwiki-reload-code.py')]:
                install_file(source / 'scripts' / name, Path('/usr/local/libexec') / target)
            for name in ('cloudwiki-code-reload.path', 'cloudwiki-code-reload.service'):
                install_file(source / 'docker/editor' / name, Path('/etc/systemd/system') / name, 0o644)
            run(['systemctl', 'daemon-reload'])
            run(['systemctl', 'enable', '--now', 'cloudwiki-code-reload.path'])
            run(['python3', str(source / 'scripts/install-diff-dashboard.py'), '--source', str(source)])
            guard.accept_local_config(ROOT, STATE, guard.Runner(ROOT))
            guard.atomic_private_write(editor / 'installation.json', json.dumps({'installed_at': time.time(),
                                      'snapshot': snapshot, 'image': IMAGE}) + '\n')
            frozen = False
            print('Live editor installed; password is stored privately in /var/lib/cloudwiki/editor/password', flush=True)
        except Exception:
            with __import__('contextlib').suppress(Exception):
                run(new_compose + ['stop', 'code'])
            guard.atomic_private_write(env, previous_env)
            guard.atomic_private_write(wrapper, previous_wrapper)
            if frozen:
                # This install changes no database schemas; restoring the previous mounts is safe.
                run(['docker', 'compose', '--env-file', str(env), 'up', '-d', '--no-build', '--wait', '--wait-timeout', '180'])
            raise
    run(['systemctl', 'start', 'cloudwiki-diff.service'])


if __name__ == '__main__':
    main()
