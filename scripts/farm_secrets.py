"""Generate runtime credentials once, shared by explicit environment setup commands."""
import json, os, secrets, subprocess
from pathlib import Path

def create_secrets(p: Path):
    p.mkdir(mode=0o700, parents=True, exist_ok=True)
    def write(name, value):
        file = p / name
        if not file.exists():
            fd = os.open(file, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, 'w') as out: out.write(value + '\n')
    write('farm.json', json.dumps({'db_password': secrets.token_hex(24), 'secret_key': secrets.token_hex(32), 'upgrade_key': secrets.token_hex(16)}, indent=2))
    write('admin-password', secrets.token_urlsafe(24))
    write('db-root-password', secrets.token_hex(32))
    if not (p / 'oauth-private.key').exists():
        subprocess.run(['openssl', 'genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:2048', '-out', str(p / 'oauth-private.key')], check=True, stderr=subprocess.DEVNULL)
    if not (p / 'oauth-public.key').exists():
        subprocess.run(['openssl', 'pkey', '-in', str(p / 'oauth-private.key'), '-pubout', '-out', str(p / 'oauth-public.key')], check=True)
    # Docker Desktop bind-mounted secrets must be readable by the www-data/node users.
    # The containing directory stays private on the host.
    p.chmod(0o700)
    for file in p.iterdir():
        if file.name != '.gitkeep': file.chmod(0o644)
