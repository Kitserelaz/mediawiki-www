#!/usr/bin/env python3
"""Issue, install and renew wildcard TLS without changing wiki source or approving its edits."""
import argparse
import contextlib
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time

ROOT = Path('/var/www')
STATE = Path('/var/lib/cloudwiki')
BASE = STATE / 'wildcard'
DOMAIN = 'cloudwiki.us'
IMAGE = 'certbot/dns-cloudflare@sha256:c45edb002b883da1a1235abb205dff474a7a1a459d878e8d5fdc7f9d83073aea'
CREDENTIAL = BASE / 'credentials/cloudflare.ini'


def run(args, capture=False):
    result = subprocess.run(args, cwd=ROOT, text=True, check=True,
                            stdout=subprocess.PIPE if capture else None,
                            stderr=subprocess.PIPE if capture else None)
    return result.stdout.strip() if capture else None


def guard_module():
    spec = importlib.util.spec_from_file_location('deploy_guard', '/usr/local/libexec/cloudwiki-deploy-production.py')
    guard = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(guard)
    return guard


def atomic_write(path, content, mode=0o600):
    fd, temporary = tempfile.mkstemp(dir=path.parent, prefix='.' + path.name)
    try:
        with os.fdopen(fd, 'w') as out:
            out.write(content)
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def compose_environment(source, overlay):
    lines = source.splitlines()
    found = False
    for i, line in enumerate(lines):
        if line.startswith('COMPOSE_FILE='):
            value = line.partition('=')[2].strip('"\x27')
            if str(overlay) not in value.split(':'):
                value += ':' + str(overlay)
            lines[i] = 'COMPOSE_FILE=' + value
            found = True
    if not found:
        raise RuntimeError('An explicit production COMPOSE_FILE is required')
    return '\n'.join(lines) + '\n'


def approved_proxy_baseline(accepted, before, after, approved, source_root=ROOT):
    """Accept only the requested proxy files. Preserve unrelated, unaccepted edits."""
    allowed_source = source_root / 'mediawiki'
    for path in before.keys() | after.keys():
        if before.get(path) != after.get(path) and path not in approved and not Path(path).is_relative_to(allowed_source):
            raise RuntimeError('Unrelated configuration changed during proxy setup: ' + path)
    result = dict(accepted)
    for path in approved:
        if path not in after or after[path] is None:
            raise RuntimeError('Required proxy configuration is missing: ' + path)
        result[path] = after[path]
    return result


def certbot(command, dry_run=False):
    if not CREDENTIAL.is_file() or CREDENTIAL.stat().st_mode & 0o077:
        raise RuntimeError('A root-only Cloudflare DNS credential file is required')
    arguments = ['docker', 'run', '--rm', '--name', 'cloudwiki-certbot', '--cap-drop', 'ALL',
                 '--security-opt', 'no-new-privileges:true', '--memory', '384m', '--cpus', '0.5']
    for source, target, readonly in [('credentials', '/run/cloudflare', True), ('acme', '/etc/letsencrypt', False),
                                     ('work', '/var/lib/letsencrypt', False), ('logs', '/var/log/letsencrypt', False)]:
        arguments += ['-v', str(BASE / source) + ':' + target + (':ro' if readonly else '')]
    arguments += [IMAGE]
    if command == 'issue':
        arguments += ['certonly', '--non-interactive', '--agree-tos', '--email', 'admin@' + DOMAIN,
                      '--dns-cloudflare', '--dns-cloudflare-credentials', '/run/cloudflare/cloudflare.ini',
                      '--dns-cloudflare-propagation-seconds', '30', '--cert-name', DOMAIN,
                      '--key-type', 'ecdsa', '-d', '*.' + DOMAIN]
    else:
        arguments += ['renew', '--non-interactive', '--cert-name', DOMAIN]
        if dry_run:
            arguments.append('--dry-run')
    with (BASE / 'certificate.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        run(arguments)


def export_certificate():
    live = BASE / 'acme/live' / DOMAIN
    cert, key, chain = live / 'cert.pem', live / 'privkey.pem', live / 'chain.pem'
    run(['openssl', 'verify', '-CAfile', '/etc/ssl/certs/ca-certificates.crt', '-untrusted', str(chain), str(cert)], capture=True)
    run(['openssl', 'x509', '-in', str(cert), '-noout', '-checkend', '86400'], capture=True)
    names = run(['openssl', 'x509', '-in', str(cert), '-noout', '-ext', 'subjectAltName'], capture=True)
    if '*.' + DOMAIN not in re.findall(r'DNS:([^,\s]+)', names):
        raise RuntimeError('Certificate does not cover the wildcard hostname')
    if run(['openssl', 'x509', '-in', str(cert), '-pubkey', '-noout'], capture=True) != run(['openssl', 'pkey', '-in', str(key), '-pubout'], capture=True):
        raise RuntimeError('Certificate and private key do not match')
    (BASE / 'tls').mkdir(mode=0o700, exist_ok=True)
    changed = False
    for name in ('fullchain.pem', 'privkey.pem'):
        source, target = live / name, BASE / 'tls' / name
        content = source.read_text()
        if not target.exists() or target.read_text() != content:
            atomic_write(target, content)
            changed = True
    return changed


def check_routes():
    for host in ('somethingsomehting', 'wildcard-check-' + str(time.time_ns())):
        domain = host + '.' + DOMAIN
        with tempfile.TemporaryDirectory(dir=BASE) as directory:
            body = Path(directory) / 'body'
            status = run(['curl', '--silent', '--show-error', '--max-time', '15', '--retry', '8', '--retry-all-errors',
                          '--retry-delay', '1', '--resolve', domain + ':443:127.0.0.1', '--output', str(body),
                          '--write-out', '%{http_code}', 'https://' + domain + '/'], capture=True)
            if status != '404' or 'wiki' not in body.read_text().lower():
                raise RuntimeError('Unknown wiki did not return the expected application 404')
    # These exact hosts must retain their separate HTTP handlers.
    login = run(['curl', '--fail', '--silent', '--show-error', '--max-time', '20',
                 '--resolve', 'code.' + DOMAIN + ':443:127.0.0.1', 'https://code.' + DOMAIN + '/login'], capture=True)
    if 'password' not in login:
        raise RuntimeError('Editor handler failed')
    data = run(['curl', '--fail', '--silent', '--show-error', '--max-time', '20',
                '--resolve', 'diff.' + DOMAIN + ':443:127.0.0.1', 'https://diff.' + DOMAIN + '/changes.json'], capture=True)
    if not json.loads(data).get('deployed_commit'):
        raise RuntimeError('Dashboard handler failed')
    guard = guard_module()
    guard.probe(ROOT, guard.Runner(ROOT))


def install(source):
    guard = guard_module()
    env, wrapper = ROOT / '.env.production', STATE / 'diff/Caddyfile'
    with (STATE / 'deploy.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        before = guard.capture_local_config(ROOT, guard.Runner(ROOT))
        accepted = json.loads((STATE / guard.CONFIG_BASELINE).read_text())['files']
        approved_proxy_baseline(accepted, accepted, before, set())
        old_env, old_wrapper = env.read_text(), wrapper.read_text()
        backup = BASE / ('before-proxy-' + str(time.time_ns()))
        backup.mkdir(mode=0o700)
        atomic_write(backup / 'production.env', old_env)
        atomic_write(backup / 'Caddyfile', old_wrapper)
        atomic_write(backup / 'accepted-local-config.json', (STATE / guard.CONFIG_BASELINE).read_text())
        export_certificate()
        for name in ('Caddyfile', 'compose.yaml'):
            atomic_write(BASE / name, (source / 'docker/wildcard' / name).read_text(), 0o644)
        approved = {str(p) for p in (env, wrapper, BASE / 'Caddyfile', BASE / 'compose.yaml', CREDENTIAL)}
        compose = ['python3', 'scripts/compose', '--env-file', str(env)]
        try:
            atomic_write(env, compose_environment(old_env, BASE / 'compose.yaml'))
            new_wrapper = old_wrapper
            if 'import /etc/caddy/wildcard.caddy' not in new_wrapper.splitlines():
                new_wrapper += '\nimport /etc/caddy/wildcard.caddy\n'
            atomic_write(wrapper, new_wrapper)
            run(compose + ['config', '--quiet'])
            run(compose + ['run', '--rm', '--no-deps', '--entrypoint', 'caddy', 'web',
                           'validate', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile'])
            run(compose + ['up', '-d', '--no-deps', '--no-build', 'web'])
            check_routes()
            after = guard.capture_local_config(ROOT, guard.Runner(ROOT))
            expected = approved_proxy_baseline(accepted, before, after, approved)
            guard.save_config_baseline(STATE, expected, 'approved wildcard proxy setup; unrelated existing drift preserved')
        except Exception:
            atomic_write(env, old_env)
            atomic_write(wrapper, old_wrapper)
            with contextlib.suppress(Exception):
                run(compose + ['up', '-d', '--no-deps', '--no-build', 'web'])
            raise
        atomic_write(Path('/usr/local/libexec/cloudwiki-wildcard-tls.py'), (source / 'scripts/wildcard-tls.py').read_text())
        for name in ('cloudwiki-wildcard-renew.service', 'cloudwiki-wildcard-renew.timer'):
            atomic_write(Path('/etc/systemd/system') / name, (source / 'docker/wildcard' / name).read_text(), 0o644)
        run(['systemctl', 'daemon-reload'])
        run(['systemctl', 'enable', '--now', 'cloudwiki-wildcard-renew.timer'])
        # The normalized, guarded credential is retained; remove its extra input copy.
        (BASE / 'cloudflare-api-token').unlink(missing_ok=True)
        print('Wildcard routing active; existing source/config approvals preserved', flush=True)


def renew(dry_run):
    certbot('renew', dry_run)
    if dry_run:
        return
    guard = guard_module()
    with (STATE / 'deploy.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        accepted = json.loads((STATE / guard.CONFIG_BASELINE).read_text())['files']
        current = guard.capture_local_config(ROOT, guard.Runner(ROOT))
        # Renewals must not activate other unaccepted proxy/config edits.
        approved_proxy_baseline(accepted, accepted, current, set())
        if export_certificate():
            run(['docker', 'exec', 'cloudwiki-production-web-1', 'caddy', 'validate', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile'])
            run(['docker', 'restart', 'cloudwiki-production-web-1'])
            check_routes()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['issue', 'install', 'renew'])
    parser.add_argument('--source', type=Path, default=Path('/var/www'))
    parser.add_argument('--dry-run', action='store_true')
    args = parser.parse_args()
    if os.geteuid() != 0:
        parser.error('Run locally on the production server through sudo')
    if args.command == 'install':
        install(args.source)
    elif args.command == 'issue':
        certbot('issue')
    else:
        renew(args.dry_run)


if __name__ == '__main__':
    main()
