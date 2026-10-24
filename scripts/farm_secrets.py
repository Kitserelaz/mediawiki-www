"""Generate runtime credentials once, shared by explicit environment setup commands."""
import json, os, secrets, subprocess
from pathlib import Path

DISCORD_BROWSER_FIXTURES = {
    'discord_client_id': '999999999999999999',
    'discord_client_secret': 'cloudwiki-browser-fixture-not-a-real-discord-secret',
}


def create_secrets(p: Path, *, discord_browser_fixtures=False):
    p.mkdir(mode=0o700, parents=True, exist_ok=True)
    def write(name, value):
        file = p / name
        if not file.exists():
            fd = os.open(file, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, 'w') as out: out.write(value + '\n')
    runtime = {'db_password': secrets.token_hex(24), 'secret_key': secrets.token_hex(32), 'upgrade_key': secrets.token_hex(16)}
    if discord_browser_fixtures:
        runtime.update(DISCORD_BROWSER_FIXTURES)
    write('farm.json', json.dumps(runtime, indent=2))
    if discord_browser_fixtures:
        configured = json.loads((p / 'farm.json').read_text())
        if any(configured.get(key) != value for key, value in DISCORD_BROWSER_FIXTURES.items()):
            raise ValueError('Discord browser fixtures require fresh development secrets; existing credentials were preserved')
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
