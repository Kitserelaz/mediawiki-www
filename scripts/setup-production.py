#!/usr/bin/env python3
"""Prepare a new production farm's configuration and distinct credentials; never deploy."""
import argparse, json, re
from pathlib import Path
from farm_secrets import create_secrets
root = Path(__file__).resolve().parent.parent
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--domain', required=True, help='Production base domain, without scheme or port')
parser.add_argument('--env-file', default='.env.production')
parser.add_argument('--secrets-directory', default='docker/secrets-production')
args = parser.parse_args()
domain = args.domain.lower().rstrip('.')
labels = domain.split('.')
if len(labels) < 2 or len(domain)>253 or domain.endswith('localhost') or any(not re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?', label) for label in labels):
    parser.error('Use a valid production DNS domain, without scheme or port')
env_file = Path(args.env_file)
if not env_file.is_absolute(): env_file = root / env_file
if env_file.exists(): parser.error('Environment file already exists; it has not been changed')
secret_dir = Path(args.secrets_directory)
if not secret_dir.is_absolute(): secret_dir = root / secret_dir
if secret_dir.resolve() == (root/'docker/secrets').resolve(): parser.error('Production credentials must be separate from development credentials')
create_secrets(secret_dir)
source = (root/'.env.production.example').read_text()
values = {
    'MW_DOMAIN': domain, 'MW_BETA_DOMAIN': 'beta.'+domain,
    'MW_SMTP_HOST': '', 'ACME_EMAIL': 'admin@'+domain,
    'MW_PUBLIC_HOSTS': json.dumps(', '.join(host+'.'+domain for host in ['meta','login','auth','test'])),
    'FARM_SECRETS_DIRECTORY': json.dumps(str(secret_dir.resolve())),
}
lines=[]
for line in source.splitlines():
    key=line.partition('=')[0]
    lines.append(key+'='+values[key] if key in values else line)
env_file.parent.mkdir(parents=True, exist_ok=True)
with env_file.open('x') as out: out.write('\n'.join(lines)+'\n')
(root/'backups').mkdir(exist_ok=True)
print('New production configuration: '+str(env_file))
print('Production admin password file: '+str(secret_dir/'admin-password'))
print('No containers started and no public certificates requested. Review ACME_EMAIL and SMTP settings before deployment.')
