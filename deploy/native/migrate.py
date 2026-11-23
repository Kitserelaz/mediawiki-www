#!/usr/bin/env python3
"""Explicit, restartable Docker-to-native migration. No automatic volume deletion."""
import argparse
import gzip
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import time

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('installer',HERE/'install.py')
i=importlib.util.module_from_spec(spec);spec.loader.exec_module(i)
c=i.c
MIGRATION=c.STATE/'native-migration'


def inventory():
    mapping={
        'mysql':('cloudwiki-production-database-1','/var/lib/mysql'),
        'redis':('cloudwiki-production-redis-1','/data'),
        'counter':('cloudwiki-production-counter-1','/app/data'),
        'uploads':('cloudwiki-production-bg-blue-php-1','/var/www/mediawiki/images'),
        'dumps':('cloudwiki-production-bg-blue-php-1','/var/www/dumps'),
        'farm-cache':('cloudwiki-production-bg-blue-php-1','/var/www/mediawiki/cw_cache'),
        'localization':('cloudwiki-production-bg-blue-php-1','/var/www/mediawiki/cache'),
        'caddy':('cloudwiki-production-web-1','/data'),
        'caddy-config':('cloudwiki-production-web-1','/config'),
        'grafana':('cloudwiki-monitoring-grafana-1','/var/lib/grafana'),
        'prometheus':('cloudwiki-monitoring-prometheus-1','/prometheus'),
    }
    result={key:i.mount(*pair) for key,pair in mapping.items()}
    containers=json.loads(c.run(['docker','inspect',*c.run(['docker','ps','-aq']).decode().split()]))
    result['containers']=[x['Name'].lstrip('/') for x in containers if x['State']['Running'] and x['Name'].startswith(('/cloudwiki-production-','/cloudwiki-monitoring-'))]
    result['source_commit']=c.git('rev-parse','HEAD').decode().strip()
    result['active_commit']=json.loads((c.STATE/'last-success.json').read_text()).get('commit',result['source_commit'])
    return result


def cleanup():
    # Named test volumes only; Docker's dangling flag alone is not a safety check.
    ids=c.run(['docker','ps','-aq']).decode().split()
    mounts=[m['Source'] for row in json.loads(c.run(['docker','inspect',*ids])) for m in row['Mounts']]
    names=c.run(['docker','volume','ls','--format','{{.Name}}']).decode().split()
    for name in names:
        if not name.startswith(('cloudwiki-numeric-rehearsal-1122_','cloudwiki-numeric-retry-1122_')):continue
        info=json.loads(c.run(['docker','volume','inspect',name]))[0]
        path=info['Mountpoint']
        if any(p==path or p.startswith(path+'/') for p in mounts):continue
        c.run(['docker','volume','rm',name]);print('Removed obsolete rehearsal volume:',name,flush=True)
    reviews=Path('/var/lib/cloudwiki-deployment/reviews')
    for marker in reviews.glob('*/published.json'):
        record=json.loads(marker.read_text())
        if not record.get('pushed'):continue
        try:c.git('merge-base','--is-ancestor',record['commit'],'HEAD')
        except RuntimeError:continue
        path=marker.parent/'publication'
        if path.is_dir() and not path.is_symlink():
            shutil.rmtree(path);print('Removed completed publication workspace:',marker.parent.name,flush=True)


def copy_state(saved,final=False):
    owners={'redis':'cloudwiki-redis','counter':'cloudwiki-counter','caddy':'caddy','caddy-config':'caddy',
            'grafana':'cloudwiki-monitoring','prometheus':'cloudwiki-monitoring'}
    for key in ['redis','counter','uploads','dumps','farm-cache','localization','caddy','caddy-config','grafana','prometheus']:
        c.run(['rsync','-a','--delete',saved[key]+'/',str(c.DATA/key)+'/'])
        c.run(['chown','-R',owners.get(key,'www-data')+':'+owners.get(key,'www-data'),str(c.DATA/key)])
    if final:
        c.run(['rsync','-a','--delete','--no-specials','--no-devices','/var/lib/cloudwiki/editor/home/',str(c.DATA/'editor')+'/'])
        c.run(['chown','-R','cloudwiki-editor:cloudwiki-editor',str(c.DATA/'editor')])
        for name in ['uploads','dumps','farm-cache','localization']:
            (c.DATA/name).chmod(0o755)


def source_sql(query):
    # Credentials remain inside the container and never enter tool output.
    shell='umask 077; f=$(mktemp); trap "rm -f $f" EXIT; printf "[client]\\nuser=root\\npassword=%s\\n" "$(cat /run/secrets/db-root-password)" > "$f"; mariadb --defaults-extra-file="$f" --batch --skip-column-names'
    return c.run(['docker','exec','-i','cloudwiki-production-database-1','sh','-c',shell],data=query.encode())


def export_database(destination):
    databases=source_sql('SHOW DATABASES').decode().splitlines()
    databases=[name for name in databases if name not in ('mysql','sys','performance_schema','information_schema')]
    if not all(re.fullmatch('[A-Za-z0-9_]+',name) for name in databases):raise RuntimeError('Unsupported database name')
    shell='umask 077; f=$(mktemp); trap "rm -f $f" EXIT; printf "[client]\\nuser=root\\npassword=%s\\n" "$(cat /run/secrets/db-root-password)" > "$f"; mariadb-dump --defaults-extra-file="$f" --single-transaction --routines --events --triggers --hex-blob --databases '+ ' '.join(databases)
    with gzip.open(destination,'wb') as output:
        process=subprocess.Popen(['docker','exec','cloudwiki-production-database-1','sh','-c',shell],stdout=subprocess.PIPE,stderr=subprocess.DEVNULL)
        shutil.copyfileobj(process.stdout,output)
        if process.wait():raise RuntimeError('Production database export failed')
    return databases


def restore_database(backup,databases):
    c.systemctl('stop',['mariadb'])
    datadir=c.DATA/'mysql'
    if any(datadir.iterdir()):
        # This is exclusively the new migration instance, never the existing host database.
        if not (MIGRATION/'inventory.json').exists():raise RuntimeError('Migration inventory required before replacing staged data')
        for child in datadir.iterdir():
            if child.is_dir():shutil.rmtree(child)
            else:child.unlink()
    with (MIGRATION/'initialize.log').open('wb') as output:
        result=subprocess.run(['runuser','-u','cloudwiki-db','--','mariadb-install-db','--no-defaults','--datadir='+str(datadir),'--auth-root-authentication-method=socket'],stdout=output,stderr=subprocess.STDOUT)
    if result.returncode:raise RuntimeError('Native database initialization failed; see private initialize.log')
    c.systemctl('start',['mariadb'])
    for _ in range(30):
        if Path('/run/cloudwiki-db/mysql.sock').exists():break
        time.sleep(1)
    with gzip.open(backup,'rb') as source:
        result=subprocess.run(['mariadb','--socket=/run/cloudwiki-db/mysql.sock'],input=source.read(),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if result.returncode:
            (MIGRATION/'restore-error.log').write_bytes(result.stderr);raise RuntimeError('Database restore failed; see private restore-error.log')
    # Keep the existing secret; grants apply only to this dedicated native instance.
    password=json.loads((c.CONFIG/'secrets/farm.json').read_text())['db_password']
    literal="'"+password.replace('\\','\\\\').replace("'","''")+"'"
    c.sql("CREATE USER IF NOT EXISTS 'mediawiki'@'127.0.0.1' IDENTIFIED BY "+literal+"; GRANT ALL PRIVILEGES ON *.* TO 'mediawiki'@'127.0.0.1'; CREATE USER IF NOT EXISTS 'mediawiki'@'%' IDENTIFIED BY "+literal+"; GRANT ALL PRIVILEGES ON *.* TO 'mediawiki'@'%';")
    restored=c.sql('SHOW DATABASES').decode().splitlines()
    if not set(databases).issubset(restored):raise RuntimeError('Restored database inventory differs')
    expected=source_sql('SELECT COUNT(*) FROM wikidb.cw_wikis').strip()
    if c.sql('SELECT COUNT(*) FROM wikidb.cw_wikis').strip()!=expected:raise RuntimeError('Wiki registry count differs')
    c.run(['mariadb-check','--socket=/run/cloudwiki-db/mysql.sock','--check-upgrade','--all-databases'])


def auxiliary():
    root=Path('/opt/cloudwiki-native');root.mkdir(exist_ok=True)
    # Reuse the exact official release binaries already running in production.
    grafana=root/'grafana'
    if not grafana.exists():c.run(['docker','cp','cloudwiki-monitoring-grafana-1:/usr/share/grafana',str(grafana)])
    for container,source,name in [('cloudwiki-monitoring-prometheus-1','/bin/prometheus','prometheus'),
                                  ('cloudwiki-monitoring-prometheus-1','/bin/promtool','promtool'),
                                  ('cloudwiki-monitoring-node-exporter-1','/bin/node_exporter','node_exporter'),
                                  ('cloudwiki-monitoring-blackbox-exporter-1','/bin/blackbox_exporter','blackbox_exporter')]:
        c.run(['docker','cp',container+':'+source,str(root/name)]);(root/name).chmod(0o755)
    monitoring=c.CONFIG/'monitoring'
    monitoring.mkdir(exist_ok=True)
    shutil.copytree('/opt/cloudwiki-monitoring/provisioning',monitoring/'provisioning',dirs_exist_ok=True)
    shutil.copytree('/opt/cloudwiki-monitoring/dashboards',monitoring/'dashboards',dirs_exist_ok=True)
    for name in ['prometheus.json','blackbox.json']:shutil.copy2(Path('/opt/cloudwiki-monitoring')/name,monitoring/name)
    for path in monitoring.rglob('*'):
        if path.is_file():
            text=path.read_text().replace('/etc/grafana/dashboards',str(monitoring/'dashboards')).replace('/var/lib/grafana/dashboards',str(monitoring/'dashboards'))
            if path.suffix=='.json' and 'dashboards' in path.parts:
                def adapt(value):
                    if isinstance(value,str):return value.replace('kind="container"','kind="unit"').replace('Containers','Services').replace('Container CPU','Service CPU').replace('Container memory','Service memory').replace('Production containers','Native services').replace('container recreation','service restart')
                    if isinstance(value,list):return [adapt(x) for x in value]
                    if isinstance(value,dict):return {k:adapt(v) for k,v in value.items()}
                    return value
                text=json.dumps(adapt(json.loads(text)),indent=2)
            path.write_text(text)
        shutil.chown(path,'root','cloudwiki-monitoring');path.chmod(0o750 if path.is_dir() else 0o640)
    shutil.chown(monitoring,'root','cloudwiki-monitoring');monitoring.chmod(0o750)
    grafana_env=dict(x.split('=',1) for x in i.inspect('cloudwiki-monitoring-grafana-1')['Config']['Env'] if x.startswith('GF_'))
    grafana_env.update(GF_PATHS_DATA=str(c.DATA/'grafana'),GF_PATHS_HOME=str(grafana),GF_PATHS_PROVISIONING='/etc/cloudwiki/monitoring/provisioning',
                       GF_PATHS_LOGS=str(c.DATA/'grafana/log'),GF_PATHS_PLUGINS=str(c.DATA/'grafana/plugins'),
                       GF_SERVER_HTTP_ADDR='127.0.0.1',GF_SERVER_HTTP_PORT='3000')
    if 'GF_SECURITY_ADMIN_PASSWORD__FILE' in grafana_env:
        path=c.CONFIG/'grafana-admin-password';shutil.copy2('/opt/cloudwiki-monitoring/secrets/admin-password',path)
        shutil.chown(path,'root','cloudwiki-monitoring');path.chmod(0o640);grafana_env['GF_SECURITY_ADMIN_PASSWORD__FILE']=str(path)
    i.write(c.CONFIG/'grafana.env',''.join(k+'='+json.dumps(v)+'\n' for k,v in grafana_env.items()),0o600)
    i.service('grafana',str(grafana/'bin/grafana')+' server --homepath='+str(grafana),'cloudwiki-monitoring','384M',
              'EnvironmentFile=/etc/cloudwiki/grafana.env',environment=False)
    i.service('prometheus',str(root/'prometheus')+' --config.file=/etc/cloudwiki/monitoring/prometheus.json --storage.tsdb.path='+str(c.DATA/'prometheus')+' --storage.tsdb.retention.time=7d --storage.tsdb.retention.size=1GB --web.listen-address=127.0.0.1:9090','cloudwiki-monitoring','256M',environment=False)
    i.service('node-exporter',str(root/'node_exporter')+' --web.listen-address=127.0.0.1:9100 --collector.textfile.directory=/var/lib/cloudwiki-monitoring/textfile','cloudwiki-monitoring','64M',environment=False)
    i.service('blackbox-exporter',str(root/'blackbox_exporter')+' --config.file=/etc/cloudwiki/monitoring/blackbox.json --web.listen-address=127.0.0.1:9115','cloudwiki-monitoring','64M',environment=False)
    tunnel=Path('/var/www/docker/cloudflare-saas/config.yaml').read_text()
    tunnel=re.sub(r'(?m)^credentials-file:.*','credentials-file: /etc/cloudwiki/tunnel.json',tunnel)
    tunnel=re.sub(r'http://(?:web|172\.[0-9.]+):8082','http://127.0.0.1:8082',tunnel)
    i.write(c.CONFIG/'cloudflared.yaml',tunnel,0o600)
    shutil.copy2('/var/lib/cloudwiki/custom-domains/cloudflare-tunnel.json',c.CONFIG/'tunnel.json');(c.CONFIG/'tunnel.json').chmod(0o600)
    i.service('cloudflared','/usr/local/bin/cloudflared tunnel --no-autoupdate --config /etc/cloudwiki/cloudflared.yaml run','root','128M',environment=False)
    c.run(['systemctl','daemon-reload'])


def stage(commit):
    with c.lock():
        MIGRATION.mkdir(parents=True,exist_ok=True,mode=0o700)
        saved=inventory();c.write_json(MIGRATION/'inventory.json',saved)
        release=c.build(commit)
        c.activate_link(release)
        auxiliary()
        # Online copies are for rehearsal only; final state is recopied under the write freeze.
        copy_state(saved)
        databases=export_database(MIGRATION/'rehearsal.sql.gz')
        restore_database(MIGRATION/'rehearsal.sql.gz',databases)
        c.systemctl('start',['redis','memcached'])
        c.php_manage('preflight')
        c.write_json(MIGRATION/'staged.json',{'commit':commit,'created':int(time.time()),'databases':databases})
        print('Staged native data and application preflight complete. Docker still serves production.')


def cutover():
    with c.lock():
        staged=json.loads((MIGRATION/'staged.json').read_text());saved=json.loads((MIGRATION/'inventory.json').read_text())
        c.capacity(c.size(c.DATA/'mysql'))
        if c.git('status','--porcelain').strip():raise RuntimeError('Source changed during staging')
        c.progress(staged['commit'],'migration_maintenance')
        # Hold public wiki traffic on a dedicated HAProxy maintenance backend; admin stays online.
        haproxy=Path('/etc/haproxy/haproxy.cfg');old=haproxy.read_text();i.write(MIGRATION/'haproxy.cfg',old,0o600)
        c.systemctl('start',['caddy'])
        c.maintenance(True)
        next_config=old.replace('127.0.0.1:8443','127.0.0.1:18443').replace('127.0.0.1:8088','127.0.0.1:18088')
        i.write('/etc/haproxy/haproxy.cfg',next_config)
        c.run(['haproxy','-c','-f','/etc/haproxy/haproxy.cfg'])
        c.run(['systemctl','reload','haproxy'])
        c.systemctl('start',['cloudflared'])
        # All following failures retain the public maintenance response.
        writers=[n for n in saved['containers'] if n not in ('cloudwiki-production-database-1','cloudwiki-production-redis-1','cloudwiki-production-memcached-1') and not n.startswith('cloudwiki-monitoring-')]
        c.run(['docker','stop','--time','60',*writers])
        c.systemctl('stop',['redis','mariadb'])
        c.run(['docker','exec','cloudwiki-production-redis-1','redis-cli','SAVE'])
        databases=export_database(MIGRATION/'final.sql.gz')
        c.run(['docker','stop','--time','60','cloudwiki-production-redis-1',*[n for n in saved['containers'] if n.startswith('cloudwiki-monitoring-')]])
        copy_state(saved,final=True)
        restore_database(MIGRATION/'final.sql.gz',databases)
        c.systemctl('start',['redis','memcached'])
        c.php_manage('preflight')
        # No application schema changes are introduced by the transport migration itself.
        c.php_manage('refresh')
        c.systemctl('start',c.APP_UNITS)
        c.systemctl('start',['caddy','grafana','prometheus','node-exporter','blackbox-exporter','cloudflared','editor'])
        next_config=old.replace('127.0.0.1:8443','127.0.0.1:18443').replace('127.0.0.1:8088','127.0.0.1:18088')
        i.write('/etc/haproxy/haproxy.cfg',next_config)
        c.run(['haproxy','-c','-f','/etc/haproxy/haproxy.cfg'])
        c.run(['systemctl','reload','haproxy'])
        time.sleep(5)
        c.maintenance(False)
        try: c.probe()
        except Exception:
            c.maintenance(True)
            c.systemctl('stop',c.WRITERS)
            c.progress(staged['commit'],'recovery_required','failed','Native migration health check failed; maintenance retained')
            raise
        c.run(['docker','stop','--time','60','cloudwiki-production-database-1','cloudwiki-production-memcached-1'])
        manifest=json.loads((HERE/'runtime.json').read_text())
        native_units=[name+'.service' for name in manifest['units']]
        c.run(['systemctl','enable',*native_units])
        c.write_json(c.CONFIG/'runtime.json',manifest)
        shutil.copy2(c.ROOT/'scripts/deploy-ssh','/usr/local/sbin/cloudwiki-deploy-ssh')
        Path('/usr/local/sbin/cloudwiki-deploy-ssh').chmod(0o755)
        c.run(['systemctl','disable','--now','cloudwiki-code-reload.path'])
        for source,target in [('native-wildcard-renew.service','cloudwiki-wildcard-renew.service'),('native-retention.service','cloudwiki-native-retention.service'),('native-retention.timer','cloudwiki-native-retention.timer')]:
            shutil.copy2(c.CONFIG/source,Path('/etc/systemd/system')/target)
        dropin=Path('/etc/systemd/system/cloudwiki-wildcard-renew.service.d/admin-tls.conf')
        if dropin.exists(): shutil.move(dropin,MIGRATION/'legacy-admin-tls.conf')
        c.run(['systemctl','daemon-reload'])
        c.run(['systemctl','enable','--now','cloudwiki-native-health.timer','cloudwiki-native-retention.timer'])
        c.run(['systemctl','restart','cloudwiki-admin-agent','cloudwiki-deployment-worker'])

        inventory={'runtime':'native','project':'cloudwiki-production','services':[],
                   'units':['haproxy.service','wiki-admin-panel.service',*native_units]}
        c.write_json(Path('/etc/cloudwiki-admin-agent.json'),inventory)
        c.write_json(c.STATE/'last-success.json',{'runtime':'native','commit':staged['commit'],'previous_commit':saved.get('active_commit',saved['source_commit']),
                     'snapshot':str(MIGRATION),'created':int(time.time()),'schema_commit':staged['commit']})
        c.write_json(c.STATE/'history'/('native-'+str(time.time_ns())+'.json'),json.loads((c.STATE/'last-success.json').read_text()))
        c.editor_permissions(saved.get('active_commit',saved['source_commit']),staged['commit'])
        c.save_baseline('Native migration installed by SSH operator')
        c.progress(staged['commit'],'complete','success')
        c.write_json(MIGRATION/'completed.json',{'completed':int(time.time()),'retain_docker_until':int(time.time())+7*86400})
        print('Native production is active. Original Docker state retained for seven days.')


def main():
    parser=argparse.ArgumentParser();parser.add_argument('phase',choices=['cleanup','stage','cutover']);parser.add_argument('--commit')
    args=parser.parse_args()
    if os.geteuid()!=0:raise SystemExit('Run as root')
    if args.phase=='cleanup':cleanup()
    elif args.phase=='stage':stage(args.commit)
    else:cutover()

if __name__=='__main__':main()
