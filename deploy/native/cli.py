#!/usr/bin/env python3
"""Native equivalent of the farm operator CLI. Development still uses Compose."""
import importlib.util,json,os,sys,time
from pathlib import Path
s=importlib.util.spec_from_file_location('native','/usr/local/libexec/cloudwiki-native.py')
c=importlib.util.module_from_spec(s);s.loader.exec_module(c)
def main():
    args=sys.argv[1:]
    if os.geteuid()!=0:raise SystemExit('Run native maintenance as root')
    if not args:raise SystemExit('Usage: scripts/farm preflight|update|refresh|list|snapshot [--keep-stopped] NAME|logs [SERVICE]|stop|up')
    action=args.pop(0)
    with c.lock():
        if action in ('preflight','update','refresh','list'):c.php_manage(action)
        elif action=='snapshot':
            keep='--keep-stopped' in args;args=[a for a in args if a!='--keep-stopped']
            import re
            if len(args)!=1 or not re.fullmatch('[A-Za-z0-9_-]+',args[0]):raise SystemExit('A safe snapshot name is required')
            c.maintenance(True);c.systemctl('stop',c.WRITERS);c.systemctl('stop',['redis'])
            print(c.snapshot(args[0]))
            if not keep:c.systemctl('start',['redis',*c.APP_UNITS]);c.maintenance(False)
        elif action=='stop':c.maintenance(True);c.systemctl('stop',c.WRITERS)
        elif action=='up':c.systemctl('start',['mariadb','redis','memcached',*c.APP_UNITS]);c.maintenance(False)
        elif action=='logs':
            name=args[0] if args else 'php'
            manifest=json.loads((c.CONFIG/'runtime.json').read_text())
            if 'cloudwiki-'+name not in manifest['units']:raise SystemExit('Unknown native service')
            os.execvp('journalctl',['journalctl','-fu','cloudwiki-'+name+'.service'])
        else:raise SystemExit('Unsupported native operation; source edits must be published and deployed through the panel')
if __name__=='__main__':main()
