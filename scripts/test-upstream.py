#!/usr/bin/env python3
"""Run both pinned extensions' complete upstream suite in a reserved Docker project."""
import argparse
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parent.parent


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--env-file', default='.env')
    parser.add_argument('--project', default='cloudwiki-upstream-tests')
    parser.add_argument('--keep-running', action='store_true', help='Retain test services for debugging')
    args = parser.parse_args()
    if not re.fullmatch(r'cloudwiki-upstream-[a-z0-9][a-z0-9_-]*', args.project):
        parser.error('Use the reserved cloudwiki-upstream- project prefix')
    base = ['docker', 'compose', '--env-file', args.env_file, '-p', args.project,
            '-f', 'compose.yaml', '-f', 'docker/tests/upgrade-isolated.compose.yaml']
    tests = base + ['-f', 'docker/tests/upstream.compose.yaml']

    def run(command):
        subprocess.run(command, cwd=ROOT, check=True)

    config = json.loads(subprocess.check_output(base + ['--profile', 'tools', 'config', '--format', 'json'], cwd=ROOT))
    if config['services']['init']['environment']['MW_ENV'] != 'development':
        parser.error('Upstream integration tests require development credentials/configuration')
    # Core requires this generated override even when a restricted suite is used.
    (ROOT / 'mediawiki/phpunit.xml').touch(exist_ok=True)
    try:
        run(base + ['up', '-d', '--wait', 'database', 'redis', 'memcached'])
        run(base + ['run', '--rm', '--no-deps', 'init', 'php', '/opt/farm/manage.php', 'fresh'])
        run(tests + ['run', '--rm', '--no-deps', 'init', 'php', '/opt/upstream-reset.php'])
        run(tests + ['run', '--rm', '--no-deps', '--user', 'www-data', 'init', 'php',
                     'maintenance/run.php', 'update', '--wiki=testwiki', '--quick', '--doshared'])
        run(tests + ['run', '--rm', '--no-deps', 'init', 'php', 'tests/phpunit/generatePHPUnitConfig.php'])
        run(tests + ['run', '--rm', '--no-deps', '--user', 'www-data', 'init', 'php',
                     'vendor/bin/phpunit', '-c', 'phpunit-upgrade.xml', '--do-not-cache-result'])
    finally:
        if not args.keep_running:
            run(base + ['down', '--remove-orphans'])
    print('Complete CreateWiki/ManageWiki upstream suite passed in the reserved test project.')


if __name__ == '__main__':
    main()
