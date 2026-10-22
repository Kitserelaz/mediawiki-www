#!/usr/bin/env python3
"""Check fail-closed HTTP behavior and metadata repair on a fresh private fixture."""
import argparse,json,re,subprocess,time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('wiki', help='Fresh private fixture from creation.py')
parser.add_argument('--report',type=Path,default=Path('/tmp/one-pass-metadata-guard.json'))
args=parser.parse_args()
wiki=args.wiki
if not re.fullmatch(r'9creation[a-z0-9]+privatewiki',wiki):parser.error('Fresh private creation fixture required')
configuration=json.loads(subprocess.check_output(['docker','compose','--profile','tools','config','--format','json'],cwd=ROOT,text=True))
environment=configuration['services']['init']['environment']
if environment.get('MW_ENV')!='development' or environment.get('MW_DOMAIN')!='localhost':parser.error('Localhost development stack required')
host=wiki[:-4]+'.localhost'
report={'wiki':wiki,'status':'running'}
def compose(*args,check=True):
 r=subprocess.run(['docker','compose',*args],cwd=ROOT,text=True,capture_output=True)
 if check and r.returncode:raise RuntimeError(r.stdout+r.stderr)
 return r.stdout
def http(path='/api.php?action=query&meta=siteinfo&format=json'):
 r=subprocess.run(['curl','--silent','--show-error','--max-time','10','--cacert',str(ROOT/'docker/secrets/local-ca.crt'),'--resolve',host+':443:127.0.0.1','--write-out','\n%{http_code}','https://'+host+path],check=True,capture_output=True,text=True)
 body,status=r.stdout.rsplit('\n',1);return body,int(status)
def launches():
 return int(compose('exec','-T','php','php','-r','echo preg_match_all("/^MediaWiki [^\\n]+ Updater$/m",file_get_contents("/var/www/mediawiki/cw_cache/.provision-".$argv[1].".log"));',wiki))
try:
 assert json.loads(http()[0])['error']['code']=='readapidenied'
 compose('stop','provisioner')
 before=launches()
 compose('exec','-T','php','php','-r','file_put_contents("/var/www/mediawiki/cw_cache/".$argv[1].".php","<?php return ".var_export(["mtime"=>time(),"database"=>$argv[1]],true).";"); require "/opt/farm/dispatch.php"; FarmDispatch::provision(FarmDispatch::connect(),$argv[1]);',wiki)
 until=time.monotonic()+15
 while time.monotonic()<until:
  body,status=http()
  if status==503 and 'being prepared' in body:break
  time.sleep(.3)
 else:raise AssertionError('Incomplete metadata did not fail closed')
 report['incomplete_metadata_http_status']=status
 print('PASS incomplete private metadata returns HTTP 503',flush=True)
 compose('up','-d','--no-deps','--wait','provisioner')
 until=time.monotonic()+60
 while time.monotonic()<until:
  state=compose('exec','-T','redis','redis-cli','HGET','global:farm:provision-status:'+wiki,'state').strip()
  if state=='complete':break
  time.sleep(.3)
 else:raise AssertionError('Repair did not complete')
 until=time.monotonic()+15
 while time.monotonic()<until:
  body,status=http()
  if status!=503 and json.loads(body).get('error',{}).get('code')=='readapidenied':break
  time.sleep(.3)
 else:raise AssertionError('Private access not restored')
 report['repaired_private_api']='readapidenied'
 body,status=http('/img_auth.php/docker-access-test.txt')
 assert status>=400 and 'Private development fixture' not in body
 report['private_file_protected']=True
 report['repair_updater_invocations']=launches()-before
 assert report['repair_updater_invocations']==1
 report['status']='passed'
 print('PASS worker repaired metadata, ran one updater, and retained private API/file denial',flush=True)
finally:
 if report['status']!='passed':
  compose('run','--rm','-T','--no-deps','--user','www-data','-e','MW_BOOTSTRAP_DB=','init','php','maintenance/run.php','/opt/farm/prepare-wiki.php','--wiki=metawiki','--target='+wiki,check=False)
 compose('up','-d','--no-deps','provisioner',check=False)
 args.report.write_text(json.dumps(report,indent=2)+'\n')
