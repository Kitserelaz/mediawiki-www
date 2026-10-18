#!/usr/bin/env python3
"""Create local secrets once. Existing files are never overwritten."""
import os
from pathlib import Path
from farm_secrets import create_secrets
root = Path(__file__).resolve().parent.parent
os.chdir(root)
create_secrets(root / 'docker/secrets')
if not (root / '.env').exists(): (root / '.env').write_text((root / '.env.example').read_text())
(root / 'backups').mkdir(exist_ok=True)
print('Local configuration created. Admin password: docker/secrets/admin-password')
