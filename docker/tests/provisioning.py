#!/usr/bin/env python3
"""Compare full two-pass provisioning against an isolated one-pass prototype."""
import json,os,subprocess,time,uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
project=os.getenv('PROVISION_TEST_PROJECT') or 'cloudwiki-provision-test-'+uuid.uuid4().hex[:8]
assert project.startswith('cloudwiki-provision-test-'), 'Disposable test project required'
prefix='9provision'+uuid.uuid4().hex[:6]
base=['docker','compose','-p',project,'-f','compose.yaml','-f','docker/tests/provisioning.compose.yaml']
env=dict(os.environ,MW_ENV='development',MW_PROVISION_SINGLE_PASS='0')
report={'project':project,'status':'running','cases':[]}
reference_file=os.getenv('PROVISION_REFERENCE_REPORT')
def compose(*args,check=True):
 r=subprocess.run(base+list(args),cwd=ROOT,env=env,text=True,capture_output=True,errors='replace')
 if check and r.returncode:raise RuntimeError(r.stdout+r.stderr)
 return r
try:
 print('Initializing '+project,flush=True)
 if os.getenv('PROVISION_TEST_REUSE')!='1':
  compose('up','-d','--wait','database','redis','memcached')
  compose('run','--rm','-T','--no-deps','init','php','/opt/farm/manage.php','fresh')
  print(compose('run','--rm','-T','--no-deps','init','php','/opt/consolidate-test.php').stdout,flush=True)
 missing=compose('run','--rm','-T','--no-deps','init','php','maintenance/run.php','/opt/provisioning-test.php','--wiki=9provisionmissingwiki','--action=inspect',check=False)
 assert missing.returncode!=0 and 'was not found' in missing.stdout,'Missing metadata falsely succeeded'
 print('PASS missing metadata exits with failure status',flush=True)
 snapshots={}
 if reference_file:
  controls=json.loads(Path(reference_file).read_text())['cases']
  snapshots={case['variant']:case['checks'] for case in controls if case['mode']=='two'}
  report['reference_cases']=controls
 for mode in (['single'] if reference_file else ['two','single']):
  env['MW_PROVISION_SINGLE_PASS']='1' if mode=='single' else '0'
  for variant in os.getenv('PROVISION_TEST_VARIANTS','public,private,extra,schema').split(','):
   compose('stop','provisioner')
   wiki=prefix+mode+variant+'wiki'
   compose('run','--rm','-T','--no-deps','init','php','maintenance/run.php','/opt/provisioning-test.php','--wiki=metawiki','--action=create','--target='+wiki,'--variant='+variant)
   # Custom defaults are seeded directly in SQL for this fixture. Rebuild in a
   # fresh process so creation's cached module objects cannot restore old defaults.
   compose('run','--rm','-T','--no-deps','--user','www-data','init','php','maintenance/run.php','/opt/farm/prepare-wiki.php','--wiki=metawiki','--target='+wiki)
   compose('run','--rm','-T','--no-deps','init','php','-r','@unlink("/var/www/mediawiki/cw_cache/config-".$argv[1].".php");',wiki)
   start=time.monotonic()
   compose('up','-d','--no-deps','--force-recreate','provisioner')
   until=time.monotonic()+90
   while time.monotonic()<until:
    state=compose('exec','-T','redis','redis-cli','--json','HGETALL','global:farm:provision-status:'+wiki).stdout
    state=json.loads(state)
    if state.get('state')=='complete':break
    time.sleep(.3)
   else:raise RuntimeError('Provisioning stalled: '+wiki)
   result=compose('run','--rm','-T','--no-deps','init','php','maintenance/run.php','/opt/provisioning-test.php','--wiki='+wiki,'--action=inspect','--checkuser')
   snapshot=json.loads(result.stdout.splitlines()[-1])
   report['latest_snapshot']=snapshot
   assert snapshot['language']==('de' if variant=='private' else 'en'),snapshot
   assert snapshot['anonymous_read']==(variant!='private'),snapshot
   if variant in ['extra','schema']:assert snapshot['uploads'] is False
   if variant=='extra':assert 'Comments' in snapshot['extensions']
   if variant=='schema':assert 'Cargo' in snapshot['extensions'] and 'Linter' in snapshot['extensions'] and 'cargo_tables' in snapshot['tables']
   if mode=='two':snapshots[variant]=snapshot
   else:
    reference=snapshots[variant]
    assert snapshot['tables']==reference['tables'],{'variant':variant,'schema_differences':[t for t in set(snapshot['tables'])|set(reference['tables']) if snapshot['tables'].get(t)!=reference['tables'].get(t)]}
    update_difference={'only_two_pass':sorted(set(reference['updatelog_keys'])-set(snapshot['updatelog_keys'])),
                       'only_single_pass':sorted(set(snapshot['updatelog_keys'])-set(reference['updatelog_keys']))}
    assert snapshot['extensions']==reference['extensions'],{'variant':variant,'extension_set_differs':True}
   row={'mode':mode,'variant':variant,'wiki':wiki,'table_count':len(snapshot['tables']),'duration_ms':int(state['finished_ms'])-int(state['started_ms']),'checks':snapshot}
   if mode=='single':row['update_log_difference']=update_difference
   report['cases'].append(row)
   print('PASS '+mode+'/'+variant+': '+str(row['table_count'])+' schemas, settings, real CheckUser index job and lookup; '+str(row['duration_ms'])+' ms',flush=True)
 report['status']='passed'
 report['prototype_update_log_equivalent']=all(not any(case.get('update_log_difference',{}).values()) for case in report['cases'] if case['mode']=='single')
 report['prototype_deployed']=False
 if not report['prototype_update_log_equivalent']:
  print('Prototype schemas/settings match, but update logs differ; prototype remains undeployed.',flush=True)
except BaseException:
 report['status']='failed'
 print(compose('logs','--no-color','--tail=25','provisioner',check=False).stdout,flush=True)
 raise
finally:
 Path('/tmp/provisioning-equivalence.json').write_text(json.dumps(report,indent=2)+'\n')
 if os.getenv('KEEP_PROVISION_TEST')!='1':compose('down','-v','--remove-orphans',check=False)
