#!/usr/bin/env python3
"""Restricted SSH entry point for the cloudwiki.us production deployment."""
import contextlib
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import threading

REPOSITORY = "https://github.com/Kitserelaz/mediawiki-www.git"
REGISTRY = "ghcr.io/kitserelaz/mediawiki-www-"
SERVICES = ("php", "web", "counter")
WRITERS = ("web", "php", "worker", "provisioner", "jobchron", "scheduler", "counter")
IMAGE_KEYS = {"php": "MW_IMAGE", "web": "WEB_IMAGE", "counter": "COUNTER_IMAGE"}
CONFIG_BASELINE = 'accepted-local-config.json'
ROLLBACK_POLICY = 'rollback-compatibility.json'


def rollback_approved(state, previous_commit, candidate_commit):
    """Only a local operator can attest to a rehearsed database-compatible pair.

    A candidate cannot grant itself permission to run old code against its
    migrations. Missing, malformed or writable-by-others policies fail closed.
    Approval must cover partial migrations as well as the completed schema.
    """
    path = state / ROLLBACK_POLICY
    try:
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o022:
            return False
        policy = json.loads(path.read_text())
        if policy.get('schema_version') != 1:
            return False
        return any(
            entry.get('previous_commit') == previous_commit
            and entry.get('candidate_commit') == candidate_commit
            and entry.get('database_backward_compatible') is True
            and entry.get('partial_migrations_compatible') is True
            and isinstance(entry.get('evidence'), str) and entry['evidence'].strip()
            for entry in policy['approvals'] if isinstance(entry, dict)
        )
    except (OSError, ValueError, KeyError, TypeError, AttributeError):
        return False


def compose_command(root, env_file):
    if (root / 'scripts/compose').is_file():
        return ['python3', 'scripts/compose', '--env-file', str(env_file)]
    return ['docker', 'compose', '--env-file', str(env_file)]


def stop_application(runner, project, live):
    # Candidate Compose/Python files can themselves be the cause of failure.
    # Use Docker labels, not those files, to stop only this farm's writers.
    rows = runner.run(['docker', 'ps', '-a', '--filter',
                       'label=com.docker.compose.project=' + project, '--format',
                       '{{.ID}} {{.Label "com.docker.compose.service"}}'], capture=True)
    services = {*WRITERS, 'creation-events', 'bg-blue-php', 'bg-blue-web',
                'bg-green-php', 'bg-green-web'} | ({'code'} if live else set())
    ids = []
    for line in rows.splitlines():
        fields = line.split()
        if len(fields) == 2 and fields[1] in services:
            if not re.fullmatch('[0-9a-f]{12,64}', fields[0]):
                raise RuntimeError('Unexpected container identity during recovery')
            ids.append(fields[0])
    if ids:
        runner.run(['docker', 'stop', '--time', '30', *ids])


def persistent_service_config(config, name):
    service = config.get('services', {}).get(name)
    if service is None:
        return None
    result = {'service': service}
    for kind in ('volumes', 'networks', 'secrets', 'configs'):
        entries = service.get(kind, {})
        if kind == 'networks':
            names = entries
        else:
            names = [entry['source'] for entry in entries if isinstance(entry, dict)
                     and entry.get('source') and (kind != 'volumes' or entry.get('type') == 'volume')]
        result[kind] = {key: config.get(kind, {}).get(key) for key in names}
    return result


def rollback_application(root, state, runner, health, progress, *, old_commit,
                         candidate_commit, old_environment, new_environment,
                         initial_config, tracked_paths, live, database_started,
                         previous_success, code_changed, project, online_settings=None):
    """Restore application selection only. Never restore SQL or data volumes."""
    progress.stage('rolling_back')
    env_file = root / '.env.production'
    # Stop possible partial starts before replacing any code. Failure to stop
    # aborts recovery; never swap source underneath an unknown running writer.
    stop_application(runner, project, live)
    # A failed maintenance deployment returns to the legacy runtime. A later
    # successful maintenance release can rebuild the blue/green gateway.
    if online_settings and online_settings.get('MW_ZERO_DOWNTIME') == '1':
        disable_online(online_settings)
    check_worktree(runner)
    check_stable_files(initial_config, tracked_paths | {str(env_file)})
    if env_file.read_text() not in (old_environment, new_environment):
        raise config_changed([str(env_file)])
    if code_changed:
        runner.run(['git', 'checkout', '--detach', old_commit])
    atomic_private_write(env_file, old_environment)
    if live and code_changed:
        runner.run(['python3', 'scripts/source-release.py', '--directory', str(root)])
        runner.run(['python3', '/usr/local/libexec/cloudwiki-live-permissions.py'])
    compose = compose_command(root, env_file)
    check_local_config(root, state, runner, initial_config)
    runner.run(compose + ['config', '--quiet'])
    # Start the existing data services in place. Do not recreate or downgrade
    # database/Redis containers, remove volumes, import SQL, or run an updater.
    runner.run(compose + ['start', 'database', 'redis', 'memcached'])
    if database_started:
        # The local compatibility attestation is mandatory before reaching this
        # path; also verify the previous runtime's read-only prerequisites.
        runner.run(compose + ['run', '--rm', '--no-deps', 'init', 'php',
                              '/opt/farm/manage.php', 'preflight'], input='')
    restored_services = json.loads(runner.run(compose + ['config', '--format', 'json'], capture=True))['services']
    runtime = [*WRITERS, 'creation-events'] if 'creation-events' in restored_services else WRITERS
    runner.run(compose + ['up', '-d', '--no-deps', '--no-build', '--pull', 'never',
                          '--wait', '--wait-timeout', '180', *runtime])
    health(root, runner)
    check_worktree(runner)
    check_local_config(root, state, runner, initial_config)
    last = state / 'last-success.json'
    if last.exists() and last.read_text() != previous_success:
        if json.loads(last.read_text()).get('commit') != candidate_commit:
            raise RuntimeError('Release record changed during recovery')
        if previous_success is None:
            last.unlink()
        else:
            atomic_private_write(last, previous_success)
    if live:
        runner.run(compose + ['up', '-d', '--no-deps', '--no-build', '--pull', 'never', 'code'])
    save_config_baseline(state, initial_config, 'application rollback to ' + old_commit)
    progress.stage('rolled_back', 'rolled_back', 'application_restored_database_preserved')
    print('Previous application restored and healthy; database contents and data volumes preserved.', flush=True)


def validate_request(command, payload):
    if command != "deploy":
        raise ValueError("Only the deploy command is permitted")
    required = {"commit", "images", "registry_user", "registry_token"}
    if not isinstance(payload, dict) or not required <= set(payload) or set(payload) - required - {'mode'}:
        raise ValueError("Invalid deployment request")
    if payload.get('mode', 'auto') not in ('auto', 'online', 'maintenance'):
        raise ValueError('Invalid deployment mode')
    if not isinstance(payload["commit"], str) or not re.fullmatch(r"[0-9a-f]{40}", payload["commit"]):
        raise ValueError("A full commit SHA is required")
    if not isinstance(payload["images"], dict) or set(payload["images"]) != set(SERVICES):
        raise ValueError("All three production images are required")
    for service in SERVICES:
        image = payload["images"][service]
        if not isinstance(image, str) or not re.fullmatch(re.escape(REGISTRY + service) + r"@sha256:[0-9a-f]{64}", image):
            raise ValueError("Images must be pinned digests from the production repository")
    if not isinstance(payload["registry_user"], str) or not re.fullmatch(r"[A-Za-z0-9-]{1,100}(?:\[bot\])?", payload["registry_user"]):
        raise ValueError("Invalid registry username")
    if not isinstance(payload["registry_token"], str) or not 1 <= len(payload["registry_token"]) <= 4096:
        raise ValueError("Invalid registry credential")
    return payload


def image_environment(source, images):
    replacements = {IMAGE_KEYS[service]: image for service, image in images.items()}
    # Chron normally follows MW_IMAGE; do not retain an independently pinned old runner.
    replacements["JOBCHRON_IMAGE"] = images["php"]
    lines = []
    seen = set()
    for line in source.splitlines():
        key = line.partition("=")[0]
        if key in replacements:
            lines.append(key + "=" + replacements[key])
            seen.add(key)
        else:
            lines.append(line)
    lines.extend(key + "=" + value for key, value in replacements.items() if key not in seen)
    return "\n".join(lines) + "\n"


def atomic_private_write(path, content):
    fd, temporary = tempfile.mkstemp(prefix="." + path.name, dir=path.parent)
    try:
        with os.fdopen(fd, "w") as out:
            out.write(content)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def file_fingerprint(path):
    try:
        data = path.read_bytes()
        if path.is_symlink():
            data = os.readlink(path).encode() + b'\0' + data
        return hashlib.sha256(data).hexdigest()
    except FileNotFoundError:
        return None


def capture_local_config(root, runner):
    """Fingerprint configuration without storing or logging credential values."""
    env_file = root / '.env.production'
    base = ['docker', 'compose', '--env-file', str(env_file), '--profile', '*', 'config']
    config = json.loads(runner.run(base + ['--no-env-resolution', '--format', 'json'], capture=True))
    environment = runner.run(base + ['--environment'], capture=True)
    settings = dict(line.split('=', 1) for line in environment.splitlines() if '=' in line)
    paths = {env_file}
    for name in (settings.get('COMPOSE_FILE') or 'compose.yaml').split(settings.get('COMPOSE_PATH_SEPARATOR') or os.pathsep):
        paths.add(root / name)
    for secret in config.get('secrets', {}).values():
        if secret.get('file'):
            path = Path(secret['file'])
            if not path.is_absolute():
                path = root / path
            paths.add(path)
            # Also detect additions/removals in the local credential directory.
            if path.parent.is_dir():
                paths.update(item for item in path.parent.iterdir() if item.is_file())
    for service in config.get('services', {}).values():
        for entry in service.get('env_file', []):
            path = Path(entry['path'] if isinstance(entry, dict) else entry)
            if not path.is_absolute():
                path = root / path
            required = not isinstance(entry, dict) or entry.get('required', True)
            if required or path.exists():
                paths.add(path)
        for volume in service.get('volumes', []):
            if volume.get('type') == 'bind':
                path = Path(volume['source'])
                if not path.is_absolute():
                    path = root / path
                if path.is_file():
                    paths.add(path)
    if settings.get('MW_CODE_MODE') == 'bind' and settings.get('MW_SOURCE_DIRECTORY'):
        source = Path(settings['MW_SOURCE_DIRECTORY'])
        if not source.is_absolute():
            source = root / source
        if settings.get('MW_BIND_STRATEGY', 'versioned') != 'live':
            paths.add(source / 'release.json')
        paths.add(source / 'docker/mediawiki/private.php')
        for pattern in ['*Settings*.php', 'ManageWiki*.php', 'FarmSiteConfiguration.php',
                        'GlobalExtensions.php', 'SkyWikiFunctions.php', 'LocalWiki.php']:
            paths.update((source / 'mediawiki').glob(pattern))
    return {str(path): file_fingerprint(path) for path in sorted(paths)}


def config_changed(paths):
    return RuntimeError('Local configuration changed: ' + ', '.join(sorted(paths)) +
                        '. Review the files, then acknowledge intentional changes locally with '
                        'sudo python3 /usr/local/libexec/cloudwiki-deploy-production.py --accept-config')


def check_local_config(root, state, runner, expected=None):
    if expected is None:
        baseline = state / CONFIG_BASELINE
        if not baseline.is_file():
            raise RuntimeError('No accepted local configuration baseline. Establish it locally with '
                               'sudo python3 /usr/local/libexec/cloudwiki-deploy-production.py --accept-config')
        expected = json.loads(baseline.read_text())['files']
    # Check known files first so invalid edits cannot make Compose fail before
    # the guard can report the changed filenames without revealing contents.
    edited = [path for path, fingerprint in expected.items() if file_fingerprint(Path(path)) != fingerprint]
    if edited:
        raise config_changed(edited)
    actual = capture_local_config(root, runner)
    changed = [path for path in expected.keys() | actual.keys()
               if path not in expected or path not in actual or expected[path] != actual[path]]
    if changed:
        raise config_changed(changed)
    return actual


def save_config_baseline(state, files, reason):
    atomic_private_write(state / CONFIG_BASELINE, json.dumps({
        'schema_version': 1, 'accepted_at': time.time(), 'reason': reason, 'files': files,
    }, indent=2) + '\n')


def accept_local_config(root, state, runner):
    files = capture_local_config(root, runner)
    missing = [path for path, fingerprint in files.items() if fingerprint is None]
    if missing:
        raise RuntimeError('Cannot accept missing configuration files: ' + ', '.join(missing))
    save_config_baseline(state, files, 'local operator acknowledgement')
    print('Accepted local configuration fingerprints (' + str(len(files)) + ' files); no secret values recorded', flush=True)


def check_stable_files(expected, ignored):
    changed = [path for path, fingerprint in expected.items()
               if path not in ignored and file_fingerprint(Path(path)) != fingerprint]
    if changed:
        raise config_changed(changed)


class Runner:
    def __init__(self, root):
        self.root = root

    def run(self, args, *, capture=False, input=None, env=None):
        result = subprocess.run(args, cwd=self.root, input=input, text=True,
                                stdout=subprocess.PIPE if capture else None,
                                check=True, env=env)
        return result.stdout.strip() if capture else None


def probe(root, runner):
    # Contact this origin directly, validating its public certificate. A CDN cache
    # must not make a broken origin appear healthy.
    for host in ("meta", "login", "test"):
        data = runner.run(["curl", "--fail", "--silent", "--show-error", "--max-time", "30",
                           "--resolve", f"{host}.cloudwiki.us:443:127.0.0.1",
                           f"https://{host}.cloudwiki.us/api.php?action=query&meta=siteinfo&format=json"], capture=True)
        if not json.loads(data).get("query", {}).get("general", {}).get("generator", "").startswith("MediaWiki "):
            raise RuntimeError("Origin API health check failed")
    heartbeat = runner.run(["curl", "--fail", "--silent", "--show-error", "--max-time", "30",
                            "--resolve", "counter.cloudwiki.us:443:127.0.0.1",
                            "https://counter.cloudwiki.us/heart-beat"], capture=True)
    if heartbeat != "alive":
        raise RuntimeError("Counter health check failed")


def trim_history(root, state, runner):
    """Retain two image releases and three generated recovery snapshots."""
    records = []
    for path in sorted((state / "history").glob("deploy-*.json")):
        records.append(json.loads(path.read_text()))
    keep_images = {image for record in records[-2:] for image in record["images"].values()}
    for image in sorted({image for record in records[:-2] for image in record["images"].values()} - keep_images):
        # No force: an image still used by any container is retained by Docker.
        with contextlib.suppress(subprocess.CalledProcessError):
            runner.run(["docker", "image", "rm", image])
    for record in records[:-3]:
        name = record["snapshot"]
        if re.fullmatch(r"deploy-[0-9]+-[0-9a-f]{12}", name):
            path = root / "backups" / name
            if path.exists() and not path.is_symlink():
                shutil.rmtree(path)
    keep_sources = {record.get('source_directory') for record in records[-2:]}
    for record in records[:-2]:
        source = record.get('source_directory')
        if source and source not in keep_sources:
            path = Path(source)
            if path.parent == state / 'releases' and re.fullmatch(r'[0-9a-f]{40}', path.name) and path.exists() and not path.is_symlink():
                shutil.rmtree(path)


def prepare_release(commit, root, state, runner):
    directory = state / 'releases' / commit
    # Use the exporter from the target commit, even when the previous checkout
    # predates bind-mode support. Exporting finishes before traffic is stopped.
    script = runner.run(['git', 'show', commit + ':scripts/source-release.py'], capture=True)
    with tempfile.TemporaryDirectory(prefix='exporter-', dir=state) as temporary:
        exporter = Path(temporary) / 'source-release.py'
        exporter.write_text(script)
        runner.run(['python3', str(exporter), '--repository', str(root), '--commit', commit,
                    '--directory', str(directory)])
    return directory


def check_worktree(runner):
    if runner.run(['git', 'status', '--porcelain', '--untracked-files=no'], capture=True):
        raise RuntimeError('Tracked production files have local changes; refusing to overwrite them')
    if runner.run(['git', 'status', '--porcelain', '--untracked-files=all', '--', 'mediawiki'], capture=True):
        raise RuntimeError('MediaWiki has uncommitted or untracked live edits; refusing to overwrite them')


def blue_green():
    spec = importlib.util.spec_from_file_location('cloudwiki_blue_green', Path(__file__).with_name('blue_green.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def online_directory(settings):
    path = Path(settings.get('MW_BLUE_GREEN_DIRECTORY', '/var/lib/cloudwiki/blue-green'))
    if not path.is_absolute():
        raise RuntimeError('MW_BLUE_GREEN_DIRECTORY must be absolute')
    if path.exists() or path.is_symlink():
        info = path.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o022:
            raise RuntimeError('Blue/green state must be a private operator-owned directory')
    return path


def online_changes(runner, previous, candidate):
    """Reject deletions, type changes and symlinks as well as unknown paths."""
    raw = runner.run(['git', 'diff', '--raw', '--no-abbrev', '--no-renames', '-z',
                      previous, candidate, '--'], capture=True)
    fields = raw.split('\0')
    paths = []
    for index in range(0, len(fields) - 1, 2):
        metadata, path = fields[index:index + 2]
        parts = metadata.split()
        if (len(parts) != 5 or parts[0] not in (':000000', ':100644', ':100755')
                or parts[1] not in ('100644', '100755') or parts[4] not in ('A', 'M')):
            return False
        paths.append(path)
    return blue_green().online_safe(paths)


def slot_command(directory, slot):
    blue_green().slot_name(slot, 'web')  # Validate before using the slot as a path.
    return ['docker', 'compose', '-f', str(directory / (slot + '.json'))]


def write_caddy_files(directory, files):
    directory.mkdir(mode=0o755, parents=True, exist_ok=True)
    for name, content in files.items():
        if Path(name).name != name:
            raise ValueError('Invalid generated Caddy filename')
        atomic_private_write(directory / name, content)
        (directory / name).chmod(0o644)


def probe_slot(runner, directory, slot, domain):
    bg = blue_green()
    compose = slot_command(directory, slot)
    for host in ('meta', 'login', 'test'):
        result = runner.run(compose + ['exec', '-T', bg.slot_name(slot, 'php'),
                            'curl', '--fail', '--silent', '--show-error', '--max-time', '30',
                            '-H', 'Host: ' + host + '.' + domain,
                            'http://' + bg.slot_name(slot, 'web') + ':8080/api.php?action=query&meta=siteinfo&format=json'], capture=True)
        if not json.loads(result).get('query', {}).get('general', {}).get('generator', '').startswith('MediaWiki '):
            raise RuntimeError('Candidate HTTP API check failed')
    result = runner.run(compose + ['exec', '-T', bg.slot_name(slot, 'php'),
                        'curl', '--fail', '--silent', '--show-error', '--max-time', '30',
                        '-H', 'Host: meta.' + domain,
                        'http://' + bg.slot_name(slot, 'web') + ':8080/load.php?modules=startup&only=scripts&lang=en&skin=vector'], capture=True)
    if not result.strip():
        raise RuntimeError('Candidate ResourceLoader response is empty')


def prepare_slot(root, directory, runner, base, slot, images, source):
    bg = blue_green()
    # A retained slot may still be finishing long-running requests from the last
    # cutover. Never replace it merely because a fixed drain timer expired.
    ids = runner.run(['docker', 'ps', '--filter', 'label=com.docker.compose.project=' + base['name'],
                      '--filter', 'label=com.docker.compose.service=' + bg.slot_name(slot, 'web'),
                      '--format', '{{.ID}}'], capture=True)
    if ids:
        count = runner.run(slot_command(directory, slot) + ['exec', '-T', bg.slot_name(slot, 'web'),
                           'sh', '-ec', 'connections=$(netstat -tn); printf "%s\\n" "$connections" | awk \'$4 ~ /:(8080|8083)$/ && $6 == "ESTABLISHED" { n++ } END { print n+0 }\''], capture=True)
        if count != '0':
            raise RuntimeError('Previous application slot is still draining; retry deployment later')
    backend = directory / slot
    write_caddy_files(backend, bg.backend_files(root, slot))
    config = bg.slot_config(base, slot, images, backend, source)
    atomic_private_write(directory / (slot + '.json'), json.dumps(config) + '\n')
    compose = slot_command(directory, slot)
    runner.run(compose + ['config', '--quiet'])
    runner.run(compose + ['up', '-d', '--no-deps', '--no-build', '--pull', 'never',
                          '--wait', '--wait-timeout', '180'])


def reload_gateway(root, runner):
    bg = blue_green()
    compose = compose_command(root, root / '.env.production')
    runner.run(compose + ['exec', '-T', 'web', 'caddy', 'reload', '--address', bg.ADMIN,
                          '--config', bg.MOUNT + '/Caddyfile', '--adapter', 'caddyfile'])


def enable_online(root, state, runner, settings, images, commit, source=None, health=probe):
    """One-time gateway conversion, within the existing maintenance window."""
    directory = online_directory(settings)
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    if (directory / 'pending.json').exists():
        raise RuntimeError('Interrupted online deployment; run --recover-online locally first')
    compose = compose_command(root, root / '.env.production')
    base = json.loads(runner.run(['docker', 'compose', '--env-file', str(root / '.env.production'),
                                 'config', '--format', 'json'], capture=True))
    gateway = directory / 'gateway'
    prepare_slot(root, directory, runner, base, 'blue', images, source)
    probe_slot(runner, directory, 'blue', settings.get('MW_DOMAIN', 'cloudwiki.us'))
    write_caddy_files(gateway, blue_green().gateway_files(base, 'blue'))
    validation = directory / 'validate.json'
    atomic_private_write(validation, json.dumps(blue_green().gateway_validation_config(base, gateway)) + '\n')
    runner.run(['docker', 'compose', '-f', str(validation), 'run', '--rm', '--no-deps', 'gateway-validation'])
    atomic_private_write(directory / 'compose.json', json.dumps(blue_green().gateway_override(gateway)) + '\n')
    try:
        runner.run(compose + ['config', '--quiet'])
        # This is the only gateway recreation; future online releases use reload.
        runner.run(compose + ['up', '-d', '--no-deps', '--no-build', '--pull', 'never', 'web'])
        runner.run(compose + ['exec', '-T', 'web', 'caddy', 'validate', '--config',
                              blue_green().MOUNT + '/Caddyfile', '--adapter', 'caddyfile'])
        for attempt in range(6):
            try:
                health(root, runner)
                break
            except (subprocess.CalledProcessError, RuntimeError, ValueError):
                if attempt == 5:
                    raise
                time.sleep(2)
        atomic_private_write(directory / 'active.json', json.dumps({
            'slot': 'blue', 'commit': commit, 'schema_commit': commit}) + '\n')
    except Exception:
        (directory / 'compose.json').unlink(missing_ok=True)
        runner.run(compose + ['up', '-d', '--no-deps', '--no-build', '--pull', 'never', 'web'])
        raise


def disable_online(settings):
    # Called only after snapshot has stopped the edge AND both application slots.
    directory = online_directory(settings)
    (directory / 'compose.json').unlink(missing_ok=True)
    (directory / 'active.json').unlink(missing_ok=True)


def recover_online(root, state, runner, settings, health=probe):
    directory = online_directory(settings)
    pending = directory / 'pending.json'
    transaction = json.loads(pending.read_text())
    env = root / '.env.production'
    check_worktree(runner)
    check_stable_files(transaction['initial_config'], {str(env)})
    if env.read_text() not in (transaction['old_environment'], transaction['new_environment']):
        raise config_changed([str(env)])
    current = runner.run(['git', 'rev-parse', 'HEAD'], capture=True)
    if current not in (transaction['old_commit'], transaction['candidate_commit']):
        raise RuntimeError('Source revision changed during online recovery')
    # Restore routing first: the previous slot and all its mounts stay intact.
    write_caddy_files(directory / 'gateway', transaction['gateway_files'])
    reload_gateway(root, runner)
    health(root, runner)
    runner.run(['git', 'checkout', '--detach', transaction['old_commit']])
    atomic_private_write(env, transaction['old_environment'])
    atomic_private_write(directory / 'active.json', json.dumps(transaction['active']) + '\n')
    last = state / 'last-success.json'
    if transaction['previous_success'] is None:
        last.unlink(missing_ok=True)
    else:
        atomic_private_write(last, transaction['previous_success'])
    save_config_baseline(state, transaction['initial_config'], 'Recovered online deployment; database unchanged')
    record_id = transaction.get('record_id', '')
    if re.fullmatch(r'deploy-[0-9]+-[0-9a-f]{12}', record_id):
        (state / 'history' / (record_id + '.json')).unlink(missing_ok=True)
    pending.unlink()


def deploy_online(payload, root, state, runner, health, progress, settings, base,
                  initial_config, old_commit, new_environment, source):
    directory = online_directory(settings)
    if (directory / 'pending.json').exists():
        raise RuntimeError('Interrupted online deployment; run --recover-online locally first')
    active = json.loads((directory / 'active.json').read_text())
    if active['commit'] != old_commit:
        raise RuntimeError('Online routing and checked-out release do not match')
    slot = 'green' if active['slot'] == 'blue' else 'blue'
    progress.stage('starting_services')
    prepare_slot(root, directory, runner, base, slot, payload['images'], source)
    progress.stage('health_checks')
    probe_slot(runner, directory, slot, settings.get('MW_DOMAIN', 'cloudwiki.us'))
    check_worktree(runner)
    check_local_config(root, state, runner, initial_config)
    gateway = directory / 'gateway'
    old_files = {path.name: path.read_text() for path in gateway.iterdir() if path.is_file()}
    env = root / '.env.production'
    last = state / 'last-success.json'
    record_id = 'deploy-' + str(time.time_ns()) + '-' + payload['commit'][:12]
    transaction = {'old_commit': old_commit, 'candidate_commit': payload['commit'],
                   'initial_config': initial_config, 'old_environment': env.read_text(),
                   'new_environment': new_environment, 'active': active, 'gateway_files': old_files,
                   'previous_success': last.read_text() if last.exists() else None, 'record_id': record_id}
    atomic_private_write(directory / 'pending.json', json.dumps(transaction) + '\n')
    try:
        progress.stage('switching_release')
        write_caddy_files(gateway, blue_green().gateway_files(base, slot))
        compose = compose_command(root, env)
        runner.run(compose + ['exec', '-T', 'web', 'caddy', 'validate', '--config',
                              blue_green().MOUNT + '/Caddyfile', '--adapter', 'caddyfile'])
        runner.run(['git', 'checkout', '--detach', payload['commit']])
        atomic_private_write(env, new_environment)
        reload_gateway(root, runner)
        progress.stage('health_checks')
        health(root, runner)
        check_worktree(runner)
        check_stable_files(initial_config, {str(env)})
        accepted = capture_local_config(root, runner)
        record = {'commit': payload['commit'], 'images': payload['images'], 'previous_commit': old_commit,
                  'snapshot': record_id, 'backup': None, 'deployment_mode': 'online',
                  'schema_commit': active['schema_commit'], 'slot': slot,
                  'code_mode': settings.get('MW_CODE_MODE', 'image'),
                  'bind_strategy': 'versioned' if source else None,
                  'source_directory': str(source) if source else None}
        (state / 'history').mkdir(mode=0o700, exist_ok=True)
        atomic_private_write(state / 'history' / (record_id + '.json'), json.dumps(record) + '\n')
        atomic_private_write(last, json.dumps(record) + '\n')
        atomic_private_write(directory / 'active.json', json.dumps({
            'slot': slot, 'commit': payload['commit'], 'schema_commit': active['schema_commit']}) + '\n')
        save_config_baseline(state, accepted, 'Online application release; database unchanged')
        (directory / 'pending.json').unlink()
        # Retain the previous slot, including long-running requests and source.
        # Reuse is allowed only after its incoming connections have drained.
        print('Online deployment healthy: ' + payload['commit'] + '; previous slot retained', flush=True)
    except Exception:
        try:
            recover_online(root, state, runner, settings, health)
            progress.stage('rolled_back', 'rolled_back', 'application_restored_database_preserved')
        except Exception:
            progress.stage('rolling_back', 'failed', 'rollback_failed')
            print('Online recovery needs attention; both slots retained. Run --recover-online locally.', file=sys.stderr)
        raise



class DeploymentProgress:
    """Fixed public stage names and timestamps only; no command output or credentials."""
    def __init__(self, state, commit):
        self.path = state / 'deployment-progress.json'
        now = time.time()
        self.data = {'commit': commit, 'status': 'running', 'stage': 'validating',
                     'started_at': now, 'stage_started_at': now, 'updated_at': now}
        self.lock = threading.Lock()
        self.stop = threading.Event()
        self.worker = threading.Thread(target=self.heartbeat, daemon=True)
        self.stage('validating')
        self.worker.start()

    def stage(self, stage, status='running', reason=None):
        with self.lock:
            now = time.time()
            if stage != self.data['stage']:
                self.data['stage_started_at'] = now
            self.data.update(stage=stage, status=status, reason=reason, updated_at=now, heartbeat_at=now)
            atomic_private_write(self.path, json.dumps(self.data) + '\n')

    def heartbeat(self):
        while not self.stop.wait(15):
            with self.lock:
                self.data['heartbeat_at'] = time.time()
                atomic_private_write(self.path, json.dumps(self.data) + '\n')

    def finish(self):
        self.stop.set()
        self.worker.join(timeout=5)


def deploy(payload, root, state, runner, health=probe):
    progress = DeploymentProgress(state, payload['commit'])
    try:
        _deploy(payload, root, state, runner, health, progress)
        if progress.data['status'] == 'running':
            progress.stage('complete', 'success')
        return progress.data['status'] == 'success'
    except Exception as error:
        message = str(error)
        reason = ('local_configuration_changed' if 'Local configuration changed:' in message else
                  'uncommitted_files' if 'uncommitted' in message or 'Tracked production' in message else
                  'insufficient_disk' if 'GiB free' in message else 'stage_failed')
        if progress.data['status'] != 'rolled_back' and progress.data.get('reason') != 'rollback_failed':
            progress.stage(progress.data['stage'], 'blocked' if reason != 'stage_failed' else 'failed', reason)
        raise
    finally:
        progress.finish()



def _deploy(payload, root, state, runner, health, progress):
    commit = payload["commit"]
    env_file = root / ".env.production"
    initial_config = check_local_config(root, state, runner)
    old_environment = env_file.read_text()
    if "MW_ENV=production" not in old_environment.splitlines():
        raise RuntimeError("Production configuration is required")
    check_worktree(runner)
    origin = runner.run(["git", "config", "--get", "remote.origin.url"], capture=True)
    if origin != REPOSITORY:
        raise RuntimeError("Unexpected production Git origin")
    progress.stage("fetching")
    runner.run(["git", "fetch", "--no-tags", "origin", "main"])
    if runner.run(["git", "rev-parse", "FETCH_HEAD"], capture=True) != commit:
        progress.stage("superseded", "superseded")
        print("A newer main commit exists; skipping this superseded deployment", flush=True)
        return
    old_commit = runner.run(["git", "rev-parse", "HEAD"], capture=True)
    relative_paths = [str(Path(path).relative_to(root)) for path in initial_config if Path(path).is_relative_to(root)]
    tracked = runner.run(['git', 'ls-files', '-z', '--', *relative_paths], capture=True)
    tracked_paths = {str(root / path) for path in tracked.split('\0') if path}
    resolved = runner.run(['docker', 'compose', '--env-file', str(env_file), 'config', '--environment'], capture=True)
    settings = dict(line.split('=', 1) for line in resolved.splitlines() if '=' in line)
    if settings.get('MW_NUMERIC_WIKI_IDS') == '1':
        try:
            runner.run(['git', 'cat-file', '-e', f'{commit}:docker/mediawiki/wiki-identity.php'])
        except subprocess.CalledProcessError as error:
            raise RuntimeError('Candidate release lacks numeric wiki identity support required by production') from error
    project_config = json.loads(runner.run(['docker', 'compose', '--env-file', str(env_file),
                                          'config', '--format', 'json'], capture=True))
    project = project_config.get('name', settings.get('COMPOSE_PROJECT_NAME', 'cloudwiki'))
    mode = settings.get('MW_CODE_MODE', 'image')
    if mode not in ('image', 'bind'):
        raise RuntimeError('MW_CODE_MODE must be image or bind')
    strategy = settings.get('MW_BIND_STRATEGY', 'versioned')
    if strategy not in ('versioned', 'live'):
        raise RuntimeError('MW_BIND_STRATEGY must be versioned or live')
    live = mode == 'bind' and strategy == 'live'
    if live and Path(settings.get('MW_SOURCE_DIRECTORY', '')).resolve() != root.resolve():
        raise RuntimeError('Live production source must be the application checkout')
    enabled = settings.get('MW_ZERO_DOWNTIME') == '1'
    directory = online_directory(settings)
    if enabled and live:
        raise RuntimeError('Blue/green requires image mode or versioned bind source; live editing cannot be isolated')
    if enabled and mode == 'image':
        for service in ('php', 'web'):
            for volume in project_config.get('services', {}).get(service, {}).get('volumes', []):
                if volume.get('type') == 'bind' and volume['target'] in (
                        '/var/www/mediawiki', '/opt/farm', '/var/www/mediawiki/resources',
                        '/var/www/mediawiki/extensions', '/var/www/mediawiki/skins'):
                    raise RuntimeError('Image blue/green cannot use bind-mounted application source; remove source overlays or select versioned bind mode')
    if enabled and (directory / 'pending.json').exists():
        raise RuntimeError('Interrupted online deployment; run --recover-online locally first')
    ready = enabled and (directory / 'compose.json').is_file() and (directory / 'active.json').is_file()
    if ready:
        active = json.loads((directory / 'active.json').read_text())
        if active.get('commit') != old_commit or active.get('slot') not in ('blue', 'green'):
            raise RuntimeError('Online routing and checked-out release do not match')
    safe = online_changes(runner, old_commit, commit) if enabled else False
    requested_mode = payload.get('mode', 'auto')
    online = ready and safe and requested_mode != 'maintenance'
    print('Deployment mode: ' + ('online (public assets only)' if online else 'maintenance (backup and database updates)'), flush=True)
    last = state / "last-success.json"
    previous_success = last.read_text() if last.exists() else None
    if last.exists():
        previous = json.loads(last.read_text())
        source_matches = mode == 'image' or (live and previous.get('bind_strategy') == 'live') or (not live and settings.get('MW_SOURCE_DIRECTORY') == str(state / 'releases' / commit)
                                             and (state / 'releases' / commit / 'release.json').is_file())
        if previous.get("commit") == commit and previous.get("images") == payload["images"] and previous.get('code_mode', 'image') == mode and source_matches and (not enabled or ready):
            health(root, runner)
            print("This release is already deployed and healthy", flush=True)
            return
    if requested_mode == 'online' and not online:
        raise RuntimeError('Online release requires an initialized gateway and only approved public-asset changes; use maintenance mode')
    new_environment = image_environment(old_environment, payload["images"])
    if shutil.disk_usage(root).free < 5 * 1024 ** 3:
        raise RuntimeError("Less than 5 GiB free; free storage before deploying")
    # Pull all images before the write freeze. Registry credentials exist only in
    # a private temporary Docker config and are discarded after the pulls.
    progress.stage("pulling_images")
    with tempfile.TemporaryDirectory(prefix="registry-", dir=state) as config:
        docker_env = dict(os.environ, DOCKER_CONFIG=config)
        runner.run(["docker", "login", "ghcr.io", "--username", payload["registry_user"], "--password-stdin"],
                   input=payload["registry_token"] + "\n", env=docker_env)
        for image in payload["images"].values():
            runner.run(["docker", "pull", image], env=docker_env)
            revision = runner.run(["docker", "image", "inspect", image, "--format",
                                   '{{index .Config.Labels "org.opencontainers.image.revision"}}'], capture=True)
            if revision != commit:
                raise RuntimeError("Pulled image does not match the requested commit")
    progress.stage("preparing_source")
    source_directory = root if live else prepare_release(commit, root, state, runner) if mode == 'bind' else None
    if source_directory:
        # Replace every active assignment, including duplicates, while preserving
        # the rest of the operator's production configuration.
        lines = new_environment.splitlines()
        key = 'MW_SOURCE_DIRECTORY'
        replacement = key + '=' + json.dumps(str(source_directory))
        found = any(line.partition('=')[0] == key for line in lines)
        new_environment = '\n'.join(replacement if line.partition('=')[0] == key else line for line in lines) + '\n'
        if not found:
            new_environment += replacement + '\n'
    if online:
        deploy_online(payload, root, state, runner, health, progress, settings, project_config,
                      initial_config, old_commit, new_environment, source_directory)
        return
    snapshot = "deploy-" + str(time.time_ns()) + "-" + commit[:12]
    compose = ['docker', 'compose', '--env-file', str(env_file)]
    # Downloads and source exports can take time. Recheck immediately before
    # maintenance so an intervening edit never gets silently overwritten.
    check_local_config(root, state, runner, initial_config)
    check_worktree(runner)
    frozen = False
    changed = False
    database_started = False
    rollback_authorized = rollback_approved(state, old_commit, commit)
    editor_stopped = False
    try:
        if live:
            runner.run(compose + ['stop', 'code'])
            editor_stopped = True
            check_worktree(runner)
            check_local_config(root, state, runner, initial_config)
        progress.stage("backup")
        frozen = True
        runner.run(["scripts/snapshot", "--env-file", str(env_file), "--keep-stopped", snapshot], input="")
        if enabled:
            disable_online(settings)
        check_local_config(root, state, runner, initial_config)
        check_worktree(runner)
        backup = root / "backups" / snapshot
        atomic_private_write(backup / "production.env", old_environment)
        atomic_private_write(backup / "deployment.json", json.dumps({"previous_commit": old_commit, "target_images": payload["images"]}, indent=2) + "\n")
        progress.stage("switching_release")
        changed = True
        runner.run(["git", "checkout", "--detach", commit])
        if live:
            runner.run(['python3', 'scripts/source-release.py', '--directory', str(root)])
            runner.run(['python3', '/usr/local/libexec/cloudwiki-live-permissions.py'])
        if runner.run(['git', 'status', '--porcelain', '--untracked-files=no'], capture=True):
            raise RuntimeError('Tracked production files changed while switching releases')
        if (root / 'scripts/compose').is_file():
            compose = ['python3', 'scripts/compose', '--env-file', str(env_file)]
        check_stable_files(initial_config, tracked_paths)
        atomic_private_write(env_file, new_environment)
        check_stable_files(initial_config, tracked_paths | {str(env_file)})
        intended_config = capture_local_config(root, runner)
        if intended_config[str(env_file)] != hashlib.sha256(new_environment.encode()).hexdigest():
            raise config_changed([str(env_file)])
        if runner.run(['git', 'status', '--porcelain', '--untracked-files=no'], capture=True):
            raise RuntimeError('Tracked production configuration changed while preparing the release')
        runner.run(compose + ["config", "--quiet"])
        candidate_config = json.loads(runner.run(compose + ['config', '--format', 'json'], capture=True))
        if candidate_config.get('name', project) != project:
            raise RuntimeError('Application deployments cannot change the Compose project identity')
        for service in ('database', 'redis', 'memcached'):
            if persistent_service_config(candidate_config, service) != persistent_service_config(project_config, service):
                raise RuntimeError('Application deployments cannot change persistent data-service definitions: ' + service)
        # Starting a new database service can itself change on-disk state, even
        # before MediaWiki's updater. Treat it as part of the compatibility boundary.
        database_started = True
        runner.run(compose + ["up", "-d", "--no-build", "--wait", "database", "redis", "memcached"])
        progress.stage("migrations")
        runner.run(["scripts/farm", "--env-file", str(env_file), "update"], input="")
        progress.stage("starting_services")
        runner.run(compose + ["up", "-d", "--no-build", "--wait", "--wait-timeout", "180"] + (["--scale", "code=0"] if live else []))
        progress.stage("health_checks")
        health(root, runner)
        if enabled:
            enable_online(root, state, runner, settings, payload['images'], commit, source_directory, health=health)
            health(root, runner)
        check_worktree(runner)
        check_local_config(root, state, runner, intended_config)
        record = {"commit": commit, "images": payload["images"], "previous_commit": old_commit, "snapshot": snapshot,
                  'code_mode': mode, 'bind_strategy': strategy if mode == 'bind' else None, 'source_directory': str(source_directory) if source_directory else None,
                  'deployment_mode': 'maintenance', 'schema_commit': commit}
        history = state / "history"
        history.mkdir(mode=0o700, exist_ok=True)
        atomic_private_write(history / (snapshot + ".json"), json.dumps(record, indent=2) + "\n")
        atomic_private_write(last, json.dumps(record, indent=2) + "\n")
        save_config_baseline(state, intended_config, 'successful deployment ' + commit)
        frozen = False
        progress.stage("restoring_editor")
        if live:
            try:
                runner.run(compose + ['up', '-d', '--no-deps', '--no-build', 'code'])
                editor_stopped = False
            except Exception:
                print('Release is healthy, but the live editor needs to be restarted', file=sys.stderr)
        print("Deployment healthy: " + commit + "; recovery snapshot: backups/" + snapshot, flush=True)
        try:
            trim_history(root, state, runner)
        except Exception:
            print("Release is healthy, but deployment retention cleanup needs attention", file=sys.stderr)
    except Exception:
        if editor_stopped and not frozen:
            with contextlib.suppress(Exception):
                runner.run(compose + ['up', '-d', '--no-deps', '--no-build', 'code'])
        if frozen:
            recovered = False
            if not database_started or rollback_authorized:
                try:
                    rollback_application(
                        root, state, runner, health, progress,
                        old_commit=old_commit, candidate_commit=commit,
                        old_environment=old_environment, new_environment=new_environment,
                        initial_config=initial_config, tracked_paths=tracked_paths,
                        live=live, database_started=database_started,
                        previous_success=previous_success, code_changed=changed,
                        project=project, online_settings=settings)
                    recovered = True
                except Exception:
                    progress.stage('rolling_back', 'failed', 'rollback_failed')
                    print('Application rollback failed; writers remain stopped for recovery.', file=sys.stderr)
            if recovered:
                # Retain the original deployment exception: CI must still fail
                # even though the previous release has been restored successfully.
                with contextlib.suppress(Exception):
                    atomic_private_write(state / ('rollback-' + snapshot + '.json'), json.dumps({
                        'failed_commit': commit, 'restored_commit': old_commit,
                        'snapshot': snapshot, 'database_started': database_started,
                        'database_restored': False, 'compatibility_approved': rollback_authorized,
                        'completed_at': time.time(),
                    }, indent=2) + '\n')
                raise
            with contextlib.suppress(Exception):
                stop_application(runner, project, live)
            print("Deployment failed with writers stopped. Recovery snapshot: backups/" + snapshot, file=sys.stderr)
            if database_started and not rollback_authorized:
                print('Database work began and this release pair has no verified rollback approval; application rollback withheld.', file=sys.stderr)
            if not changed:
                print("The application checkout and image settings were not changed.", file=sys.stderr)
        raise


def manual_rollback(payload, root, state, runner, health=probe):
    """Explicit compatible application rollback selected from private release records."""
    required = {'release_id', 'expected_current', 'expected_target', 'operation_id', 'registry_user', 'registry_token'}
    if not isinstance(payload, dict) or set(payload) != required:
        raise ValueError('Invalid rollback request')
    release_id = payload['release_id']
    if not isinstance(release_id, str) or not re.fullmatch(r'deploy-[0-9]+-[0-9a-f]{12}', release_id):
        raise ValueError('Invalid recorded release')
    if not isinstance(payload['expected_current'], str) or not re.fullmatch(r'[0-9a-f]{40}', payload['expected_current']):
        raise ValueError('Invalid current revision')
    if not isinstance(payload['operation_id'], str) or not re.fullmatch(r'[a-f0-9-]{36}', payload['operation_id']):
        raise ValueError('Invalid operation identity')
    path = state / 'history' / (release_id + '.json')
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
        raise RuntimeError('Recorded release is not trusted')
    record = json.loads(path.read_text())
    target = record['commit']
    if payload['expected_target'] != target:
        raise RuntimeError('Recorded release differs from the confirmed target')
    validate_request('deploy', {'commit': target, 'images': record['images'], 'registry_user': payload['registry_user'], 'registry_token': payload['registry_token']})
    initial = check_local_config(root, state, runner)
    check_worktree(runner)
    current = runner.run(['git', 'rev-parse', 'HEAD'], capture=True)
    if current != payload['expected_current']:
        raise RuntimeError('Current release changed; review rollback again')
    if not rollback_approved(state, target, current):
        raise RuntimeError('This release pair lacks explicit database and partial-migration compatibility approval')
    if runner.run(['git', 'config', '--get', 'remote.origin.url'], capture=True) != REPOSITORY:
        raise RuntimeError('Unexpected production Git origin')
    runner.run(['git', 'fetch', '--no-tags', 'origin', 'main'])
    runner.run(['git', 'merge-base', '--is-ancestor', target, 'FETCH_HEAD'])
    old_env = (root / '.env.production').read_text()
    compose = compose_command(root, root / '.env.production')
    settings = dict(line.split('=', 1) for line in runner.run(compose + ['config', '--environment'], capture=True).splitlines() if '=' in line)
    mode = settings.get('MW_CODE_MODE', 'image')
    strategy = settings.get('MW_BIND_STRATEGY', 'versioned')
    if record.get('code_mode', 'image') != mode or (mode == 'bind' and record.get('bind_strategy') != strategy):
        raise RuntimeError('Rollback cannot change source deployment mode')
    if settings.get('MW_NUMERIC_WIKI_IDS') == '1':
        runner.run(['git', 'cat-file', '-e', target + ':docker/mediawiki/wiki-identity.php'])
    before = json.loads(runner.run(compose + ['config', '--format', 'json'], capture=True))
    project = before['name']
    new_env = image_environment(old_env, record['images'])
    if mode == 'bind':
        source = str(root) if strategy == 'live' else str(state / 'releases' / target)
        if strategy != 'live' and not (Path(source) / 'release.json').exists():
            raise RuntimeError('Retained application source is unavailable')
        lines = new_env.splitlines()
        new_env = '\n'.join('MW_SOURCE_DIRECTORY=' + json.dumps(source) if line.startswith('MW_SOURCE_DIRECTORY=') else line for line in lines) + '\n'
    with tempfile.TemporaryDirectory(prefix='rollback-registry-', dir=state) as temporary:
        environment = dict(os.environ, DOCKER_CONFIG=temporary)
        runner.run(['docker', 'login', 'ghcr.io', '--username', payload['registry_user'], '--password-stdin'], input=payload['registry_token'] + '\n', env=environment)
        for image in record['images'].values():
            runner.run(['docker', 'pull', image], env=environment)
            if runner.run(['docker', 'image', 'inspect', image, '--format', '{{index .Config.Labels "org.opencontainers.image.revision"}}'], capture=True) != target:
                raise RuntimeError('Rollback image revision mismatch')
    check_local_config(root, state, runner, initial)
    check_worktree(runner)
    progress = DeploymentProgress(state, target)
    snapshot = 'deploy-' + str(time.time_ns()) + '-' + target[:12]
    previous_success = (state / 'last-success.json').read_bytes() if (state / 'last-success.json').exists() else None
    changed = False
    try:
        progress.stage('backup')
        runner.run(['scripts/snapshot', '--env-file', str(root / '.env.production'), '--keep-stopped', snapshot], input='')
        if settings.get('MW_ZERO_DOWNTIME') == '1':
            disable_online(settings)
        atomic_private_write(root / 'backups' / snapshot / 'production.env', old_env)
        if strategy == 'live':
            runner.run(compose + ['stop', 'code'])
        check_worktree(runner)
        check_local_config(root, state, runner, initial)
        progress.stage('switching_release')
        changed = True
        runner.run(['git', 'checkout', '--detach', target])
        atomic_private_write(root / '.env.production', new_env)
        if mode == 'bind' and strategy == 'live':
            runner.run(['python3', 'scripts/source-release.py', '--directory', str(root)])
            runner.run(['python3', '/usr/local/libexec/cloudwiki-live-permissions.py'])
        compose = compose_command(root, root / '.env.production')
        candidate = json.loads(runner.run(compose + ['config', '--format', 'json'], capture=True))
        if candidate['name'] != project:
            raise RuntimeError('Rollback cannot change Compose identity')
        for service in ('database', 'redis', 'memcached'):
            if persistent_service_config(candidate, service) != persistent_service_config(before, service):
                raise RuntimeError('Rollback cannot change persistent data-service definitions')
        runner.run(compose + ['start', 'database', 'redis', 'memcached'])
        runner.run(compose + ['run', '--rm', '--no-deps', 'init', 'php', '/opt/farm/manage.php', 'preflight'], input='')
        runtime = [*WRITERS, 'creation-events']
        runner.run(compose + ['up', '-d', '--no-deps', '--no-build', '--pull', 'never', '--wait', '--wait-timeout', '180', *runtime])
        health(root, runner)
        if settings.get('MW_ZERO_DOWNTIME') == '1':
            enable_online(root, state, runner, settings, record['images'], target,
                          Path(source) if mode == 'bind' else None, health=health)
            health(root, runner)
        check_worktree(runner)
        if strategy == 'live': runner.run(compose + ['up', '-d', '--no-deps', '--no-build', '--pull', 'never', 'code'])
        accepted = capture_local_config(root, runner)
        # External operator-owned files must remain at their reviewed fingerprints.
        external = {name: value for name, value in initial.items() if not Path(name).is_relative_to(root)}
        check_stable_files(external, set())
        restored = {**record, 'previous_commit': current, 'snapshot': snapshot, 'rollback_from': current, 'operation_id': payload['operation_id']}
        atomic_private_write(state / 'history' / (snapshot + '.json'), json.dumps(restored) + '\n')
        atomic_private_write(state / 'last-success.json', json.dumps(restored) + '\n')
        save_config_baseline(state, accepted, 'Explicit compatible application rollback to ' + target)
        progress.stage('complete', 'success', 'application_restored_database_preserved')
        print('Compatible application rollback completed; current data preserved', flush=True)
    except Exception:
        try:
            stop_application(runner, project, mode == 'bind' and strategy == 'live')
            if settings.get('MW_ZERO_DOWNTIME') == '1':
                disable_online(settings)
            if changed:
                runner.run(['git', 'checkout', '--detach', current])
                atomic_private_write(root / '.env.production', old_env)
                if mode == 'bind' and strategy == 'live':
                    runner.run(['python3', 'scripts/source-release.py', '--directory', str(root)])
                    runner.run(['python3', '/usr/local/libexec/cloudwiki-live-permissions.py'])
            compose = compose_command(root, root / '.env.production')
            runner.run(compose + ['start', 'database', 'redis', 'memcached'])
            runner.run(compose + ['up', '-d', '--no-deps', '--no-build', '--pull', 'never', '--wait', '--wait-timeout', '180', *WRITERS, 'creation-events'])
            health(root, runner)
            if strategy == 'live': runner.run(compose + ['up', '-d', '--no-deps', '--no-build', '--pull', 'never', 'code'])
            if previous_success is not None: atomic_private_write(state / 'last-success.json', previous_success.decode())
            save_config_baseline(state, initial, 'Recovered unsuccessful explicit rollback')
            progress.stage('rolled_back', 'failed', 'rollback_attempt_failed_original_application_restored')
        except Exception:
            with contextlib.suppress(Exception): stop_application(runner, project, mode == 'bind' and strategy == 'live')
            progress.stage('rolling_back', 'failed', 'rollback_failed')
        raise
    finally:
        progress.finish()


def main():
    if len(sys.argv) != 2:
        raise ValueError("A restricted SSH command is required")
    if os.geteuid() != 0:
        raise RuntimeError("The deployment entry point must run through sudo")
    root = Path("/var/www")
    state = Path("/var/lib/cloudwiki")
    state.mkdir(mode=0o700, exist_ok=True)
    with (state / "deploy.lock").open("w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if sys.argv[1] == '--accept-config':
            accept_local_config(root, state, Runner(root))
            return
        if sys.argv[1] == '--check-config':
            check_local_config(root, state, Runner(root))
            print('Local configuration matches the accepted baseline')
            return
        if sys.argv[1] == '--recover-online':
            runner = Runner(root)
            resolved = runner.run(['docker', 'compose', '--env-file', str(root / '.env.production'),
                                   'config', '--environment'], capture=True)
            settings = dict(line.split('=', 1) for line in resolved.splitlines() if '=' in line)
            recover_online(root, state, runner, settings)
            print('Previous online release restored; database unchanged')
            return
        body = sys.stdin.read(16385)
        if len(body) > 16384:
            raise ValueError("Deployment request is too large")
        if sys.argv[1] == 'rollback':
            manual_rollback(json.loads(body), root, state, Runner(root))
            return
        payload = validate_request(sys.argv[1], json.loads(body))
        deployed = deploy(payload, root, state, Runner(root))
        # Keep the separately installed SSH entry point in sync after a successful
        # release, without giving the SSH key an arbitrary shell command.
        last = state / "last-success.json"
        source = root / "scripts/deploy-production.py"
        if deployed and last.exists() and json.loads(last.read_text()).get("commit") == payload["commit"] and source.exists():
            atomic_private_write(Path('/usr/local/libexec/blue_green.py'), (root / 'scripts/blue_green.py').read_text())
            atomic_private_write(Path("/usr/local/libexec/cloudwiki-deploy-production.py"), source.read_text())
            ssh_source = root / 'scripts/deploy-ssh'
            if ssh_source.is_file():
                ssh_target = Path('/usr/local/sbin/cloudwiki-deploy-ssh')
                atomic_private_write(ssh_target, ssh_source.read_text())
                ssh_target.chmod(0o755)
            if (state / 'editor').is_dir():
                for name, target in [('live-source-permissions.py', 'cloudwiki-live-permissions.py'),
                                     ('reload-live-code.py', 'cloudwiki-reload-code.py')]:
                    utility = root / 'scripts' / name
                    if utility.is_file():
                        atomic_private_write(Path('/usr/local/libexec') / target, utility.read_text())
            wildcard = root / 'scripts/wildcard-tls.py'
            if (state / 'wildcard').is_dir() and wildcard.is_file():
                atomic_private_write(Path('/usr/local/libexec/cloudwiki-wildcard-tls.py'), wildcard.read_text())
            dashboard_deployer = root / 'scripts/deploy-dashboard.py'
            if dashboard_deployer.is_file():
                atomic_private_write(Path('/usr/local/libexec/cloudwiki-deploy-dashboard.py'), dashboard_deployer.read_text())
            dashboard = root / 'scripts/install-diff-dashboard.py'
            if (state / 'diff').is_dir() and dashboard.is_file():
                try:
                    Runner(root).run(['python3', str(dashboard)])
                except Exception:
                    print('Release is healthy, but the diff dashboard asset refresh needs attention', file=sys.stderr)


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        # Do not include request bodies, environment files, or registry tokens.
        print("Deployment error: " + str(error), file=sys.stderr)
        sys.exit(1)
