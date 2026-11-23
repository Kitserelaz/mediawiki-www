#!/usr/bin/env python3
"""Install dashboard additions into the existing private monitoring stack."""
import datetime
import json
import os
from pathlib import Path
import shutil
import subprocess

SOURCE = Path(__file__).resolve().parent
ROOT = Path('/opt/cloudwiki-monitoring')
STATE = Path('/var/lib/cloudwiki-monitoring')
COMPOSE = ['docker', 'compose', '-f', str(ROOT/'compose.json')]

def run(*args):
    subprocess.run(list(args), check=True)

def copy_owned(source, destination):
    result = shutil.copy2(source, destination)
    st = os.stat(source)
    os.chown(destination, st.st_uid, st.st_gid)
    return result

def main():
    if os.geteuid() != 0:
        raise SystemExit('Run on the production server with sudo')
    if shutil.disk_usage('/').free < 5 * 1024**3:
        raise SystemExit('Less than 5 GiB disk free; refusing rollout')
    if not (ROOT/'compose.json').is_file():
        raise SystemExit('Existing monitoring installation required')
    backup = STATE/'backups'/datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    backup.parent.mkdir(parents=True,exist_ok=True)
    shutil.copytree(ROOT, backup, copy_function=copy_owned)
    backup.chmod(0o700)
    for unit in ['cloudwiki-monitoring-collector.service','cloudwiki-monitoring-collector.timer']:
        if (Path('/etc/systemd/system')/unit).exists():
            raise SystemExit('Collector already installed; use a reviewed update procedure')
    textfile = STATE/'textfile'
    textfile.mkdir(parents=True,exist_ok=True)
    STATE.chmod(0o755)
    textfile.chmod(0o755)
    image = json.loads(subprocess.check_output(['docker','image','inspect','prom/blackbox-exporter:latest']))[0]['RepoDigests'][0]
    config = json.loads((ROOT/'compose.json').read_text())
    config['services']['node-exporter']['command'] += ['--collector.textfile.directory=/textfile']
    config['services']['node-exporter']['volumes'] += [str(textfile)+':/textfile:ro']
    config['services']['grafana']['environment']['GF_DASHBOARDS_DEFAULT_HOME_DASHBOARD_PATH']='/etc/grafana/dashboards/overview.json'
    config['services']['blackbox-exporter']={'image':image,'restart':'unless-stopped','network_mode':'host','cap_drop':['ALL'],'security_opt':['no-new-privileges:true'],'read_only':True,'mem_limit':'128m','cpus':0.25,'command':['--config.file=/etc/blackbox/config.yml','--web.listen-address=127.0.0.1:9115'],'volumes':['./blackbox.json:/etc/blackbox/config.yml:ro'],'logging':{'driver':'json-file','options':{'max-size':'5m','max-file':'2'}}}
    prometheus = json.loads((ROOT/'prometheus.json').read_text())
    prometheus['scrape_configs'].append({'job_name':'blackbox-exporter','static_configs':[{'targets':['127.0.0.1:9115']}]})
    prometheus['scrape_configs'].append({'job_name':'blackbox-public','scrape_interval':'30s','scrape_timeout':'12s','metrics_path':'/probe','static_configs':[{'targets':['https://'+site+'.cloudwiki.us/'],'labels':{'site':site,'module':'cloudwiki_'+site}} for site in ['meta','admin','grafana']],'relabel_configs':[{'source_labels':['__address__'],'target_label':'__param_target'},{'source_labels':['module'],'target_label':'__param_module'},{'source_labels':['__param_target'],'target_label':'instance'},{'target_label':'__address__','replacement':'127.0.0.1:9115'}]})
    units_written=False
    try:
        (ROOT/'collector').mkdir(exist_ok=True)
        shutil.copy2(SOURCE/'collect.py',ROOT/'collector/collect.py')
        shutil.copy2(SOURCE/'blackbox.json',ROOT/'blackbox.json')
        for dashboard in (SOURCE/'dashboards').glob('*.json'):
            shutil.copy2(dashboard,ROOT/'dashboards'/dashboard.name)
        (ROOT/'compose.json').write_text(json.dumps(config,indent=2)+'\n')
        (ROOT/'prometheus.json').write_text(json.dumps(prometheus,indent=2)+'\n')
        run(*COMPOSE,'config','--quiet')
        run(*COMPOSE,'run','--rm','--no-deps','--entrypoint','/bin/promtool','prometheus','check','config','/etc/prometheus/prometheus.yml')
        run(*COMPOSE,'run','--rm','--no-deps','blackbox-exporter','--config.file=/etc/blackbox/config.yml','--config.check')
        run('python3',str(ROOT/'collector/collect.py'))
        with (textfile/'services.prom').open('rb') as stream:
            subprocess.run(['docker','exec','-i','cloudwiki-monitoring-prometheus-1','promtool','check','metrics'],stdin=stream,check=True)
        for unit in ['cloudwiki-monitoring-collector.service','cloudwiki-monitoring-collector.timer']:
            shutil.copy2(SOURCE/unit,Path('/etc/systemd/system')/unit)
        units_written=True
        run('systemctl','daemon-reload')
        run('systemctl','start','cloudwiki-monitoring-collector.service')
        run('systemctl','enable','--now','cloudwiki-monitoring-collector.timer')
        run(*COMPOSE,'up','-d')
        run('docker','kill','--signal','HUP','cloudwiki-monitoring-prometheus-1')
        shutil.copy2(SOURCE/'README.md',ROOT/'DASHBOARDS.md')
        shutil.copy2(SOURCE/'verify.py',ROOT/'verify-dashboards.py')
        (ROOT/'dashboard-rollout.json').write_text(json.dumps({'backup':str(backup),'blackbox_image':image},indent=2)+'\n')
        print('Dashboard rollout complete; backup:',backup,flush=True)
    except Exception:
        if units_written:
            subprocess.run(['systemctl','disable','--now','cloudwiki-monitoring-collector.timer'])
            for unit in ['cloudwiki-monitoring-collector.service','cloudwiki-monitoring-collector.timer']:
                (Path('/etc/systemd/system')/unit).unlink(missing_ok=True)
            subprocess.run(['systemctl','daemon-reload'])
        for child in ROOT.iterdir():
            if child.is_dir():shutil.rmtree(child)
            else:child.unlink()
        shutil.copytree(backup,ROOT,dirs_exist_ok=True,copy_function=copy_owned)
        subprocess.run(COMPOSE+['up','-d','--remove-orphans'])
        raise

if __name__=='__main__':main()
