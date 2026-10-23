#!/usr/bin/env python3
"""Verify bind mode against real images, without databases or browser tooling."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import uuid

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('source_release', ROOT / 'scripts/source-release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


def main():
    project = 'cloudwiki-source-check-' + uuid.uuid4().hex[:10]
    with tempfile.TemporaryDirectory(prefix='cloudwiki-source-check-') as temporary:
        directory = Path(temporary).resolve()
        for name in ['mediawiki/index.php', 'mediawiki/LocalSettings.php', 'docker/mediawiki/private.php',
                     'docker/mediawiki/entrypoint.sh', 'mediawiki/resources/probe.js',
                     'mediawiki/extensions/probe.js', 'mediawiki/skins/probe.js']:
            path = directory / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('source-mount-proof')
        release.prepare_mountpoints(directory)
        proof = directory / 'mediawiki/source-proof.php'
        proof.write_text('<?php return "first";')
        (directory / 'docker/mediawiki/source-proof.txt').write_text('matching-runner')
        env_file = directory / 'farm.env'
        env_file.write_text('COMPOSE_PROJECT_NAME=' + project + '\nCOMPOSE_FILE=compose.yaml\nMW_ENV=development\n'
                            'MW_CODE_MODE=bind\nMW_SOURCE_DIRECTORY=' + json.dumps(str(directory)) + '\n'
                            'FARM_SECRETS_DIRECTORY=' + json.dumps(str(ROOT / 'docker/secrets')) + '\n')
        environment = dict(os.environ, COMPOSE_PROJECT_NAME=project, COMPOSE_FILE='compose.yaml',
                           MW_ENV='development', MW_CODE_MODE='bind', MW_SOURCE_DIRECTORY=str(directory))
        base = ['python3', 'scripts/compose', '--env-file', str(env_file)]
        def run(*args, capture=False):
            return subprocess.run(base + list(args), cwd=ROOT, env=environment, text=True,
                                  stdout=subprocess.PIPE if capture else None, check=True)
        try:
            run('run', '--rm', '--no-deps', '--entrypoint', 'php', 'php', '-r',
                'if ((require "/var/www/mediawiki/source-proof.php") !== "first") exit(1); '
                'if (@file_put_contents("/var/www/mediawiki/source-proof.php", "bad") !== false) exit(2); '
                'if (file_put_contents("/var/www/mediawiki/cw_cache/source-probe", "ok") !== 2) exit(3); '
                'echo "Source is mounted read-only; cache volume is writable\\n";')
            proof.write_text('<?php return "second";')
            run('run', '--rm', '--no-deps', '--entrypoint', 'php', 'worker', '-r',
                'if ((require "/var/www/mediawiki/source-proof.php") !== "second") exit(1); '
                'echo "Host PHP edits visible without rebuilding\\n";')
            run('run', '--rm', '--no-deps', '--entrypoint', 'php', 'jobchron', '-r',
                'if (file_get_contents("/opt/farm/source-proof.txt") !== "matching-runner") exit(1);')
            for tree in ['resources', 'extensions', 'skins']:
                result = run('run', '--rm', '--no-deps', '--entrypoint', 'cat', 'web',
                             '/var/www/mediawiki/' + tree + '/probe.js', capture=True)
                assert result.stdout == 'source-mount-proof', tree
            print('Matching Caddy assets and queue-runner source verified')
        finally:
            run('--profile', '*', 'down', '--volumes', '--remove-orphans')


if __name__ == '__main__':
    main()
