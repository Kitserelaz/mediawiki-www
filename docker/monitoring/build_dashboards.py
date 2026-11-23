#!/usr/bin/env python3
"""Generate provisioned Grafana dashboards; no external packages required."""
import json
from pathlib import Path
ROOT = Path(__file__).parent
DS = {'type': 'prometheus', 'uid': 'prometheus'}
FRESH = ' and on() ((time() - cloudwiki_snapshot_timestamp_seconds) < 60)'
LINKS = [{'title': title, 'url': '/d/' + uid, 'type': 'link', 'keepTime': True} for title,uid in [('Overview','cloudwiki-overview'),('Host','cloudwiki-host'),('Containers','cloudwiki-containers'),('Availability','cloudwiki-availability')]]

def dashboard(uid,title):
    return {'uid':uid,'title':title,'schemaVersion':39,'version':2,'editable':False,'refresh':'30s','time':{'from':'now-6h','to':'now'},'timezone':'browser','tags':['cloudwiki','operations'],'links':LINKS,'panels':[]}

def panel(d,title,expr,unit='short',kind='timeseries',legend='',thresholds=None,w=12,h=7,description=''):
    n=len(d['panels']); x=sum(p['gridPos']['w'] for p in d['panels'] if p['gridPos']['y']==(d['panels'][-1]['gridPos']['y'] if n else 0)) if n else 0
    y=d['panels'][-1]['gridPos']['y'] if n else 0
    if x+w>24: x=0;y=max(p['gridPos']['y']+p['gridPos']['h'] for p in d['panels'])
    defaults={'unit':unit,'noValue':'Unknown','color':{'mode':'thresholds' if kind=='stat' else 'palette-classic'},'thresholds':{'mode':'absolute','steps':[{'color':'green','value':None}]+[{'color':color,'value':value} for value,color in (thresholds or [])]}}
    p={'id':n+1,'title':title,'type':kind,'description':description,'gridPos':{'x':x,'y':y,'w':w,'h':h},'datasource':DS,'fieldConfig':{'defaults':defaults,'overrides':[]},'targets':[{'refId':'A','expr':expr,'legendFormat':legend,'instant':kind in ('stat','table'),'range':kind=='timeseries','format':'table' if kind=='table' else 'time_series'}],'options':{'legend':{'displayMode':'list','placement':'bottom'},'tooltip':{'mode':'multi'},'reduceOptions':{'calcs':['lastNotNull'],'fields':'','values':False},'colorMode':'value','graphMode':'none'}}
    d['panels'].append(p);return p

def statusmap(p, mapping):
    p['fieldConfig']['defaults']['mappings']=[{'type':'value','options':{str(k):{'text':text,'color':color} for k,text,color in mapping}}]

CPU='100 * (1 - avg(rate(node_cpu_seconds_total{mode="idle"}[$__rate_interval])))'
MEM='100 * (1 - node_memory_MemAvailable_bytes / node_memory_MemTotal_bytes)'
DISK='100 * (1 - node_filesystem_avail_bytes{mountpoint="/",fstype!="rootfs"} / node_filesystem_size_bytes{mountpoint="/",fstype!="rootfs"})'
WARN=[(85,'orange'),(95,'red')]
d=dashboard('cloudwiki-overview','CloudWiki Overview')
for title,expr,th in [('Host CPU',CPU,WARN),('Memory used',MEM,WARN),('Root disk used',DISK,[(80,'orange'),(90,'red')])]:panel(d,title,expr,'percent','stat',thresholds=th,w=8,h=5)
for title,expr in [('Services not running','(count(cloudwiki_service_state != 1) or vector(0))'+FRESH),('Unhealthy services','(count(cloudwiki_service_health == 0) or vector(0))'+FRESH),('Failed public sites','sum(1 - probe_success{job="blackbox-public"})')]:panel(d,title,expr,kind='stat',thresholds=[(1,'red')],w=8,h=5)
p=panel(d,'Service collection','cloudwiki_collector_success'+FRESH,kind='stat',w=8,h=5);statusmap(p,[(0,'Collection error','orange'),(1,'Fresh','green')])
panel(d,'Snapshot age','time() - cloudwiki_snapshot_timestamp_seconds','s','stat',thresholds=[(60,'orange'),(120,'red')],w=8,h=5)
p=panel(d,'Scrape targets','min(up)',kind='stat',w=8,h=5);statusmap(p,[(0,'Target down','red'),(1,'All up','green')])
panel(d,'Host CPU and memory',CPU,'percent')
d['panels'][-1]['targets'].append({'refId':'B','expr':MEM,'legendFormat':'Memory','range':True})
d['panels'][-1]['targets'][0]['legendFormat']='CPU'
panel(d,'Public site response time','probe_duration_seconds{job="blackbox-public"}','s',legend='{{site}}')
(ROOT/'dashboards/overview.json').write_text(json.dumps(d,indent=2)+'\n')

d=dashboard('cloudwiki-host','CloudWiki Host')
for title,expr,unit,th in [('CPU usage',CPU,'percent',WARN),('Memory used',MEM,'percent',WARN),('Root disk used',DISK,'percent',[(80,'orange'),(90,'red')]),('Host uptime','time()-node_boot_time_seconds','s',[])]:panel(d,title,expr,unit,'stat',thresholds=th,w=6,h=5)
for title,expr,unit,legend in [
 ('CPU usage by mode','100 * avg by (mode)(rate(node_cpu_seconds_total[$__rate_interval]))','percent','{{mode}}'),
 ('Load per CPU','node_load1 / scalar(count(node_cpu_seconds_total{mode="idle"}))','short','1-minute load / CPU count'),
 ('Available memory','node_memory_MemAvailable_bytes','bytes','Available'),
 ('Root disk available','node_filesystem_avail_bytes{mountpoint="/",fstype!="rootfs"}','bytes','Available'),
 ('Disk read throughput','rate(node_disk_read_bytes_total{device=~"sd[a-z]+|vd[a-z]+|nvme[0-9]+n[0-9]+"}[$__rate_interval])','Bps','{{device}}'),
 ('Disk write throughput','rate(node_disk_written_bytes_total{device=~"sd[a-z]+|vd[a-z]+|nvme[0-9]+n[0-9]+"}[$__rate_interval])','Bps','{{device}}'),
 ('Network receive','rate(node_network_receive_bytes_total{device=~"eth.*|ens.*|enp.*|eno.*"}[$__rate_interval])','Bps','{{device}}'),
 ('Network transmit','rate(node_network_transmit_bytes_total{device=~"eth.*|ens.*|enp.*|eno.*"}[$__rate_interval])','Bps','{{device}}')]:panel(d,title,expr,unit,legend=legend)
(ROOT/'dashboards/host.json').write_text(json.dumps(d,indent=2)+'\n')

def table(d,title,kind,filtered=False):
    selector='{kind="'+kind+'"'+(',service=~"$service"' if filtered else '')+'}'
    fields=[('state','State','short'),('health','Health','short'),('cpu_percent','CPU %','percent'),('memory_bytes','Memory','bytes'),('uptime_seconds','Uptime','s'),('restarts','Restarts since creation','short')]
    p=panel(d,title,'cloudwiki_service_state'+selector+FRESH,kind='table',w=24,h=11)
    p['options']={'showHeader':True,'cellHeight':'sm','footer':{'show':False},'sortBy':[{'displayName':'CPU %','desc':True}]}
    p['targets']=[{'refId':chr(65+i),'expr':'cloudwiki_service_'+metric+selector+FRESH,'instant':True,'range':False,'format':'table'} for i,(metric,_,_) in enumerate(fields)]
    p['transformations']=[{'id':'joinByField','options':{'byField':'service','mode':'outerTabular'}},{'id':'organize','options':{'excludeByName':{'Time':True,'kind':True,'job':True,'instance':True,'__name__':True},'renameByName':{'service':'Service',**{'Value #'+chr(65+i):name for i,(_,name,_) in enumerate(fields)}}}}]
    for _,name,unit in fields:p['fieldConfig']['overrides'].append({'matcher':{'id':'byName','options':name},'properties':[{'id':'unit','value':unit},{'id':'noValue','value':'Unknown'}]})
    state=[(1,'Running','green'),(0,'Stopped','red'),(-1,'Missing','red'),(-2,'Unknown','orange'),(-3,'Ambiguous','orange')]
    health=[(1,'Healthy','green'),(0,'Unhealthy','red'),(2,'Starting','orange'),(-1,'Not configured','text'),(-2,'Unknown','orange')]
    for name,mapping in [('State',state),('Health',health)]:p['fieldConfig']['overrides'].append({'matcher':{'id':'byName','options':name},'properties':[{'id':'mappings','value':[{'type':'value','options':{str(k):{'text':text,'color':color} for k,text,color in mapping}}]}]})
    p['description']='CPU: 100% equals one core. Restarts reset on container recreation. Stale data is omitted after 60 seconds.'

d=dashboard('cloudwiki-containers','CloudWiki Containers')
d['templating']={'list':[{'name':'service','label':'Production service','type':'query','datasource':DS,'query':'label_values(cloudwiki_service_state{kind="container"}, service)','definition':'label_values(cloudwiki_service_state{kind="container"}, service)','refresh':1,'multi':True,'includeAll':True,'allValue':'.*','current':{'text':'All','value':'$__all'}}]}
p=panel(d,'Collector status','cloudwiki_collector_success'+FRESH,kind='stat',w=12,h=5);statusmap(p,[(1,'Fresh','green'),(0,'Collection error','orange')])
panel(d,'Source snapshot age','time()-cloudwiki_snapshot_timestamp_seconds','s','stat',thresholds=[(60,'orange')],w=12,h=5)
table(d,'Production containers','container',True)
panel(d,'Container CPU — 100% = one core','cloudwiki_service_cpu_percent{kind="container",service=~"$service"}'+FRESH,'percent',legend='{{service}}')
panel(d,'Container memory','cloudwiki_service_memory_bytes{kind="container",service=~"$service"}'+FRESH,'bytes',legend='{{service}}')
table(d,'Host services','unit')
(ROOT/'dashboards/containers.json').write_text(json.dumps(d,indent=2)+'\n')

d=dashboard('cloudwiki-availability','CloudWiki Availability')
p=panel(d,'Public site status','probe_success{job="blackbox-public"}',kind='stat',legend='{{site}}',w=24,h=5);statusmap(p,[(1,'Available','green'),(0,'Failed','red')]);p['options']['reduceOptions']['values']=False
for title,expr,unit,th in [
 ('Observed availability in selected period','100 * avg_over_time(probe_success{job="blackbox-public"}[$__range])','percent',[]),
 ('TLS certificate time remaining','probe_ssl_earliest_cert_expiry{job="blackbox-public"} - time()','s',[(0,'red'),(604800,'orange'),(1209600,'green')]),
 ('Public response time','probe_duration_seconds{job="blackbox-public"}','s',[]),
 ('Final HTTP status','probe_http_status_code{job="blackbox-public"}','short',[])]:panel(d,title,expr,unit,'stat' if 'availability' in title or 'remaining' in title or 'status' in title else 'timeseries',legend='{{site}}',thresholds=th)
d['panels'][2]['fieldConfig']['defaults']['thresholds']['steps'][0]['color']='red'
d['description']='Checks originate on the VPS and traverse public DNS/Cloudflare. This cannot independently detect a total monitoring-server outage. Observed availability excludes missing samples and begins at deployment; it is not an external SLA.'
(ROOT/'dashboards/availability.json').write_text(json.dumps(d,indent=2)+'\n')

modules={}
for key,title in [('meta','Meta Wiki'),('admin','Sign in · CloudWiki Admin'),('grafana','Grafana')]:
    modules['cloudwiki_'+key]={'prober':'http','timeout':'10s','http':{'method':'GET','valid_status_codes':[200],'follow_redirects':True,'preferred_ip_protocol':'ip4','headers':{'User-Agent':'Mozilla/5.0 (compatible; CloudWikiMonitoring/1.0)'},'fail_if_not_ssl':True,'fail_if_body_not_matches_regexp':['<title[^>]*>'+title+'</title>'],'fail_if_body_matches_regexp':['Wiki not found'],'tls_config':{'insecure_skip_verify':False}}}
(ROOT/'blackbox.json').write_text(json.dumps({'modules':modules},indent=2)+'\n')
