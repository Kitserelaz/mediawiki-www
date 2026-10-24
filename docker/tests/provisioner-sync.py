#!/usr/bin/env python3
"""Live watch and dispatch-lock regression check; requires scripts/farm watch-code."""
import json,subprocess,time,uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
name='source-sync-proof-'+uuid.uuid4().hex+'.txt'
proof=ROOT/'docker/mediawiki'/name
report={}
configuration=json.loads(subprocess.check_output(['scripts/farm','compose','config','--format','json'],cwd=ROOT,text=True))
service=configuration['services']['provisioner']
assert service['environment'].get('MW_ENV')=='development' and service['environment'].get('MW_CODE_SYNC')=='1', 'Requires development sync mode and a running scripts/farm watch-code'
def docker(*args):
 return subprocess.check_output(['docker',*args],cwd=ROOT,text=True).strip()
container=subprocess.check_output(['scripts/farm','compose','ps','-q','provisioner'],cwd=ROOT,text=True).strip()
def read(path):
 return docker('exec',container,'php','-r','echo is_file($argv[1])?file_get_contents($argv[1]):"MISSING";',path)
def wait(path,value):
 start=time.monotonic()
 while time.monotonic()-start<90:
  if read(path)==value:return round((time.monotonic()-start)*1000)
  time.sleep(.2)
 raise RuntimeError('Timed out waiting for '+path)
live='/opt/farm/'+name
staged='/tmp/farm-source/farm/'+name
holder=None
try:
 proof.write_text('first')
 report['create_sync_ms']=wait(live,'first')
 holder=subprocess.Popen(['docker','exec','-i',container,'php','-r',
  '$f=fopen("/tmp/farm-source.lock","r");flock($f,LOCK_SH);echo "locked\\n";fflush(STDOUT);fgets(STDIN);'],
  stdin=subprocess.PIPE,stdout=subprocess.PIPE,text=True)
 assert holder.stdout.readline().strip()=='locked'
 proof.write_text('second')
 wait(staged,'second')
 time.sleep(1)
 assert read(live)=='first','Source changed while a dispatch lock was held'
 report['active_job_source_unchanged']=True
 holder.stdin.write('\n');holder.stdin.flush();holder.wait(timeout=10);holder=None
 report['publish_after_job_ms']=wait(live,'second')
 proof.unlink()
 report['delete_sync_ms']=wait(live,'MISSING')
 report['status']='passed'
finally:
 proof.unlink(missing_ok=True)
 if holder:
  holder.stdin.write('\n');holder.stdin.flush();holder.wait(timeout=10)
print(json.dumps(report,indent=2))
Path('/tmp/provisioner-source-sync-check.json').write_text(json.dumps(report,indent=2)+'\n')
