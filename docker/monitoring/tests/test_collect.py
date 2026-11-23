import importlib.util
from pathlib import Path
import tempfile
import unittest
spec = importlib.util.spec_from_file_location('collector', Path(__file__).parents[1] / 'collect.py')
c = importlib.util.module_from_spec(spec)
spec.loader.exec_module(c)

class CollectorTests(unittest.TestCase):
    def sample(self, **overrides):
        item = dict(name='scheduler', kind='container', state='running', health=None,
                    cpuPercent=150.5, memoryBytes=1234, restarts=2, uptimeSeconds=10)
        item.update(overrides)
        return {'services': {'collectedAt': 100, 'items': [item], 'errors': []}}
    def test_cpu_keeps_one_core_units_and_health_absent(self):
        text = c.render(self.sample(), 110)
        self.assertIn('cloudwiki_service_cpu_percent{service="scheduler",kind="container"} 150.5', text)
        self.assertIn('cloudwiki_service_health{service="scheduler",kind="container"} -1', text)
    def test_stale_and_missing_sources_do_not_report_healthy(self):
        for data in [self.sample(), {}, {'services': None}]:
            text = c.render(data, 200)
            self.assertIn('cloudwiki_collector_success 0', text)
            self.assertNotIn('cloudwiki_service_state', text)
    def test_missing_and_unknown_are_distinct_from_stopped(self):
        for state, value in [('missing', -1), ('unknown', -2), ('ambiguous', -3), ('exited', 0)]:
            self.assertIn(f'cloudwiki_service_state{{service="scheduler",kind="container"}} {value}', c.render(self.sample(state=state), 110))
    def test_null_and_nonfinite_resources_are_omitted(self):
        text = c.render(self.sample(cpuPercent=None, memoryBytes=float('nan')), 110)
        self.assertNotIn('cloudwiki_service_cpu_percent', text)
        self.assertNotIn('cloudwiki_service_memory_bytes', text)
    def test_duplicate_and_unsafe_labels_fail(self):
        data = self.sample(); data['services']['items'] *= 2
        with self.assertRaises(ValueError): c.render(data, 110)
        with self.assertRaises(ValueError): c.render(self.sample(name='bad"label'), 110)
    def test_collection_errors_are_visible(self):
        data = self.sample(); data['services']['errors'] = ['Docker unavailable']
        self.assertIn('cloudwiki_collector_success 0', c.render(data, 110))
    def test_atomic_replace_leaves_no_temporary_metrics(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'services.prom'
            c.atomic_write(target, 'old\n'); c.atomic_write(target, 'new\n')
            self.assertEqual(target.read_text(), 'new\n')
            self.assertEqual(list(Path(directory).iterdir()), [target])

if __name__ == '__main__': unittest.main()
