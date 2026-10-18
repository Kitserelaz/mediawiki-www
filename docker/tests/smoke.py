#!/usr/bin/env python3
"""HTTP integration checks against the local stack. Trust is scoped to Caddy's CA."""
import json, subprocess, time
from pathlib import Path
root = Path(__file__).resolve().parents[2]
def curl(host, path, expected=200):
    cert = root/'docker/secrets/local-ca.crt'
    result = subprocess.run(['curl','--connect-timeout','5','--max-time','20','--silent','--show-error','--cacert',str(cert),'--resolve',host+':443:127.0.0.1','--write-out','\n%{http_code}', 'https://'+host+path], capture_output=True, text=True)
    if result.returncode: raise AssertionError(result.stderr)
    body, status = result.stdout.rsplit('\n',1)
    assert int(status) == expected, f'{host}{path}: HTTP {status}: {body[:200]}'
    return body
for attempt in range(30):
    try:
        curl('test.localhost', '/api.php?action=query&meta=siteinfo&format=json')
        break
    except AssertionError:
        if attempt == 29: raise
        time.sleep(2)
for wiki in ['meta','test','login']:
    data = json.loads(curl(wiki+'.localhost','/api.php?action=query&meta=siteinfo&format=json'))
    assert 'query' in data, data
    for path in ['/LocalSettings.php','/PrivateSettings.php','/cw_cache/databases.php','/vendor/autoload.php','/docker/mediawiki/private.php','/images/testwiki/deleted/secret.png']:
        curl(wiki+'.localhost',path,404)
curl('missing.localhost','/',404)
assert curl('counter.localhost','/heart-beat') == 'alive'
name = 'docker-smoke'
before = json.loads(curl('counter.localhost','/record/@'+name))['num']
assert '<svg' in curl('counter.localhost','/@'+name)
after = json.loads(curl('counter.localhost','/record/@'+name))['num']
assert after == before + 1
print('Wiki routing, API, private-path protection, and persistent counter smoke checks passed.')
