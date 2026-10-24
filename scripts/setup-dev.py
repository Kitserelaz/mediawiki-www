#!/usr/bin/env python3
"""Create local secrets once. Existing files are never overwritten."""
import argparse
import os
from pathlib import Path
from farm_secrets import create_secrets
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--discord-browser-fixtures', action='store_true',
                    help='Use dummy OAuth credentials in a fresh disposable CI development farm')
args = parser.parse_args()
if args.discord_browser_fixtures and os.environ.get('CI') != 'true':
    parser.error('Discord browser fixtures are only allowed in explicit CI development setup')
root = Path(__file__).resolve().parent.parent
os.chdir(root)
create_secrets(root / 'docker/secrets', discord_browser_fixtures=args.discord_browser_fixtures)
if not (root / '.env').exists(): (root / '.env').write_text((root / '.env.example').read_text())
(root / 'backups').mkdir(exist_ok=True)
print('Local configuration created. Admin password: docker/secrets/admin-password')
