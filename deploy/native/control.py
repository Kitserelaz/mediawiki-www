#!/usr/bin/env python3
"""Root-owned native farm controller. Fixed paths and operations; JSON stdin bridge."""
import argparse
import contextlib
import fcntl
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.request

ROOT = Path('/var/www')
BASE = Path('/srv/cloudwiki')
DATA = Path('/var/lib/cloudwiki-native')
CONFIG = Path('/etc/cloudwiki')
STATE = Path('/var/lib/cloudwiki')
RUN = Path('/run/cloudwiki-native')
REPO = 'https://github.com/Kitserelaz/mediawiki-www.git'
WRITERS = ['worker', 'provisioner', 'jobchron', 'scheduler', 'creation-events', 'php', 'counter']
APP_UNITS = ['php', 'counter', 'creation-events', 'worker', 'provisioner', 'jobchron', 'scheduler']


def run(args, *, cwd=None, data=None, env=None, capture=True, timeout=1200):
    result = subprocess.run([str(x) for x in args], cwd=cwd, input=data,
                            stdout=subprocess.PIPE if capture else None,
                            stderr=subprocess.PIPE if capture else None,
                            env=env, timeout=timeout)
    if result.returncode:
        # Never echo subprocess environments, credential-bearing config, or SQL.
        raise RuntimeError(f'{Path(str(args[0])).name} failed (exit {result.returncode})')
    return result.stdout if capture else b''


def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            json.dump(value, stream, indent=2)
            stream.write('\n')
        os.chmod(name, 0o600)
        os.replace(name, path)
    finally:
        if os.path.exists(name): os.unlink(name)



CONFIG_BASELINE = 'accepted-native-config.json'

class Runner:
    def __init__(self, root): self.root = root

def capture_local_config(root, runner):
    return capture_config()

def save_config_baseline(state, files, reason):
    write_json(Path(state) / CONFIG_BASELINE, {'files': files, 'reason': reason, 'created': int(time.time())})


def environment():
    return json.loads((CONFIG / 'environment.json').read_text())


def active():
    return (BASE / 'current').resolve(strict=True)


def git(*args, token=None):
    env = dict(os.environ, GIT_TERMINAL_PROMPT='0', GIT_CONFIG_GLOBAL='/dev/null', GIT_CONFIG_NOSYSTEM='1')
    env.pop('CLOUDWIKI_GITHUB_TOKEN', None)
    if token:
        import base64
        env.update(GIT_CONFIG_COUNT='1', GIT_CONFIG_KEY_0='http.https://github.com/.extraheader',
                   GIT_CONFIG_VALUE_0='AUTHORIZATION: basic ' + base64.b64encode(('x-access-token:'+token).encode()).decode())
    return run(['git', '-c', 'safe.directory=/var/www', '-c', 'core.hooksPath=/dev/null', *args], cwd=ROOT, env=env)


def lock():
    STATE.mkdir(exist_ok=True, parents=True)
    stream = (STATE / 'deploy.lock').open('a')
    fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
    return stream


def progress(commit, stage, status='running', reason=None):
    old = {}
    try: old = json.loads((STATE / 'deployment-progress.json').read_text())
    except (OSError, ValueError): pass
    now = time.time()
    write_json(STATE / 'deployment-progress.json', {'runtime': 'native', 'commit': commit,
               'status': status, 'stage': stage, 'reason': reason,
               'started_at': old.get('started_at', now) if old.get('status') == 'running' else now,
               'updated_at': now, 'heartbeat_at': now})
    print(stage, flush=True)


def systemctl(action, names):
    if names: run(['systemctl', action, *['cloudwiki-'+n+'.service' for n in names]])


def maintenance(enabled):
    RUN.mkdir(parents=True, exist_ok=True)
    (STATE / 'native-maintenance.caddy').write_text('respond "CloudWiki is undergoing maintenance. Please retry shortly." 503\n' if enabled else '# online\n')
    run(['systemctl', 'reload', 'cloudwiki-caddy.service'])


def capture_config():
    # Configuration values never enter public reports; only filenames and hashes.
    paths = [p for p in CONFIG.rglob('*') if p.is_file() and not p.is_symlink()]
    paths += [p for p in Path('/etc/systemd/system').glob('cloudwiki-*') if p.is_file()]
    paths += [p for p in Path('/etc/systemd/system').glob('cloudwiki-*.service.d/*') if p.is_file()]
    paths += [p for p in [Path('/etc/haproxy/haproxy.cfg'),Path('/etc/apparmor.d/local/mariadbd'),*Path('/usr/local/libexec').glob('cloudwiki-native*.py'),Path('/usr/local/libexec/cloudwiki-farm-control.py'),Path('/usr/local/libexec/cloudwiki-admin-agent.js'),Path('/usr/local/libexec/cloudwiki-deployment-worker.js')] if p.exists()]
    return {str(p): hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(paths)}


def save_baseline(reason):
    previous = STATE / 'accepted-native-config.json'
    if previous.exists():
        target = Path('/var/lib/cloudwiki-deployment/baselines') / (str(time.time_ns())+'.json')
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(previous, target)
    write_json(previous, {'files': capture_config(), 'reason': reason, 'created': int(time.time())})


def assert_clean():
    if git('status', '--porcelain', '--untracked-files=normal').strip():
        raise RuntimeError('Production source edits must be published or resolved first')
    baseline = json.loads((STATE / 'accepted-native-config.json').read_text())['files']
    if baseline != capture_config(): raise RuntimeError('Native configuration differs from the accepted baseline')


def capacity(required):
    free = shutil.disk_usage(BASE if BASE.exists() else ROOT).free
    if free < required + 2*1024**3:
        raise RuntimeError('Insufficient disk space for staging, a recovery snapshot, and 2 GiB headroom')


def file_hash(path):
    h=hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda:stream.read(1024*1024),b''):h.update(chunk)
    return h.hexdigest()


def size(path):
    return int(run(['du', '-sb', str(path)]).split()[0]) if path.exists() else 0



def managed_reference():
    try:
        reference=active()
        record=json.loads((reference/'release.json').read_text())
        if record.get('runtime')=='native' and re.fullmatch('[a-f0-9]{40}',record.get('commit','')):
            return reference,record['commit']
    except (OSError,ValueError):pass
    return None,None


def blob_tree(commit):
    result={}
    for row in git('ls-tree','-rz',commit).split(b'\0'):
        if not row:continue
        metadata,name=row.split(b'\t',1)
        mode,kind,blob=metadata.split()
        if kind==b'blob' and mode in (b'100644',b'100755'):
            result[name.decode('utf8','surrogateescape')]=(mode,blob)
    return result


def extract_release(archive,destination,commit):
    # Managed releases are immutable and root-owned. Matching Git blobs can be
    # linked while the archive streams past, avoiding a duplicate vendor tree.
    reference,previous=managed_reference()
    old_tree=blob_tree(previous) if previous else {}
    new_tree=blob_tree(commit)
    with tarfile.open(fileobj=archive.stdout,mode='r|') as source:
        for member in source:
            filtered=tarfile.data_filter(member,str(destination))
            if filtered is None:continue
            name=Path(member.name).as_posix()
            old=reference/name if reference else None
            target=destination/name
            if member.isfile() and not name.startswith('Moe-Counter/') and old and old_tree.get(name)==new_tree.get(name) and name in new_tree and old.is_file() and not old.is_symlink() and old.resolve().is_relative_to(reference):
                info=old.stat()
                if info.st_uid==0 and not info.st_mode & 0o022 and info.st_size==member.size:
                    target.parent.mkdir(parents=True,exist_ok=True)
                    os.link(old,target)
                    continue
            source.extract(member,destination,filter='data')


def build(commit, token=None):
    if not re.fullmatch('[a-f0-9]{40}', commit): raise ValueError('Invalid commit')
    destination = BASE / 'releases' / commit
    if (destination/'release.json').exists(): return destination
    git('fetch', '--no-tags', REPO, 'refs/heads/main', token=token)
    latest = git('rev-parse', 'FETCH_HEAD').decode().strip()
    if latest != commit: raise RuntimeError('Main changed before preparation; click Deploy again')
    tree=git('ls-tree','-rl',commit).decode().splitlines()
    source_bytes=sum(int(line.split('\t',1)[0].split()[3]) for line in tree if line.split('\t',1)[0].split()[3].isdigit())
    # Source preparation and the recovery snapshot are sequential; shared immutable
    # files are deduplicated before maintenance starts.
    capacity(max(source_bytes+512*1024**2,size(DATA)))
    if destination.exists(): raise RuntimeError('Incomplete release exists; inspect it before retrying')
    destination.mkdir(parents=True, mode=0o755)
    destination.chmod(0o755)
    # git archive contains repository-controlled symlinks; extract with Python's data filter.
    archive = subprocess.Popen(['git', '-c', 'safe.directory=/var/www', '-C', str(ROOT), 'archive', '--format=tar', commit], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    try:
        extract_release(archive,destination,commit)
        if archive.wait(): raise RuntimeError('Source export failed')
        wire_release(destination)
        build_counter(destination)
        deduplicate_release(destination)
        run(['php', '-l', str(destination/'mediawiki/LocalSettings.php')])
        write_json(destination/'release.json', {'commit': commit, 'runtime': 'native', 'created': int(time.time())})
        # No dependency installer or application code receives the GitHub token.
        run(['chmod', '-R', 'a+rX,go-w', str(destination)])
        return destination
    except Exception:
        shutil.rmtree(destination, ignore_errors=True)
        raise
    finally:
        archive.stdout.close()
        if archive.poll() is None:
            archive.terminate();archive.wait()



def deduplicate_release(release, reference=None):
    """Share only identical immutable files; runtime state and editor files are excluded."""
    import filecmp, stat
    if reference is None:
        if (BASE/'current').exists(): reference=active()
        else:
            record=json.loads((STATE/'last-success.json').read_text())
            reference=Path(record.get('source_directory','/nonexistent'))
    reference=Path(reference).resolve()
    if reference==release.resolve() or not reference.is_dir():return 0
    if not (str(reference).startswith(str(BASE/'releases')+'/') or str(reference).startswith(str(STATE/'releases')+'/')):
        raise RuntimeError('Immutable reference must be a recorded release directory')
    saved=0
    for current,dirs,files in os.walk(release,followlinks=False):
        dirs[:]=[name for name in dirs if not (Path(current)/name).is_symlink()]
        for name in files:
            target=Path(current)/name;old=reference/target.relative_to(release)
            if target.is_symlink() or old.is_symlink() or not old.is_file():continue
            a=target.stat();b=old.stat()
            if a.st_uid!=0 or b.st_uid!=0 or b.st_mode & 0o022 or a.st_dev!=b.st_dev or a.st_size!=b.st_size or a.st_ino==b.st_ino:continue
            if not stat.S_ISREG(a.st_mode) or not stat.S_ISREG(b.st_mode):continue
            if not filecmp.cmp(target,old,shallow=False):continue
            temporary=target.with_name(target.name+'.native-link')
            os.link(old,temporary);os.replace(temporary,target);saved+=a.st_size
    return saved


def wire_release(release):
    mw = release/'mediawiki'
    for name, target in [('images', DATA/'uploads'), ('cw_cache', DATA/'farm-cache'), ('cache', DATA/'localization')]:
        path = mw/name
        if path.is_symlink(): path.unlink()
        elif path.is_dir(): shutil.rmtree(path)
        elif path.exists(): path.unlink()
        path.symlink_to(target, target_is_directory=True)
    helpers = mw/'docker/mediawiki'
    if helpers.is_symlink(): helpers.unlink()
    elif helpers.exists(): shutil.rmtree(helpers)
    helpers.parent.mkdir(exist_ok=True)
    helpers.symlink_to(release/'docker/mediawiki', target_is_directory=True)
    private = mw/'PrivateSettings.php'
    private.unlink(missing_ok=True)
    private.write_text("<?php\nrequire __DIR__ . '/docker/mediawiki/private.php';\n")


def build_counter(release):
    counter = release/'Moe-Counter'
    run(['chown', '-R', 'wiki-admin-build:wiki-admin-build', str(counter)])
    run(['systemd-run', '--quiet', '--wait', '--collect', '--pipe', '--unit=cloudwiki-build-'+str(time.time_ns()),
         '-p', 'User=wiki-admin-build', '-p', 'Group=wiki-admin-build', '-p', 'MemoryMax=768M',
         '-p', 'CPUQuota=80%', '-p', 'NoNewPrivileges=yes', '-p', 'ProtectSystem=strict',
         '-p', 'ReadWritePaths='+str(counter)+' /var/cache/wiki-admin-build',
         '-p', 'WorkingDirectory='+str(counter), '-p', 'Environment=HOME=/var/cache/wiki-admin-build',
         '/usr/local/bin/pnpm', 'install', '--prod', '--frozen-lockfile', '--package-import-method=copy'], timeout=1800)
    run(['chown', '-R', 'root:root', str(counter)])


def sql(statement):
    return run(['mariadb', '--socket='+'/run/cloudwiki-db/mysql.sock', '--batch', '--skip-column-names'], data=statement.encode())


def snapshot(label):
    folder = DATA/'backups'/label
    folder.mkdir(parents=True, exist_ok=False, mode=0o700)
    databases = sql('SHOW DATABASES').decode().splitlines()
    databases = [d for d in databases if d not in ('mysql','sys','information_schema','performance_schema')]
    for name in databases:
        if not re.fullmatch('[A-Za-z0-9_]+', name): raise RuntimeError('Unsupported database name')
        with gzip.open(folder/(name+'.sql.gz'), 'wb') as output:
            process = subprocess.Popen(['mariadb-dump', '--socket='+'/run/cloudwiki-db/mysql.sock', '--single-transaction',
                        '--routines', '--events', '--triggers', '--hex-blob', '--databases', name], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
            shutil.copyfileobj(process.stdout, output)
            if process.wait(): raise RuntimeError('Database snapshot failed')
    # Redis and SQLite must have been stopped before this copy.
    for name in ['uploads','dumps','redis','counter','caddy','farm-cache','localization']:
        with tarfile.open(folder/(name+'.tar.gz'), 'w:gz') as archive:
            archive.add(DATA/name, arcname=name)
    with tarfile.open(folder/'configuration.tar.gz','w:gz') as archive: archive.add(CONFIG, arcname='configuration')
    write_json(folder/'manifest.json', {'runtime':'native','commit':json.loads((active()/'release.json').read_text())['commit'],
             'created':int(time.time()),'databases':databases,
             'sha256':{p.name:file_hash(p) for p in folder.iterdir() if p.is_file()}})
    return folder


def php_manage(action):
    if action not in ('preflight','update','refresh','list'): raise ValueError('Unsupported management action')
    env = dict(os.environ, **environment())
    env.pop('CLOUDWIKI_GITHUB_TOKEN', None)
    command = ['runuser','-u','www-data','--','php',str(active()/'docker/mediawiki/manage.php'),action]
    return run(command, cwd=active()/'mediawiki', env=env, capture=False, timeout=3600)


def probe():
    for name in ['mariadb','redis','memcached',*APP_UNITS,'caddy']:
        run(['systemctl','is-active','--quiet','cloudwiki-'+name+'.service'])
    for command in [['php',str(active()/'docker/mediawiki/health.php')],
                    ['php',str(active()/'docker/mediawiki/dispatch-health.php'),'jobs'],
                    ['php',str(active()/'docker/mediawiki/dispatch-health.php'),'provision']]:
        run(['runuser','-u','www-data','--',*command], env=dict(os.environ,**environment()))
    # Probe through the actual HAProxy TLS entry point with verified public certificates.
    for host in ['meta','auth','counter']:
        domain=host+'.'+environment()['MW_DOMAIN']
        run(['curl','--fail','--silent','--show-error','--max-time','30','--resolve',domain+':443:127.0.0.1',
             'https://'+domain+('/heart-beat' if host=='counter' else ('/metawiki/w/api.php' if host=='auth' else '/api.php')+'?action=query&meta=siteinfo&format=json')])


def activate_link(release):
    temporary = BASE/('current.'+str(time.time_ns()))
    temporary.symlink_to(release, target_is_directory=True)
    os.replace(temporary,BASE/'current')



def editor_permissions(previous,commit):
    source=ROOT/'mediawiki'
    files=[];directories=set()
    for name in git('diff','--name-only','-z',previous,commit,'--','mediawiki').decode().split('\0'):
        if not name:continue
        path=ROOT/name
        if not path.exists() or path.is_symlink() or not path.resolve().is_relative_to(source):continue
        files.append(str(path))
        for parent in path.parents:
            if not parent.is_relative_to(source):break
            directories.add(str(parent))
    for values,permission in [(files,'u:cloudwiki-editor:rwX'),(sorted(directories),'u:cloudwiki-editor:rwx,d:u:cloudwiki-editor:rwx')]:
        for offset in range(0,len(values),100):run(['setfacl','-m',permission,*values[offset:offset+100]])


def deploy(commit, token=None):
    with lock():
        assert_clean()
        progress(commit,'preparing')
        try: release=build(commit,token)
        except Exception:
            progress(commit,'preparation_failed','failed','Release preparation failed; production remains online')
            raise
        previous=active()
        if release==previous:
            progress(commit,'complete','success')
            return {'message':'This commit is already active','commit':commit,'skipped':True}
        capacity(size(DATA))
        progress(commit,'maintenance')
        maintenance(True)
        data_changed=False
        try:
            systemctl('stop',WRITERS)
            systemctl('stop',['redis'])
            backup=snapshot('native-'+str(time.time_ns())+'-'+commit[:12])
            assert_clean()
            activate_link(release)
            systemctl('start',['redis'])
            php_manage('preflight')
            data_changed=True
            progress(commit,'updating')
            php_manage('update')
            systemctl('start',APP_UNITS)
            maintenance(False)
            # Readiness includes both dispatchers, allowing their first heartbeat.
            time.sleep(5)
            probe()
            record={'runtime':'native','commit':commit,'previous_commit':previous.name,'snapshot':str(backup),
                    'created':int(time.time()),'schema_commit':commit,'deployment_mode':'maintenance'}
            write_json(STATE/'history'/('native-'+str(time.time_ns())+'.json'),record)
            write_json(STATE/'last-success.json',record)
            # Keep the operator checkout aligned, only after the successful switch.
            source_before=git('rev-parse','HEAD').decode().strip()
            git('merge','--ff-only',commit)
            editor_permissions(source_before,commit)
            progress(commit,'complete','success')
            return {'message':'Native production release activated','commit':commit}
        except Exception:
            maintenance(True)
            systemctl('stop',WRITERS)
            if not data_changed:
                activate_link(previous)
                systemctl('start',['redis',*APP_UNITS])
                maintenance(False)
                progress(commit,'recovered','failed','Previous application resumed before database updates')
            else:
                progress(commit,'recovery_required','failed','Database updates began; maintenance retained for a forward repair')
            raise


def overview():
    baseline=STATE/'accepted-native-config.json'
    actual=capture_config()
    before=json.loads(baseline.read_text())['files'] if baseline.exists() else {}
    files=[{'id':hashlib.sha256(p.encode()).hexdigest()[:24],'path':p,'status':'modified',
            'before':before.get(p),'after':actual.get(p)} for p in sorted(set(before)|set(actual)) if before.get(p)!=actual.get(p)]
    last=json.loads((STATE/'last-success.json').read_text()) if (STATE/'last-success.json').exists() else {}
    history=[]
    for path in sorted((STATE/'history').glob('native-*.json'),reverse=True):
        r=json.loads(path.read_text());history.append({'id':path.stem,'commit':r['commit'],'created':r['created'],
            'rollbackEligible':False,'reason':'Use a forward release after database writes; no automatic database downgrade'})
    progress_data=json.loads((STATE/'deployment-progress.json').read_text()) if (STATE/'deployment-progress.json').exists() else None
    return {'runtime':'native','sourceCommit':git('rev-parse','HEAD').decode().strip(),
            'lastSuccess':last.get('commit'),'configuration':{'status':'clean' if baseline.exists() and not files else 'changed','files':files},
            'releases':history,'images':{},'progress':progress_data}


def main():
    os.umask(0o077)
    body=json.load(sys.stdin)
    if body.get('action')=='overview': return overview()
    if body.get('action')=='deploy': return deploy(body['commit'],os.environ.get('CLOUDWIKI_GITHUB_TOKEN'))
    if body.get('action')=='manage':
        with lock(): php_manage(body.get('command'))
        return {'message':'Management command completed'}
    raise ValueError('Unknown native operation')


if __name__=='__main__':
    try: print(json.dumps({'ok':True,'result':main()}))
    except BlockingIOError:
        print(json.dumps({'ok':False,'message':'Another production operation holds the deployment lock'}));sys.exit(1)
    except Exception as error:
        print(json.dumps({'ok':False,'message':str(error)}));sys.exit(1)
