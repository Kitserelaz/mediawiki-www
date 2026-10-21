#!/usr/bin/env python3
"""Refresh the review matrix without treating old-image evidence as final acceptance."""
import hashlib
import importlib.util
import json
from pathlib import Path
ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('catalogue', ROOT/'scripts/check-catalogue.py')
catalogue = importlib.util.module_from_spec(spec)
spec.loader.exec_module(catalogue)
inputs = [
    ('docs/upgrade-evidence/catalogue-immutable-isolated.json', 'prior-immutable-image'),
    ('docs/upgrade-evidence/captcha-immutable-retries.json', 'prior-immutable-image'),
    ('docs/upgrade-evidence/socialprofile-immutable-retries.json', 'prior-immutable-image'),
    ('docs/upgrade-evidence/skin-render-assets-source-pass.json', 'prior-source-overlay'),
    ('docs/upgrade-evidence/snapwiki-assets-source-retry.json', 'prior-source-overlay'),
    ('docs/upgrade-evidence/schema-ports-registration.json', 'source-overlay-after-schema-ports'),
]
reports=[]
for name, scope in inputs:
    file=ROOT/name
    if file.exists(): reports.append((name, scope, json.loads(file.read_text())))
paths=sorted(set((ROOT/'mediawiki/extensions').glob('*/extension*.json')) |
             set((ROOT/'mediawiki/extensions').glob('*/*/extension*.json')) |
             set((ROOT/'mediawiki/skins').glob('*/skin.json')) |
             {ROOT/'mediawiki/extensions/SocialProfile/SocialProfile.php',ROOT/'mediawiki/extensions/Validator/Validator.php'})
targets=json.loads((ROOT/'docs/upgrade-targets.json').read_text())
entries=[]
for path in paths:
    relative=path.relative_to(ROOT/'mediawiki').as_posix()
    digest=hashlib.sha256(path.read_bytes()).hexdigest()
    source=catalogue.source_fingerprint(path.parent)
    evidence=[]
    for name, scope, report in reports:
        for old in report['components']:
            if old['manifest'] != relative: continue
            same=old.get('source_sha256')==source and old.get('manifest_sha256')==digest
            evidence.append({'report':name,'scope':scope,'gate':report.get('gate','registration'),
                             'observed_status':old['status'],'same_component_source':same,
                             'final_acceptance':False})
    entries.append({'manifest':relative,'source_sha256':source,'manifest_sha256':digest,
                    'source_selection':targets['extensions'].get(Path(relative).parts[1],{'selection':'retained'}),
                    'evidence':evidence,'final_registration':'pending-final-image',
                    'final_skin_assets':'pending-final-image' if path.name=='skin.json' else 'not-applicable',
                    'feature_coverage':'See regression evidence and schema-query audit; registration alone does not prove feature behavior.'})
result={'schema_version':2,'platform':{'core':targets['core']['version'],'php':targets['php']['version']},
        'overall_status':'in-progress','expected_targets':len(entries),
        'acceptance_policy':'Final source/image, complete gates and remaining schema review must be verified before changing overall status.',
        'schema_query_review':'docs/upgrade-schema-query-audit.json','components':entries}
(ROOT/'docs/upgrade-catalogue-validation.json').write_text(json.dumps(result,indent=2)+'\n')
print('Recorded',len(entries),'targets; final acceptance remains pending.')
