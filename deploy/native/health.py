#!/usr/bin/env python3
"""Cheap native application health; no repeated Compose processes or Docker probes."""
import importlib.util,json,time,urllib.request
from pathlib import Path
s=importlib.util.spec_from_file_location('native','/usr/local/libexec/cloudwiki-native.py');c=importlib.util.module_from_spec(s);s.loader.exec_module(c)
result={}
for name,kind in [('worker','jobs'),('provisioner','provision'),('jobchron','jobchron')]:
    path=c.RUN/('farm-jobchron-heartbeat' if name=='jobchron' else 'farm-dispatch-'+kind+'-heartbeat')
    try:result['cloudwiki-'+name+'.service']='healthy' if time.time()-int(path.read_text())<(120 if name=='jobchron' else 45) else 'unhealthy'
    except (OSError,ValueError):result['cloudwiki-'+name+'.service']='unhealthy'
for name,url in [('counter','http://127.0.0.1:3001/heart-beat'),('creation-events','http://127.0.0.1:8091/health'),('editor','http://127.0.0.1:8085/healthz'),('grafana','http://127.0.0.1:3000/api/health'),('prometheus','http://127.0.0.1:9090/-/ready')]:
    try:
        with urllib.request.urlopen(url,timeout=3) as response:result['cloudwiki-'+name+'.service']='healthy' if response.status in (200,204) else 'unhealthy'
    except Exception:result['cloudwiki-'+name+'.service']='unhealthy'
c.write_json(c.DATA/'health.json',{'collectedAt':int(time.time()),'services':result});(c.DATA/'health.json').chmod(0o644)
