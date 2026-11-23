#!/usr/bin/env python3
"""Install native configuration and disabled services; never migrate or activate data."""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import pwd
import grp
import shutil
import subprocess
import tempfile

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('native', HERE/'control.py')
c=importlib.util.module_from_spec(spec); spec.loader.exec_module(c)

PACKAGES=['php8.5-fpm','php8.5-cli','php8.5-intl','php8.5-mysql','php8.5-mbstring','php8.5-xml','php8.5-curl',
          'php8.5-gd','php8.5-zip','php8.5-gmp','php8.5-bcmath','php8.5-ldap','php8.5-redis','php8.5-memcached',
          'php8.5-apcu','redis-server','memcached','nodejs','npm','python3-aiohttp','python3-redis',
          'imagemagick','ffmpeg','lua5.1','zip','unzip','diffutils','fonts-freefont-ttf','librsvg2-bin',
          'ghostscript','graphviz','ploticus','curl','ca-certificates','mariadb-client','kakasi','poppler-utils',
          'djvulibre-bin','libtiff-tools','python3-pygments','7zip','zstd','rsync','acl','gnupg','certbot','python3-certbot-dns-cloudflare','make','g++']


def inspect(name):
    return json.loads(c.run(['docker','inspect',name]))[0]


def user(name):
    try: return pwd.getpwnam(name)
    except KeyError:
        c.run(['useradd','--system','--home-dir','/var/lib/cloudwiki-native/'+name,'--shell','/usr/sbin/nologin',name])
        return pwd.getpwnam(name)


def write(path,text,mode=0o644,owner=None):
    path=Path(path);path.parent.mkdir(parents=True,exist_ok=True)
    path.write_text(text);path.chmod(mode)
    if owner:shutil.chown(path,owner,owner)


def mount(name,target):
    entries=[m['Source'] for m in inspect(name)['Mounts'] if m['Destination']==target]
    if len(entries)!=1:raise RuntimeError('Missing or ambiguous live mount '+target)
    return entries[0]


def service(name,command,account='www-data',memory='256M',extra='',environment=True):
    env='EnvironmentFile=/etc/cloudwiki/production.env\n' if environment else ''
    text=f'''[Unit]
Description=CloudWiki native {name}
After=network-online.target
Wants=network-online.target
StartLimitIntervalSec=60
StartLimitBurst=5
[Service]
Type=simple
User={account}
Group={account}
{env}WorkingDirectory=/srv/cloudwiki/current/mediawiki
ExecStart={command}
Restart=on-failure
RestartSec=5
TimeoutStopSec=60
KillMode=mixed
UMask=0027
MemoryMax={memory}
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
ReadWritePaths=/var/lib/cloudwiki-native /run/cloudwiki-native
{extra}
[Install]
WantedBy=multi-user.target
'''
    write('/etc/systemd/system/cloudwiki-'+name+'.service',text)


def packages():
    # Suppress package auto-start without changing the running host database.
    policy=Path('/usr/sbin/policy-rc.d'); previous=policy.read_bytes() if policy.exists() else None
    mode=policy.stat().st_mode & 0o777 if policy.exists() else None
    try:
        write(policy,'#!/bin/sh\nexit 101\n',0o755)
        c.run(['apt-get','update'],capture=False)
        c.run(['apt-get','install','-y','--no-install-recommends',*PACKAGES],env=dict(os.environ,DEBIAN_FRONTEND='noninteractive'),capture=False,timeout=1800)
    finally:
        if previous is None:policy.unlink(missing_ok=True)
        else:policy.write_bytes(previous);policy.chmod(mode)
    # These package defaults are not the dedicated farm services.
    c.run(['systemctl','disable','redis-server','memcached','php8.5-fpm'])
    # Use Caddy's signed upstream apt repository; Ubuntu's 2.6 lacks required syntax.
    key=c.run(['curl','-fsSL','https://dl.cloudsmith.io/public/caddy/stable/gpg.key'])
    c.run(['gpg','--dearmor','--yes','-o','/usr/share/keyrings/caddy-stable-archive-keyring.gpg'],data=key)
    write('/etc/apt/sources.list.d/caddy-stable.list','deb [signed-by=/usr/share/keyrings/caddy-stable-archive-keyring.gpg] https://dl.cloudsmith.io/public/caddy/stable/deb/debian any-version main\n')
    c.run(['apt-get','update'],capture=False)
    existed=policy.read_bytes() if policy.exists() else None
    try:
        write(policy,'#!/bin/sh\nexit 101\n',0o755)
        c.run(['apt-get','install','-y','--no-install-recommends','caddy'],capture=False)
    finally:
        if existed is None:policy.unlink(missing_ok=True)
        else:policy.write_bytes(existed);policy.chmod(mode)
    c.run(['systemctl','disable','caddy'])
    if not Path('/usr/local/bin/pnpm').exists():
        c.run(['npm','install','--global','pnpm@10.11.0'],capture=False)


def proxy(env,source):
    destination=c.CONFIG/'caddy';destination.mkdir(exist_ok=True)
    main=Path('/var/lib/cloudwiki/admin/Caddyfile.production').read_text()
    main=main.replace('admin off','admin unix//run/cloudwiki-caddy/admin.sock\n    default_bind 127.0.0.1\n    http_port 18088\n    https_port 18443\n    auto_https disable_redirects')
    main=main.replace('servers :443','servers :18443')
    main=main.replace('counter:3000','127.0.0.1:3001')
    main=main.replace('/etc/caddy/',str(destination)+'/')
    main+='\nimport '+str(destination/'editor.caddy')+'\nimport '+str(destination/'wildcard.caddy')+'\n'
    main+='''\nhttps://diff.{$MW_DOMAIN} {
    root * /var/lib/cloudwiki/diff/public
    header Cache-Control no-cache
    file_server
}
http://*.{$MW_DOMAIN} {
    redir https://{host}{uri} 308
}
'''
    write(destination/'Caddyfile',main)
    c.run(['setfacl','-m','u:caddy:--x','/var/lib/cloudwiki','/var/lib/cloudwiki/diff'])
    c.run(['setfacl','-R','-m','u:caddy:r-X','/var/lib/cloudwiki/diff/public'])
    wiki=(source/'docker/caddy/wiki.caddy').read_text()
    wiki=wiki.replace('/var/www/mediawiki','/srv/cloudwiki/current/mediawiki').replace('php:9000','unix//run/cloudwiki-php/wiki.sock').replace('creation-events:8080','127.0.0.1:8091')
    wiki=wiki.replace('    route {','    route {\n        import /var/lib/cloudwiki/native-maintenance.caddy',1)
    write(destination/'wiki.caddy',wiki)
    custom=(source/'docker/caddy/custom-domains.caddy').read_text().replace('php:9001','unix//run/cloudwiki-php/custom-domains.sock').replace('/opt/farm/custom-domain-lookup.php','/srv/cloudwiki/current/docker/mediawiki/custom-domain-lookup.php')
    write(destination/'custom-domains.caddy',custom)
    editor=Path('/var/lib/cloudwiki/editor/Caddyfile').read_text().replace('code:8080','127.0.0.1:8085')
    write(destination/'editor.caddy',editor)
    tls=c.CONFIG/'tls';tls.mkdir(exist_ok=True);shutil.chown(tls,'root','caddy');tls.chmod(0o750)
    for name in ['fullchain.pem','privkey.pem']:
        shutil.copy2(Path('/var/lib/cloudwiki/wildcard/tls')/name,tls/name);shutil.chown(tls/name,'root','caddy');(tls/name).chmod(0o640)
    wildcard=Path('/var/lib/cloudwiki/wildcard/Caddyfile').read_text().replace('/run/cloudwiki-tls',str(tls))
    write(destination/'wildcard.caddy',wildcard)
    c.RUN.mkdir(parents=True,exist_ok=True);shutil.chown(c.RUN,'www-data','www-data');c.RUN.chmod(0o755)
    write(c.STATE/'native-maintenance.caddy','# online\n')
    service('caddy','/usr/bin/caddy run --config /etc/cloudwiki/caddy/Caddyfile','caddy','256M',
            'RuntimeDirectory=cloudwiki-caddy\nRuntimeDirectoryMode=0750\nReadWritePaths=/run/cloudwiki-caddy\nExecReload=/usr/bin/caddy reload --address unix//run/cloudwiki-caddy/admin.sock --config /etc/cloudwiki/caddy/Caddyfile\nEnvironment=XDG_DATA_HOME=/var/lib/cloudwiki-native/caddy XDG_CONFIG_HOME=/var/lib/cloudwiki-native/caddy-config')
    c.run(['/usr/bin/caddy','validate','--config',str(destination/'Caddyfile')],env=dict(os.environ,**env),capture=False)


def configure(source):
    for account in ['cloudwiki-db','cloudwiki-redis','cloudwiki-counter','cloudwiki-editor','cloudwiki-monitoring','wiki-admin-build']:
        user(account)
    try: grp.getgrnam('cloudwiki-fpm')
    except KeyError: c.run(['groupadd','--system','cloudwiki-fpm'])
    for account in ['www-data','caddy']: c.run(['usermod','-aG','cloudwiki-fpm',account])
    c.CONFIG.mkdir(parents=True,exist_ok=True);c.CONFIG.chmod(0o755)
    c.DATA.mkdir(parents=True,exist_ok=True);c.DATA.chmod(0o755)
    c.BASE.mkdir(parents=True,exist_ok=True)
    for name,owner in [('mysql','cloudwiki-db'),('redis','cloudwiki-redis'),('counter','cloudwiki-counter'),
                       ('editor','cloudwiki-editor'),('grafana','cloudwiki-monitoring'),('prometheus','cloudwiki-monitoring'),
                       ('uploads','www-data'),('dumps','www-data'),('farm-cache','www-data'),('localization','www-data'),
                       ('caddy','caddy'),('caddy-config','caddy'),('backups','root')]:
        directory=c.DATA/name;directory.mkdir(exist_ok=True);shutil.chown(directory,owner,owner);directory.chmod(0o750)
    php=inspect('cloudwiki-production-bg-blue-php-1')
    env=dict(item.split('=',1) for item in php['Config']['Env'] if item.startswith(('MW_','MIRAHEZE_')))
    env.pop('MW_DOCKER',None)
    env.update(MW_FARM_RUNTIME='native',MW_INSTALL_PATH='/srv/cloudwiki/current/mediawiki',MW_FARM_HELPERS='/srv/cloudwiki/current/docker/mediawiki',
               MW_DB_HOST='127.0.0.1',MW_DB_PORT='3307',MW_REDIS='127.0.0.1:16379',MW_MEMCACHED='127.0.0.1:11211',
               MW_SECRETS_DIRECTORY='/etc/cloudwiki/secrets',MW_SECRETS_FILE='/etc/cloudwiki/secrets/farm.json',
               MW_PHP_CLI='/usr/bin/php8.5',MW_FPM_SOCKET='/run/cloudwiki-php/wiki.sock',MW_UPLOAD_ROOT='/var/lib/cloudwiki-native/uploads',MW_DUMP_DIRECTORY='/var/lib/cloudwiki-native/dumps',
               MW_HEARTBEAT_DIRECTORY='/run/cloudwiki-native',MW_MAINTENANCE_FILE='/etc/cloudwiki/maintenance.json',
               MW_CREATION_PROBE_URL='http://127.0.0.1:8083/wiki-creation/probe',MW_CREATION_EVENTS_PORT='8091',MW_CREATION_EVENTS_BIND='127.0.0.1',
               MW_CLOUDFLARE_TUNNEL_ORIGIN_IP='127.0.0.1',MW_CLOUDFLARE_TUNNEL_PROXY_IP='127.0.0.1',
               MW_CLOUDFLARE_SAAS_TOKEN_FILE='/etc/cloudwiki/secrets/cloudflare-saas-token')
    # Capture ACME/public host settings without displaying credential-bearing environment.
    webenv=dict(x.split('=',1) for x in inspect('cloudwiki-production-web-1')['Config']['Env'] if '=' in x)
    for key in ['ACME_EMAIL','MW_PUBLIC_HOSTS','MW_CUSTOM_DOMAINS_ACME_CA']:
        if key in webenv:env[key]=webenv[key]
    secrets=c.CONFIG/'secrets';secrets.mkdir(exist_ok=True);shutil.chown(secrets,'root','www-data');secrets.chmod(0o750)
    for name in ['farm.json','oauth-private.key','oauth-public.key']:
        shutil.copy2(mount('cloudwiki-production-bg-blue-php-1','/run/secrets/'+name),secrets/name)
        shutil.chown(secrets/name,'root','www-data');(secrets/name).chmod(0o640)
    shutil.copy2('/var/lib/cloudwiki/custom-domains/cloudflare-runtime-token',secrets/'cloudflare-saas-token')
    shutil.chown(secrets/'cloudflare-saas-token','root','www-data');(secrets/'cloudflare-saas-token').chmod(0o640)
    write(c.CONFIG/'environment.json',json.dumps(env,indent=2)+'\n',0o640);shutil.chown(c.CONFIG/'environment.json','root','www-data')
    write(c.CONFIG/'production.env',''.join(key+'='+json.dumps(value)+'\n' for key,value in sorted(env.items())),0o600)
    schedule = (source/'docker/maintenance.json').read_text().replace('/opt/farm','/srv/cloudwiki/current/docker/mediawiki').replace('/var/www/mediawiki','/srv/cloudwiki/current/mediawiki')
    write(c.CONFIG/'maintenance.json', schedule)
    profile=Path('/etc/apparmor.d/mariadbd')
    if profile.exists():
        local=Path('/etc/apparmor.d/local/mariadbd')
        text=local.read_text() if local.exists() else ''
        start='# BEGIN CLOUDWIKI NATIVE\n';end='# END CLOUDWIKI NATIVE\n'
        if start in text: text=text.split(start)[0]+text.split(end,1)[1]
        rules='/etc/cloudwiki/mysql.cnf r,\n/var/lib/cloudwiki-native/mysql/ r,\n/var/lib/cloudwiki-native/mysql/** rwk,\n/run/cloudwiki-db/ rw,\n/run/cloudwiki-db/** rwk,\n'
        write(local,text+'\n'+start+rules+end)
        c.run(['apparmor_parser','-r',str(profile)])
    write(c.CONFIG/'mysql.cnf','''[mysqld]
user=cloudwiki-db
bind-address=127.0.0.1
port=3307
socket=/run/cloudwiki-db/mysql.sock
pid-file=/run/cloudwiki-db/mysql.pid
datadir=/var/lib/cloudwiki-native/mysql
innodb_buffer_pool_size=256M
max_connections=40
skip_name_resolve=1
''')
    service('mariadb','/usr/sbin/mariadbd --defaults-file=/etc/cloudwiki/mysql.cnf','cloudwiki-db','768M','RuntimeDirectory=cloudwiki-db\nReadWritePaths=/run/cloudwiki-db',environment=False)
    write(c.CONFIG/'redis.conf','bind 127.0.0.1\nport 16379\ndir /var/lib/cloudwiki-native/redis\nappendonly yes\nappendfsync everysec\nmaxmemory-policy noeviction\n')
    service('redis','/usr/bin/redis-server /etc/cloudwiki/redis.conf','cloudwiki-redis','256M',environment=False)
    service('memcached','/usr/bin/memcached -l 127.0.0.1 -p 11211 -m 512 -U 0','memcache','600M',environment=False)
    write(c.CONFIG/'php-fpm.conf','''[global]
pid=/run/cloudwiki-native/php-fpm.pid
error_log=syslog
syslog.ident=cloudwiki-php
syslog.facility=daemon
[wiki]
user=www-data
group=www-data
listen=/run/cloudwiki-php/wiki.sock
listen.owner=www-data
listen.group=cloudwiki-fpm
listen.mode=0660
pm=ondemand
pm.max_children=3
pm.process_idle_timeout=30s
pm.max_requests=500
clear_env=no
catch_workers_output=yes
php_admin_value[memory_limit]=512M
php_admin_value[upload_max_filesize]=100M
php_admin_value[post_max_size]=110M
php_admin_flag[display_errors]=off
php_admin_value[apc.shm_size]=128M
[custom-domains]
user=www-data
group=www-data
listen=/run/cloudwiki-php/custom-domains.sock
listen.owner=www-data
listen.group=cloudwiki-fpm
listen.mode=0660
pm=ondemand
pm.max_children=2
pm.process_idle_timeout=15s
clear_env=no
request_terminate_timeout=5s
''')
    service('php','/usr/sbin/php-fpm8.5 --nodaemonize --fpm-config /etc/cloudwiki/php-fpm.conf','www-data','1024M','RuntimeDirectory=cloudwiki-php\nRuntimeDirectoryMode=0755\nReadWritePaths=/run/cloudwiki-php')
    for name,command in [('worker','php8.5 /srv/cloudwiki/current/docker/mediawiki/worker.php'),
                         ('provisioner','php8.5 /srv/cloudwiki/current/docker/mediawiki/stream-worker.php provision'),
                         ('jobchron','php8.5 /srv/cloudwiki/current/docker/mediawiki/jobchron.php'),
                         ('scheduler','php8.5 /srv/cloudwiki/current/docker/mediawiki/scheduler.php'),
                         ('creation-events','python3 /srv/cloudwiki/current/docker/mediawiki/creation-events.py')]:
        service(name,'/usr/bin/'+command,memory='384M' if name in ('worker','provisioner') else '128M')
    counter_env=dict(x.split('=',1) for x in inspect('cloudwiki-production-counter-1')['Config']['Env'] if x.startswith(('APP_','DB_')))
    counter_env.update(APP_PORT='3001',APP_HOST='127.0.0.1',DB_PATH='/var/lib/cloudwiki-native/counter/count.db',NODE_ENV='production')
    write(c.CONFIG/'counter.env',''.join(k+'='+json.dumps(v)+'\n' for k,v in counter_env.items()),0o600)
    service('counter','/usr/bin/node /srv/cloudwiki/current/Moe-Counter/index.js','cloudwiki-counter','256M',
            'EnvironmentFile=/etc/cloudwiki/counter.env\nWorkingDirectory=/srv/cloudwiki/current/Moe-Counter',environment=False)
    editor=c.DATA/'editor';c.run(['rsync','-a','--no-specials','--no-devices','/var/lib/cloudwiki/editor/home/',str(editor)+'/'])
    c.run(['chown','-R','cloudwiki-editor:cloudwiki-editor',str(editor)])
    editor_config=Path('/var/lib/cloudwiki/editor/config.yaml').read_text()
    import re
    editor_config=re.sub(r'(?m)^bind-addr:.*','bind-addr: 127.0.0.1:8085',editor_config)
    write(c.CONFIG/'editor.yaml',editor_config,0o640);shutil.chown(c.CONFIG/'editor.yaml','root','cloudwiki-editor')
    service('editor','/usr/bin/code-server --config /etc/cloudwiki/editor.yaml --disable-proxy /var/www/mediawiki','cloudwiki-editor','768M',
            'Environment=HOME=/var/lib/cloudwiki-native/editor SHELL=/bin/bash USER=cloudwiki-editor LOGNAME=cloudwiki-editor\nProtectSystem=full\nReadWritePaths=/var/www/mediawiki\nWorkingDirectory=/var/www',environment=False)
    write('/etc/tmpfiles.d/cloudwiki-native.conf','d /run/cloudwiki-native 0755 www-data www-data -\n')
    proxy(env,source)
    acl=c.run(['getfacl','-cp','/var/www/mediawiki']).decode()
    if 'default:user:cloudwiki-editor:rwx' not in acl:
        c.run(['setfacl','-R','-m','u:cloudwiki-editor:rwX','/var/www/mediawiki'])
        c.run(['find','/var/www/mediawiki','-type','d','-exec','setfacl','-m','d:u:cloudwiki-editor:rwx','{}','+'])
    installed=Path('/usr/local/libexec/cloudwiki-native.py');shutil.copy2(HERE/'control.py',installed);installed.chmod(0o644)
    for helper in ['cli','health','renew','retention']:
        path=HERE/(helper+'.py')
        if path.exists(): shutil.copy2(path,Path('/usr/local/libexec')/('cloudwiki-native-'+helper+'.py'))
    write('/etc/systemd/system/cloudwiki-native-health.service', '[Unit]\nDescription=Collect CloudWiki application health\n[Service]\nType=oneshot\nExecStart=/usr/bin/python3 /usr/local/libexec/cloudwiki-native-health.py\nMemoryMax=64M\nCPUQuota=10%\n')
    write('/etc/systemd/system/cloudwiki-native-health.timer','[Unit]\nDescription=Refresh native application health\n[Timer]\nOnBootSec=30s\nOnUnitActiveSec=30s\n[Install]\nWantedBy=timers.target\n')
    write('/etc/cloudwiki/native-wildcard-renew.service','[Unit]\nDescription=Renew native CloudWiki wildcard certificate\nAfter=network-online.target\n[Service]\nType=oneshot\nExecStart=/usr/bin/python3 /usr/local/libexec/cloudwiki-native-renew.py\nTimeoutStartSec=600\nUMask=0077\n')
    # Replace the Docker-era renewal command only at cutover, not during staging.
    write('/etc/cloudwiki/native-retention.service','[Unit]\nDescription=Retain CloudWiki recovery artifacts and retire old Docker state\n[Service]\nType=oneshot\nExecStart=/usr/bin/python3 /usr/local/libexec/cloudwiki-native-retention.py\nTimeoutStartSec=1200\n')
    write('/etc/cloudwiki/native-retention.timer','[Unit]\nDescription=Maintain native CloudWiki recovery retention\n[Timer]\nOnBootSec=30min\nOnUnitActiveSec=24h\n[Install]\nWantedBy=timers.target\n')
    c.run(['systemctl','daemon-reload'])
    # The legacy worker must not see native mode until the actual cutover.
    write(c.CONFIG/'installed-runtime.json',json.dumps({'runtime':'native','packages':json.loads((HERE/'runtime.json').read_text())},indent=2)+'\n')
    versions = c.run(['dpkg-query','-W','-f=${Package} ${Version}\n',*PACKAGES]).decode().splitlines()
    c.write_json(c.CONFIG/'package-versions.json', dict(line.split(' ',1) for line in versions))


def main():
    parser=argparse.ArgumentParser();parser.add_argument('--packages',action='store_true');parser.add_argument('--source',type=Path,required=True)
    args=parser.parse_args()
    if os.geteuid()!=0:raise SystemExit('Run as root')
    if args.packages:packages()
    configure(args.source.resolve())
    print('Native services installed; production remains on Docker. Run migration separately.')

if __name__=='__main__':main()
