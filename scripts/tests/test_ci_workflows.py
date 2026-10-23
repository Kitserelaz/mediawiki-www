"""Check the CI/deployment trust boundary and required gates without running Docker."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT=Path(__file__).parents[2]

@unittest.skipUnless(shutil.which('ruby'), 'Ruby YAML parser is unavailable')
class WorkflowTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        def read(name):
            return json.loads(subprocess.check_output(['ruby','-ryaml','-rjson','-e',
                'puts JSON.generate(YAML.load_file(ARGV[0]))',str(ROOT/'.github/workflows'/name)],text=True))
        cls.ci=read('docker.yml'); cls.deploy=read('deploy.yml')

    def test_cancel_ci_but_never_an_active_deployment(self):
        self.assertTrue(self.ci['concurrency']['cancel-in-progress'])
        self.assertNotIn('concurrency',self.deploy)
        self.assertFalse(self.deploy['jobs']['deploy']['concurrency']['cancel-in-progress'])
        self.assertNotIn('deploy',self.ci['jobs'])

    def test_every_selected_required_job_is_a_dependency_of_gate(self):
        jobs=self.ci['jobs']; gate=jobs['gate']
        for required in ['checks','build','runtime','counter','integration','queues','upstream','catalogue','production']:
            self.assertIn(required,gate['needs'])
        script=gate['steps'][0]['run']
        self.assertIn("results[name]['result'] != 'success'",script)
        self.assertIn('gate',jobs['publish']['needs'])
        self.assertIn('needs.gate.result',jobs['publish']['if'])

    def test_test_groups_are_independent_consumers_not_rebuilds(self):
        for name in ['runtime','counter','integration','queues','upstream','catalogue','production']:
            job=self.ci['jobs'][name]
            self.assertEqual(job['needs'],['changes','build'])
            self.assertTrue(any(s.get('uses')=='./.github/actions/ci-images' for s in job['steps']))
            for step in job['steps']:
                self.assertNotIn('scripts/farm build',step.get('run',''))
                self.assertNotIn('docker buildx build',step.get('run',''))

    def test_restore_stays_after_browser_data_creation(self):
        steps=self.ci['jobs']['integration']['steps']
        browser=next(i for i,s in enumerate(steps) if s.get('run')=='npm test --prefix docker/tests')
        restore=next(i for i,s in enumerate(steps) if s.get('run','').startswith('scripts/snapshot ci-restore'))
        self.assertLess(browser,restore)

    def test_privileged_handoff_accepts_only_trusted_successful_pushes(self):
        prepare=self.deploy['jobs']['prepare']
        for condition in ["conclusion == 'success'", "event == 'push'", "head_branch == 'main'",'head_repository.full_name']:
            self.assertIn(condition,prepare['if'])
        steps=self.deploy['jobs']['deploy']['steps']
        download=steps[0]['with']
        self.assertEqual(download['run-id'],'${{ github.event.workflow_run.id }}')
        self.assertIn("manifest['commit'] == os.environ['EXPECTED_COMMIT']",steps[1]['run'])
        self.assertEqual(self.deploy['jobs']['deploy']['environment']['name'],'production')
