import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch, Mock

ROOT = Path(__file__).parents[2]
def load(name, file):
    spec = importlib.util.spec_from_file_location(name, ROOT / 'scripts' / file)
    module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
    return module
routing = load('ci_routing', 'ci-changes.py')
images = load('ci_images', 'ci-images.py')
dashboard = load('deploy_dashboard', 'deploy-dashboard.py')


class RoutingTests(unittest.TestCase):
    def test_docs_do_not_start_images_or_browser_tests(self):
        self.assertEqual(routing.classify(['docs/DOCKER.md', 'README.md']),
                         {'runtime': False, 'wiki': False, 'dashboard': False, 'checks': False})

    def test_dashboard_uses_fast_checks_only(self):
        self.assertEqual(routing.classify(['docker/diff/public/app.js', 'scripts/update-diff-dashboard.py']),
                         {'runtime': False, 'wiki': False, 'dashboard': True, 'checks': True})

    def test_counter_does_not_run_wiki_tests(self):
        result = routing.classify(['Moe-Counter/index.js'])
        self.assertTrue(result['runtime']); self.assertFalse(result['wiki'])

    def test_runtime_pending_before_docs_push_still_gets_full_suite(self):
        result = routing.classify(['mediawiki/ConfigSettings.php', 'docs/DOCKER.md'])
        self.assertTrue(result['wiki']); self.assertTrue(result['runtime'])

    def test_unknown_paths_runtime_inputs_and_manual_runs_are_conservative(self):
        for path in ['docs/php-syntax-exceptions.json', '.github/workflows/docker.yml', 'compose.yaml', 'new-dependency.lock']:
            self.assertTrue(routing.classify([path])['wiki'], path)
        self.assertTrue(routing.classify([], force=True)['wiki'])

    def test_unavailable_production_base_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            event = Path(directory) / 'event.json'; event.write_text('{}')
            output = Path(directory) / 'output'
            with patch.dict(os.environ, {'GITHUB_EVENT_PATH': str(event), 'GITHUB_EVENT_NAME': 'push',
                    'GITHUB_REF': 'refs/heads/main', 'GITHUB_SHA': 'a' * 40, 'GITHUB_OUTPUT': str(output)}), \
                    patch.object(routing.urllib.request, 'urlopen', side_effect=OSError('unavailable')):
                routing.main()
            self.assertIn('wiki=true', output.read_text())

    def test_main_uses_live_revision_instead_of_only_the_last_push(self):
        with tempfile.TemporaryDirectory() as directory:
            event=Path(directory)/'event.json';event.write_text(json.dumps({'before':'c'*40}))
            output=Path(directory)/'output'
            response=Mock();response.__enter__=Mock(return_value=response);response.__exit__=Mock(return_value=False)
            response.read.return_value=json.dumps({'deployed_commit':'b'*40}).encode()
            with patch.dict(os.environ, {'GITHUB_EVENT_PATH':str(event),'GITHUB_EVENT_NAME':'push',
                    'GITHUB_REF':'refs/heads/main','GITHUB_SHA':'a'*40,'GITHUB_OUTPUT':str(output)}), \
                 patch.object(routing.urllib.request,'urlopen',return_value=response), \
                 patch.object(routing.subprocess,'run'), \
                 patch.object(routing.subprocess,'check_output',return_value=b'mediawiki/LocalSettings.php\0docs/DOCKER.md\0') as diff:
                routing.main()
            self.assertEqual(diff.call_args.args[0][-2:], ['b'*40,'a'*40])
            self.assertIn('wiki=true',output.read_text())

    def test_images_are_built_once_per_service_with_isolated_caches(self):
        with tempfile.TemporaryDirectory() as directory:
            commands = []
            def build(command):
                commands.append(command)
                metadata = Path(command[command.index('--metadata-file') + 1])
                metadata.write_text(json.dumps({'containerimage.digest': 'sha256:' + 'b' * 64}))
            with patch.dict(os.environ, {'GITHUB_EVENT_NAME':'push', 'GITHUB_REF':'refs/heads/main',
                    'GITHUB_REPOSITORY':'Kitserelaz/mediawiki-www', 'GITHUB_SHA':'a' * 40}), \
                    patch.object(images, 'input_key', return_value='c'*64), \
                    patch.object(images, 'reusable_image', return_value=None), patch.object(images, 'run', side_effect=build):
                images.build('farm', Path(directory))
            self.assertEqual(len(commands), 2)
            for command in commands:
                self.assertIn('--push', command)
                service = command[command.index('--target')+1]
                self.assertIn('type=gha,version=2,scope=cloudwiki-'+service+'-amd64,mode=max', command)
            manifest = json.loads((Path(directory) / 'farm.json').read_text())
            self.assertEqual(set(manifest), {'php', 'web'})
            self.assertIn('@sha256:', manifest['php']['image'])

    def test_pull_requests_use_image_artifacts_without_registry_push(self):
        with tempfile.TemporaryDirectory() as directory:
            commands=[]
            def build(command):
                commands.append(command)
                Path(command[command.index('--metadata-file')+1]).write_text('{}')
            with patch.dict(os.environ, {'GITHUB_EVENT_NAME':'pull_request','GITHUB_REF':'refs/pull/1/merge',
                    'GITHUB_REPOSITORY':'Kitserelaz/mediawiki-www','GITHUB_SHA':'a'*40}), \
                    patch.object(images,'input_key',return_value='c'*64), \
                    patch.object(images,'reusable_image') as reuse, patch.object(images,'run',side_effect=build):
                images.build('counter',Path(directory))
                reuse.assert_not_called()
            self.assertNotIn('--push',commands[0])
            self.assertEqual(json.loads((Path(directory)/'counter.json').read_text())['counter'],{'archive':'counter.tar'})

    def test_test_jobs_pull_exact_digests_and_never_build(self):
        with tempfile.TemporaryDirectory() as directory:
            (Path(directory)/'counter.json').write_text(json.dumps({'counter':{'image':'ghcr.io/example/counter@sha256:abc'}}))
            with patch.object(images,'run') as run:
                images.load(Path(directory),'counter')
            self.assertEqual(run.call_args_list[0].args[0],['docker','pull','ghcr.io/example/counter@sha256:abc'])
            self.assertFalse(any('build' in c.args[0] for c in run.call_args_list))

    def test_dashboard_command_accepts_only_commit_and_fixed_public_sources(self):
        self.assertEqual(dashboard.validate({'commit':'a'*40}), 'a'*40)
        for payload in [{'commit':'../private'}, {'commit':'a'*40,'path':'PrivateSettings.php'}, []]:
            with self.assertRaises(ValueError): dashboard.validate(payload)
        self.assertEqual(set(dashboard.FILES.values()), {'public/index.html','public/app.js','public/style.css','update.py'})


    def test_fast_dashboard_export_cannot_modify_application_or_credentials(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)/'app'; state=Path(directory)/'state'; root.mkdir(); state.mkdir()
            (root/'LocalSettings.php').write_text('private application config')
            public=state/'diff/public';public.mkdir(parents=True)
            for target in dashboard.FILES.values():
                (state/'diff'/target).write_text('previous public asset')
            token=state/'diff/github-token';token.write_text('private-token')
            guard=Mock(REPOSITORY='https://github.com/example/repo.git')
            runner=guard.Runner.return_value
            def command(args, **kwargs):
                if args[:3]==['git','config','--get']: return guard.REPOSITORY
                if args[:2]==['git','rev-parse']: return 'a'*40
            runner.run.side_effect=command
            with patch.object(dashboard.subprocess,'check_output',return_value=b'# public source\n'):
                dashboard.deploy('a'*40,root,state,guard)
            self.assertEqual((root/'LocalSettings.php').read_text(),'private application config')
            self.assertEqual(token.read_text(),'private-token')
            self.assertFalse(any('checkout' in c.args[0] for c in runner.run.call_args_list))
            self.assertEqual((public/'app.js').read_text(),'# public source\n')
