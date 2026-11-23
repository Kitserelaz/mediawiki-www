import json
from pathlib import Path
import re
import unittest

class ProbeContentTests(unittest.TestCase):
    def setUp(self):
        self.modules = json.loads((Path(__file__).parents[1]/'blackbox.json').read_text())['modules']
    def accepts(self, config, body):
        return all(re.search(pattern,body) for pattern in config['fail_if_body_not_matches_regexp']) and not any(re.search(pattern,body) for pattern in config['fail_if_body_matches_regexp'])
    def test_valid_pages_and_failure_fixtures(self):
        titles={'meta':'Meta Wiki','admin':'Sign in · CloudWiki Admin','grafana':'Grafana'}
        for site,title in titles.items():
            config=self.modules['cloudwiki_'+site]['http']
            self.assertTrue(self.accepts(config,f'<html><title>{title}</title></html>'))
            for failure in ['<title>Wiki not found - CloudWiki</title>','<title>Just a moment...</title>','<title>Wrong application</title>',f'<title>{title}</title>Wiki not found']:
                self.assertFalse(self.accepts(config,failure))
    def test_http_and_tls_failures_are_not_accepted(self):
        for module in self.modules.values():
            self.assertEqual(module['http']['valid_status_codes'],[200])
            self.assertTrue(module['http']['fail_if_not_ssl'])
            self.assertFalse(module['http']['tls_config']['insecure_skip_verify'])
            self.assertEqual(module['timeout'],'10s')

if __name__=='__main__':unittest.main()
