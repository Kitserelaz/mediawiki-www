#!/usr/bin/env python3
"""Retain recovery state, then retire only the recorded Docker migration resources."""
import importlib.util,json,shutil,time
from pathlib import Path
s=importlib.util.spec_from_file_location('native','/usr/local/libexec/cloudwiki-native.py');c=importlib.util.module_from_spec(s);s.loader.exec_module(c)
with c.lock():
    folder=c.STATE/'native-migration';marker=folder/'completed.json'
    if not marker.exists():raise SystemExit('No completed migration')
    completed=json.loads(marker.read_text())
    if time.time()<completed['retain_docker_until']:raise SystemExit(0)
    c.probe()
    if not completed.get('docker_retired'):
        saved=json.loads((folder/'inventory.json').read_text())
        names=c.run(['docker','ps','-aq']).decode().split()
        existing=json.loads(c.run(['docker','inspect',*names])) if names else []
        selected=[row for row in existing if row['Name'].lstrip('/') in saved['containers'] or row['Name'].startswith('/cloudwiki-production-bg-')]
        if any(row['State']['Running'] for row in selected):raise RuntimeError('Recorded Docker containers are running; cleanup refused')
        if selected:c.run(['docker','rm',*[row['Id'] for row in selected]])
        other=[m['Source'] for row in existing if row not in selected for m in row['Mounts']]
        volumes=c.run(['docker','volume','ls','--format','{{.Name}}']).decode().split()
        for name in volumes:
            if not name.startswith(('cloudwiki-production_','cloudwiki-chronological-','cloudwiki-monitoring_')):continue
            v=json.loads(c.run(['docker','volume','inspect',name]))[0]
            if v['Mountpoint'] not in [value for value in saved.values() if isinstance(value,str)]:continue
            if any(path==v['Mountpoint'] or path.startswith(v['Mountpoint']+'/') for path in other):continue
            c.run(['docker','volume','rm',name])
        # Never stop a Docker daemon that has unrelated workloads.
        if c.run(['docker','ps','-q']).strip():raise RuntimeError('Unrelated Docker workloads exist; daemon retained')
        c.run(['systemctl','disable','--now','docker.service','docker.socket','containerd.service'])
        completed['docker_retired']=int(time.time());c.write_json(marker,completed)
    records=sorted((c.STATE/'history').glob('native-*.json'),key=lambda p:p.stat().st_mtime,reverse=True)
    keep={c.active().name}
    keep.update(json.loads(p.read_text())['commit'] for p in records[:2])
    for p in (c.BASE/'releases').iterdir():
        if p.is_dir() and p.name not in keep and (p/'release.json').is_file():shutil.rmtree(p)
    snapshots=sorted((c.DATA/'backups').glob('native-*'),key=lambda p:p.stat().st_mtime,reverse=True)
    for path in snapshots[3:]:
        if (path/'manifest.json').is_file():shutil.rmtree(path)
