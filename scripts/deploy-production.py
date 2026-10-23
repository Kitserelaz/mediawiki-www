#!/usr/bin/env python3
"""Restricted SSH entry point for the cloudwiki.us production deployment."""
import contextlib
import fcntl
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


def deploy(payload, root, state, runner, health=probe):
    commit = payload["commit"]
    env_file = root / ".env.production"
    old_environment = env_file.read_text()
    if "MW_ENV=production" not in old_environment.splitlines():
        raise RuntimeError("Production configuration is required")
    if runner.run(["git", "status", "--porcelain", "--untracked-files=no"], capture=True):
        raise RuntimeError("Tracked production files have local changes; refusing to overwrite them")
    origin = runner.run(["git", "config", "--get", "remote.origin.url"], capture=True)
    if origin != REPOSITORY:
        raise RuntimeError("Unexpected production Git origin")
    runner.run(["git", "fetch", "--no-tags", "origin", "main"])
    if runner.run(["git", "rev-parse", "FETCH_HEAD"], capture=True) != commit:
        print("A newer main commit exists; skipping this superseded deployment", flush=True)
        return
    old_commit = runner.run(["git", "rev-parse", "HEAD"], capture=True)
    resolved = runner.run(['docker', 'compose', '--env-file', str(env_file), 'config', '--environment'], capture=True)
    settings = dict(line.split('=', 1) for line in resolved.splitlines() if '=' in line)
    mode = settings.get('MW_CODE_MODE', 'image')
    if mode not in ('image', 'bind'):
        raise RuntimeError('MW_CODE_MODE must be image or bind')
    last = state / "last-success.json"
    if last.exists():
        previous = json.loads(last.read_text())
        source_matches = mode == 'image' or (settings.get('MW_SOURCE_DIRECTORY') == str(state / 'releases' / commit)
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
    source_directory = prepare_release(commit, root, state, runner) if mode == 'bind' else None
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
    frozen = False
    changed = False
    try:
        frozen = True
        runner.run(["scripts/snapshot", "--env-file", str(env_file), "--keep-stopped", snapshot], input="")
        backup = root / "backups" / snapshot
        atomic_private_write(backup / "production.env", old_environment)
        atomic_private_write(backup / "deployment.json", json.dumps({"previous_commit": old_commit, "target_images": payload["images"]}, indent=2) + "\n")
        runner.run(["git", "checkout", "--detach", commit])
        changed = True
        if (root / 'scripts/compose').is_file():
            compose = ['python3', 'scripts/compose', '--env-file', str(env_file)]
        atomic_private_write(env_file, new_environment)
        runner.run(compose + ["config", "--quiet"])
        runner.run(compose + ["up", "-d", "--no-build", "--wait", "database", "redis", "memcached"])
        runner.run(["scripts/farm", "--env-file", str(env_file), "update"], input="")
        runner.run(compose + ["up", "-d", "--no-build", "--wait", "--wait-timeout", "180"])
        health(root, runner)
        record = {"commit": commit, "images": payload["images"], "previous_commit": old_commit, "snapshot": snapshot,
                  'code_mode': mode, 'source_directory': str(source_directory) if source_directory else None}
        history = state / "history"
        history.mkdir(mode=0o700, exist_ok=True)
        atomic_private_write(history / (snapshot + ".json"), json.dumps(record, indent=2) + "\n")
        atomic_private_write(last, json.dumps(record, indent=2) + "\n")
        frozen = False
        print("Deployment healthy: " + commit + "; recovery snapshot: backups/" + snapshot, flush=True)
        try:
            trim_history(root, state, runner)
        except Exception:
            print("Release is healthy, but deployment retention cleanup needs attention", file=sys.stderr)
    except Exception:
        if frozen:
            # Schema changes may already have happened. Never automatically run
            # the previous code against partially migrated databases.
            with contextlib.suppress(Exception):
                runner.run(compose + ["stop", *WRITERS])
            print("Deployment failed with writers stopped. Recovery snapshot: backups/" + snapshot, file=sys.stderr)
            if not changed:
                print("The application checkout and image settings were not changed.", file=sys.stderr)
        raise


def main():
    if len(sys.argv) != 2:
        raise ValueError("A restricted SSH command is required")
    body = sys.stdin.read(16385)
    if len(body) > 16384:
        raise ValueError("Deployment request is too large")
    payload = validate_request(sys.argv[1], json.loads(body))
    if os.geteuid() != 0:
        raise RuntimeError("The deployment entry point must run through sudo")
    root = Path("/var/www")
    state = Path("/var/lib/cloudwiki")
    state.mkdir(mode=0o700, exist_ok=True)
    with (state / "deploy.lock").open("w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        deploy(payload, root, state, Runner(root))
        # Keep the separately installed SSH entry point in sync after a successful
        # release, without giving the SSH key an arbitrary shell command.
        last = state / "last-success.json"
        source = root / "scripts/deploy-production.py"
        if last.exists() and json.loads(last.read_text()).get("commit") == payload["commit"] and source.exists():
            atomic_private_write(Path("/usr/local/libexec/cloudwiki-deploy-production.py"), source.read_text())


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        # Do not include request bodies, environment files, or registry tokens.
        print("Deployment error: " + str(error), file=sys.stderr)
        sys.exit(1)
