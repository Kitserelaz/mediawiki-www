#!/usr/bin/env python3
"""Exercise Wikibase in a newly created, disposable development Compose farm."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys
import uuid

ROOT = Path(__file__).resolve().parent.parent


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--env-file', default='.env')
    parser.add_argument('--source', action='store_true', help='Test the current checkout with the built PHP runtime')
    args = parser.parse_args()
    env = os.environ.copy()
    env.update({
        'COMPOSE_PROJECT_NAME': 'cloudwiki-wb-test-' + uuid.uuid4().hex[:12],
        'MW_ENV': 'development', 'MW_DOMAIN': 'localhost', 'MW_BETA_DOMAIN': 'beta.localhost',
        'MW_S3_ENABLED': '0', 'MW_SEARCH': '0', 'MW_REMOTE_REPOSITORIES': '0',
    })
    compose = ['docker', 'compose', '--env-file', args.env_file, '-f', 'compose.yaml']
    if args.source:
        compose += ['-f', 'compose.development.yaml', '-f', 'docker/tests/upgrade-source.compose.yaml']
    else:
        compose += ['-f', 'docker/tests/upgrade-isolated.compose.yaml']

    def run(args, capture=False):
        try:
            return subprocess.run(compose + args, cwd=ROOT, env=env, check=True,
                                  text=True, stdout=subprocess.PIPE if capture else None)
        except subprocess.CalledProcessError as error:
            if error.stdout:
                print(error.stdout, file=sys.stderr)
            raise

    def fixture(component, script, extra_env=()):
        command = ['run', '--rm', '--no-deps', '--user', 'www-data',
                   '-e', 'MW_CONFIG_FILE=/opt/catalogue-settings.php',
                   '-e', 'MW_CATALOGUE_COMPONENT=' + component]
        for item in extra_env:
            command += ['-e', item]
        for name in ['catalogue-settings.php', 'catalogue-classes.php', script]:
            command += ['-v', str(ROOT / 'docker/tests' / name) + ':/opt/' + name + ':ro']
        return command + ['init', 'php', 'maintenance/run.php', '/opt/' + script, '--wiki=testwiki']

    try:
        print('Creating isolated test farm ' + env['COMPOSE_PROJECT_NAME'], flush=True)
        run(['up', '-d', '--wait', 'database', 'redis', 'memcached'])
        run(['run', '--rm', '--no-deps', 'init', 'php', '/opt/farm/manage.php', 'fresh'])
        target = 'extensions/WikibaseLexeme/extension.json'
        run(['run', '--rm', '--no-deps', '--user', 'www-data',
             '-e', 'MW_WIKIBASE_TEST_ALLOW_WRITES=1',
             '-v', str(ROOT / 'docker/tests/wikibase-managewiki-enable.php') + ':/opt/wikibase-managewiki-enable.php:ro',
             'init', 'php', 'maintenance/run.php', '/opt/wikibase-managewiki-enable.php', '--wiki=testwiki'])
        for number in (1, 2):
            run(['run', '--rm', '--no-deps', '--user', 'www-data', 'init', 'php',
                 'maintenance/run.php', 'update', '--wiki=testwiki', '--quick'])
            print('Schema update pass ' + str(number) + ' succeeded', flush=True)
        for component in ['extensions/Wikibase/extension-client.json',
                          'extensions/Wikibase/extension-repo.json', target]:
            result = run(fixture(component, 'catalogue-registration.php'), capture=True)
            evidence = []
            for line in result.stdout.splitlines():
                try:
                    value = json.loads(line)
                    if value.get('manifest') == component:
                        evidence.append(value)
                except (ValueError, AttributeError):
                    pass
            if len(evidence) != 1 or evidence[0].get('registration') != 'passed':
                raise RuntimeError('Missing registration evidence for ' + component)
            print('Registration passed: ' + component, flush=True)
        for script in ['wikibase-migration.php', 'wikibase-assets.php']:
            run(['run', '--rm', '--no-deps', '--user', 'www-data',
                 '-e', 'MW_WIKIBASE_TEST_ALLOW_WRITES=1',
                 '-v', str(ROOT / 'docker/tests' / script) + ':/opt/' + script + ':ro',
                 'init', 'php', 'maintenance/run.php', '/opt/' + script, '--wiki=testwiki'])
        print('Wikibase migration checks passed', flush=True)
    finally:
        subprocess.run(compose + ['down', '-v', '--remove-orphans'], cwd=ROOT, env=env, check=True)


if __name__ == '__main__':
    main()
