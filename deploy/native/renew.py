#!/usr/bin/env python3
"""Renew native wildcard TLS and refresh Caddy and HAProxy certificates."""
import hashlib,importlib.util,json,os,shutil,subprocess
from pathlib import Path
s=importlib.util.spec_from_file_location('native','/usr/local/libexec/cloudwiki-native.py');c=importlib.util.module_from_spec(s);s.loader.exec_module(c)
with c.lock():
    base=Path('/var/lib/cloudwiki/wildcard')
    c.run(['certbot','renew','--non-interactive','--cert-name',c.environment()['MW_DOMAIN'],
           '--config-dir',str(base/'acme'),'--work-dir',str(base/'work'),'--logs-dir',str(base/'logs'),
           '--dns-cloudflare-credentials',str(base/'credentials/cloudflare.ini')],capture=False)
    baseline=c.STATE/'accepted-native-config.json';approved=json.loads(baseline.read_text())
    current=c.capture_config()
    if current!=approved['files']:raise RuntimeError('Resolve existing configuration drift before replacing TLS files')
    live=base/'acme/live'/c.environment()['MW_DOMAIN']
    for name in ['fullchain.pem','privkey.pem']:
        destination=c.CONFIG/'tls'/name
        shutil.copyfile(live/name,destination);shutil.chown(destination,'root','caddy');destination.chmod(0o640)
        approved['files'][str(destination)]=hashlib.sha256(destination.read_bytes()).hexdigest()
        shutil.copyfile(destination,base/'tls'/name)
    # Existing HAProxy frontends terminate admin and Grafana TLS with this wildcard.
    combined=(live/'fullchain.pem').read_bytes()+(live/'privkey.pem').read_bytes()
    for name in ['admin.pem','grafana.pem']:
        path=Path('/etc/haproxy/certs')/name;path.write_bytes(combined);path.chmod(0o600)
    c.run(['haproxy','-c','-f','/etc/haproxy/haproxy.cfg'])
    c.run(['systemctl','reload','cloudwiki-caddy','haproxy'])
    c.write_json(baseline,approved)
