#!/usr/bin/env python3
"""Record dependency identities and hashes, without recording runtime credentials."""
import hashlib, json, re
from pathlib import Path
root = Path(__file__).resolve().parent.parent
mw = root/'mediawiki'
result = {'mediawiki': re.search(r"define\( 'MW_VERSION', '([^']+)'", (mw/'includes/Defines.php').read_text())[1], 'components': [], 'lockfiles': {}}
requirements = {}
installed = {}
def record_requirements(values, component):
    for key in values:
        if key.startswith('ext-'):
            names = requirements.setdefault(key, [])
            if component not in names: names.append(component)
record_requirements(json.loads((mw/'composer.json').read_text()).get('require', {}), 'MediaWiki')
for folder, manifest in [('extensions','extension.json'), ('skins','skin.json')]:
    for file in sorted((mw/folder).glob('*/'+manifest)):
        try: data = json.loads(file.read_text())
        except (ValueError, OSError): continue
        result['components'].append({'type':folder,'name':file.parent.name,'version':data.get('version'),'sha256':hashlib.sha256(file.read_bytes()).hexdigest(),'requires':data.get('requires',{})})
        record_requirements(data.get('requires', {}).get('platform', {}), file.parent.name)
        composer = file.parent/'composer.json'
        if composer.exists():
            record_requirements(json.loads(composer.read_text()).get('require', {}), file.parent.name)
for path in ['mediawiki/composer.lock','Moe-Counter/pnpm-lock.yaml','Moe-Counter/bun.lock']:
    result['lockfiles'][path] = hashlib.sha256((root/path).read_bytes()).hexdigest()
for file in sorted(mw.glob('**/vendor/composer/installed.json')):
    if any(part in {'extensions_old', 'extensions_staging', 'node_modules'} for part in file.parts): continue
    try:
        data = json.loads(file.read_text())
        packages = data.get('packages', []) if isinstance(data, dict) else data
        installed[str(file.relative_to(root))] = [
            {'name': package.get('name'), 'version': package.get('version'), 'reference': package.get('source', {}).get('reference') or package.get('dist', {}).get('reference')}
            for package in packages
        ]
    except (ValueError, OSError): continue
for file in sorted(mw.glob('*/**/composer.lock')):
    if any(part in {'vendor', 'node_modules', 'extensions_old', 'extensions_staging'} for part in file.parts): continue
    result['lockfiles'][str(file.relative_to(root))] = hashlib.sha256(file.read_bytes()).hexdigest()
result['local_patches'] = {
    str(file.relative_to(root)): hashlib.sha256(file.read_bytes()).hexdigest()
    for file in [mw/'extensions/DataDump/includes/Api/ApiDeleteDumps.php', mw/'extensions/DataDump/includes/Api/ApiGenerateDumps.php']
}
result['installed_dependencies'] = installed
result['php_extensions'] = requirements
(root/'docs/dependency-inventory.json').write_text(json.dumps(result,indent=2)+'\n')
print(f"Recorded {len(result['components'])} extensions/skins and dependency lockfiles")
