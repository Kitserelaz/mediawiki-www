#!/usr/bin/env python3
"""Read-only acceptance checks against the deployed monitoring stack."""
import base64
import json
from pathlib import Path
import time
import urllib.request
from urllib.parse import urlencode

ROOT=Path('/opt/cloudwiki-monitoring')
def get(url, auth=False):
    r=urllib.request.Request(url,headers={'Host':'grafana.cloudwiki.us'})
    if auth:
        password=(ROOT/'secrets/admin-password').read_text().strip()
        r.add_header('Authorization','Basic '+base64.b64encode(('admin:'+password).encode()).decode())
    with urllib.request.urlopen(r,timeout=15) as response:return json.load(response)
def query(expr):
    r=get('http://127.0.0.1:9090/api/v1/query?'+urlencode({'query':expr}))
    assert r['status']=='success', r
    return r['data']['result']

def main():
    targets=get('http://127.0.0.1:9090/api/v1/targets')['data']['activeTargets']
    assert len(targets)==6,[(x['labels'],x['health']) for x in targets]
    assert all(x['health']=='up' for x in targets),targets
    print('All six Prometheus targets up')
    for expr,count in [('cloudwiki_service_state{kind="container"}',13),('cloudwiki_service_state{kind="unit"}',3),('probe_success{job="blackbox-public"}',3)]:
        result=query(expr)
        assert len(result)==count,(expr,result)
        if expr.startswith('probe_success'):assert all(x['value'][1]=='1' for x in result),result
        print(expr, 'series:',len(result))
    assert query('cloudwiki_collector_success')[0]['value'][1]=='1'
    assert time.time()-float(query('cloudwiki_snapshot_timestamp_seconds')[0]['value'][1])<60
    for file in (ROOT/'dashboards').glob('*.json'):
        dashboard=json.loads(file.read_text())
        remote=get('http://127.0.0.1:3000/api/dashboards/uid/'+dashboard['uid'],True)
        assert len(remote['dashboard']['panels'])==len(dashboard['panels'])
        for panel in dashboard['panels']:
            for target in panel.get('targets',[]):
                expr=target['expr'].replace('$__rate_interval','2m').replace('$__range','5m').replace('$service','.*')
                result=query(expr)
                assert result,(dashboard['title'],panel['title'],expr)
        print('Dashboard queries verified:',dashboard['title'])
    print('Grafana health:',get('http://127.0.0.1:3000/api/health'))
    print('All dashboard acceptance checks passed')

if __name__=='__main__':main()
