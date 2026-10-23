#!/usr/bin/env python3
"""Restricted SSH entry point for the cloudwiki.us production deployment."""
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import time

REPOSITORY = "https://github.com/Kitserelaz/mediawiki-www.git"
REGISTRY = "ghcr.io/kitserelaz/mediawiki-www-"
SERVICES = ("php", "web", "counter")
WRITERS = ("web", "php", "worker", "provisioner", "jobchron", "scheduler", "counter")
IMAGE_KEYS = {"php": "MW_IMAGE", "web": "WEB_IMAGE", "counter": "COUNTER_IMAGE"}
CONFIG_BASELINE = 'accepted-local-config.json'


def validate_request(command, payload):
    if command != "deploy":
        raise ValueError("Only the deploy command is permitted")
    if not isinstance(payload, dict) or set(payload) != {"commit", "images", "registry_user", "registry_token"}:
        raise ValueError("Invalid deployment request")
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


def deploy(payload, root, state, runner, health=probe):
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
    runner.run(["git", "fetch", "--no-tags", "origin", "main"])
    if runner.run(["git", "rev-parse", "FETCH_HEAD"], capture=True) != commit:
        print("A newer main commit exists; skipping this superseded deployment", flush=True)
        return
    old_commit = runner.run(["git", "rev-parse", "HEAD"], capture=True)
    relative_paths = [str(Path(path).relative_to(root)) for path in initial_config if Path(path).is_relative_to(root)]
    tracked = runner.run(['git', 'ls-files', '-z', '--', *relative_paths], capture=True)
    tracked_paths = {str(root / path) for path in tracked.split('\0') if path}
    resolved = runner.run(['docker', 'compose', '--env-file', str(env_file), 'config', '--environment'], capture=True)
    settings = dict(line.split('=', 1) for line in resolved.splitlines() if '=' in line)
    mode = settings.get('MW_CODE_MODE', 'image')
    if mode not in ('image', 'bind'):
        raise RuntimeError('MW_CODE_MODE must be image or bind')
    strategy = settings.get('MW_BIND_STRATEGY', 'versioned')
    if strategy not in ('versioned', 'live'):
        raise RuntimeError('MW_BIND_STRATEGY must be versioned or live')
    live = mode == 'bind' and strategy == 'live'
    if live and Path(settings.get('MW_SOURCE_DIRECTORY', '')).resolve() != root.resolve():
        raise RuntimeError('Live production source must be the application checkout')
    last = state / "last-success.json"
    if last.exists():
        previous = json.loads(last.read_text())
        source_matches = mode == 'image' or (live and previous.get('bind_strategy') == 'live') or (not live and settings.get('MW_SOURCE_DIRECTORY') == str(state / 'releases' / commit)
                                             and (state / 'releases' / commit / 'release.json').is_file())
        if previous.get("commit") == commit and previous.get("images") == payload["images"] and previous.get('code_mode', 'image') == mode and source_matches:
            health(root, runner)
            print("This release is already deployed and healthy", flush=True)
            return
    new_environment = image_environment(old_environment, payload["images"])
    if shutil.disk_usage(root).free < 5 * 1024 ** 3:
        raise RuntimeError("Less than 5 GiB free; free storage before deploying")
    # Pull all images before the write freeze. Registry credentials exist only in
    # a private temporary Docker config and are discarded after the pulls.
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
    snapshot = "deploy-" + str(time.time_ns()) + "-" + commit[:12]
    compose = ['docker', 'compose', '--env-file', str(env_file)]
    # Downloads and source exports can take time. Recheck immediately before
    # maintenance so an intervening edit never gets silently overwritten.
    check_local_config(root, state, runner, initial_config)
    check_worktree(runner)
    frozen = False
    changed = False
    editor_stopped = False
    try:
        if live:
            runner.run(compose + ['stop', 'code'])
            editor_stopped = True
            check_worktree(runner)
            check_local_config(root, state, runner, initial_config)
        frozen = True
        runner.run(["scripts/snapshot", "--env-file", str(env_file), "--keep-stopped", snapshot], input="")
        check_local_config(root, state, runner, initial_config)
        check_worktree(runner)
        backup = root / "backups" / snapshot
        atomic_private_write(backup / "production.env", old_environment)
        atomic_private_write(backup / "deployment.json", json.dumps({"previous_commit": old_commit, "target_images": payload["images"]}, indent=2) + "\n")
        runner.run(["git", "checkout", "--detach", commit])
        changed = True
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
        runner.run(compose + ["up", "-d", "--no-build", "--wait", "database", "redis", "memcached"])
        runner.run(["scripts/farm", "--env-file", str(env_file), "update"], input="")
        runner.run(compose + ["up", "-d", "--no-build", "--wait", "--wait-timeout", "180"] + (["--scale", "code=0"] if live else []))
        health(root, runner)
        check_worktree(runner)
        check_local_config(root, state, runner, intended_config)
        record = {"commit": commit, "images": payload["images"], "previous_commit": old_commit, "snapshot": snapshot,
                  'code_mode': mode, 'bind_strategy': strategy if mode == 'bind' else None, 'source_directory': str(source_directory) if source_directory else None}
        history = state / "history"
        history.mkdir(mode=0o700, exist_ok=True)
        atomic_private_write(history / (snapshot + ".json"), json.dumps(record, indent=2) + "\n")
        atomic_private_write(last, json.dumps(record, indent=2) + "\n")
        save_config_baseline(state, intended_config, 'successful deployment ' + commit)
        frozen = False
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
            # Schema changes may already have happened. Never automatically run
            # the previous code against partially migrated databases.
            with contextlib.suppress(Exception):
                runner.run(compose + ["stop", *WRITERS] + (["code"] if live else []))
            print("Deployment failed with writers stopped. Recovery snapshot: backups/" + snapshot, file=sys.stderr)
            if not changed:
                print("The application checkout and image settings were not changed.", file=sys.stderr)
        raise


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
        body = sys.stdin.read(16385)
        if len(body) > 16384:
            raise ValueError("Deployment request is too large")
        payload = validate_request(sys.argv[1], json.loads(body))
        deploy(payload, root, state, Runner(root))
        # Keep the separately installed SSH entry point in sync after a successful
        # release, without giving the SSH key an arbitrary shell command.
        last = state / "last-success.json"
        source = root / "scripts/deploy-production.py"
        if last.exists() and json.loads(last.read_text()).get("commit") == payload["commit"] and source.exists():
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
