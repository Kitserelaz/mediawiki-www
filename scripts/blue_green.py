"""Pure configuration builders for the production blue/green application slots.

The public Caddy container stays running. Slots contain matching assets and PHP;
database, Redis, workers, counter and certificate storage are not duplicated.
"""
import copy
import re
from pathlib import Path, PurePosixPath

SLOTS = ('blue', 'green')
ADMIN = 'unix//tmp/cloudwiki-admin.sock'
MOUNT = '/etc/caddy/blue-green'
LABEL = 'us.cloudwiki.release-slot'


def online_safe(paths):
    """Allow only a small, data-only public-asset surface; everything else freezes.

    In particular PHP, extension.json, SQL, Composer, Docker/base images and
    configuration are never inferred safe from the absence of a migration file.
    """
    if not paths:
        return False
    extensions = {'.js', '.css', '.less', '.svg', '.png', '.jpg', '.jpeg', '.gif',
                  '.webp', '.ico', '.woff', '.woff2', '.ttf'}
    for value in paths:
        path = PurePosixPath(value)
        if '..' in path.parts or any(part.startswith('.') for part in path.parts):
            return False
        parts = path.parts
        public = (parts[:2] == ('mediawiki', 'resources') or
                  (len(parts) >= 5 and parts[:2] in
                   (('mediawiki', 'extensions'), ('mediawiki', 'skins')) and
                   parts[3] in ('resources', 'modules', 'styles', 'images')))
        if not public or path.suffix.lower() not in extensions:
            return False
    return True


def slot_name(slot, service):
    if slot not in SLOTS or service not in ('php', 'web'):
        raise ValueError('Invalid application slot')
    return f'bg-{slot}-{service}'


def bind(source, target):
    return {'type': 'bind', 'source': str(source), 'target': target, 'read_only': True,
            'bind': {'create_host_path': False}}


def external_resources(base):
    result = {}
    for kind in ('volumes', 'networks'):
        result[kind] = {name: {'external': True, 'name': value.get('name', base['name'] + '_' + name)}
                        for name, value in base.get(kind, {}).items()}
    result['secrets'] = copy.deepcopy(base.get('secrets', {}))
    result['configs'] = copy.deepcopy(base.get('configs', {}))
    return result


def gateway_validation_config(base, directory):
    # A production edge may own a fixed Tunnel IP. A one-off copy of that
    # service cannot start while the real edge owns the address.
    web = copy.deepcopy(base['services']['web'])
    for key in ('build', 'ports', 'container_name', 'depends_on', 'profiles',
                'develop', 'network_mode', 'links', 'healthcheck', 'env_file'):
        web.pop(key, None)
    web['networks'] = {'default': {}}
    web['restart'] = 'no'
    web['entrypoint'] = ['caddy']
    web['command'] = ['validate', '--config', MOUNT + '/Caddyfile', '--adapter', 'caddyfile']
    web['volumes'] = list({v['target']: v for v in
                           [*web.get('volumes', []), bind(directory, MOUNT)]}.values())
    return {'name': base['name'], 'services': {'gateway-validation': web}, **external_resources(base)}


def slot_config(base, slot, images, directory, source=None):
    """Use the farm's existing named volumes/network, without owning their lifetime."""
    php = copy.deepcopy(base['services']['php'])
    web = copy.deepcopy(base['services']['web'])
    for service in (php, web):
        for key in ('build', 'ports', 'container_name', 'depends_on', 'profiles',
                    'develop', 'network_mode', 'links', 'env_file'):
            service.pop(key, None)
        service['networks'] = {'default': {}}
        service.setdefault('labels', {})[LABEL] = 'true'
    php['image'] = images['php']
    web['image'] = images['web']
    # Never give a slot access to the edge's certificate or admin state.
    web['volumes'] = [v for v in web.get('volumes', [])
                      if not v['target'].startswith(('/etc/caddy', '/data', '/config',
                                                     '/run/cloudwiki', '/srv/'))]
    if source:
        php['volumes'] += [bind(source / 'mediawiki', '/var/www/mediawiki'),
                           bind(source / 'docker/mediawiki', '/opt/farm'),
                           bind(source / 'docker/mediawiki', '/var/www/mediawiki/docker/mediawiki'),
                           bind(source / 'docker/mediawiki/private.php', '/var/www/mediawiki/PrivateSettings.php')]
        web['volumes'] += [bind(source / 'mediawiki' / part, '/var/www/mediawiki/' + part)
                           for part in ('resources', 'extensions', 'skins')]
    # Unique targets also make nested writable data mounts override the code root.
    php['volumes'] = list({v['target']: v for v in php.get('volumes', [])}.values())
    web['volumes'].append(bind(directory, MOUNT))
    web['volumes'] = list({v['target']: v for v in web['volumes']}.values())
    web['command'] = ['caddy', 'run', '--config', MOUNT + '/backend.caddy', '--adapter', 'caddyfile']
    web.setdefault('environment', {})['MW_NUMERIC_WIKI_IDS'] = '0'
    web['healthcheck'] = {'test': ['CMD', 'wget', '-q', '-O', '/dev/null',
                                 'http://127.0.0.1:8080/health'],
                          'interval': '5s', 'timeout': '3s', 'retries': 12}
    result = {'name': base['name'], 'services': {slot_name(slot, 'php'): php,
                                                slot_name(slot, 'web'): web}}
    result.update(external_resources(base))
    return result


def backend_files(root, slot):
    wiki = (root / 'docker/caddy/wiki.caddy').read_text().replace('php:9000', slot_name(slot, 'php') + ':9000')
    return {'wiki.caddy': wiki, 'backend.caddy': '''{
    admin off
    servers {
        trusted_proxies static private_ranges
        client_ip_headers X-CloudWiki-Client-IP
    }
}
import /etc/caddy/blue-green/wiki.caddy
http://:8080 {
    route {
        @health path /health
        respond @health 200
        vars cloudwiki_custom_wiki {http.request.header.X-CloudWiki-Route-Wiki}
        vars cloudwiki_custom_host {http.request.header.X-CloudWiki-Route-Host}
        import wiki
    }
}
'''}


def gateway_files(base, slot):
    """Preserve operator wrappers (diff/editor/wildcard) and their TLS policies."""
    mounted = {v['target']: Path(v['source']) for v in base['services']['web'].get('volumes', [])
               if v['type'] == 'bind' and v['target'].startswith('/etc/caddy/')
               and Path(v['source']).is_file()}
    if '/etc/caddy/Caddyfile' not in mounted:
        raise RuntimeError('Blue/green requires the production Caddyfile file mount')
    names = {target: MOUNT + '/' + target.removeprefix('/etc/caddy/') for target in mounted}
    files = {}
    admin_count = 0
    for target, path in mounted.items():
        content = path.read_text()
        if target.endswith('/wiki.caddy'):
            content = gateway_wiki(slot)
        elif target.endswith('/custom-domains.caddy'):
            content = content.replace('php:9001', slot_name(slot, 'php') + ':9001')
        else:
            content, count = re.subn(r'(?m)^([ \t]*)admin[ \t]+off(?=[ \t]*(?:#|$))',
                                     r'\1admin ' + ADMIN, content)
            admin_count += count
        for original, replacement in sorted(names.items(), key=lambda pair: -len(pair[0])):
            content = re.sub(r'(?m)^([ \t]*import[ \t]+)' + re.escape(original) + r'(?=[ \t]|$)',
                             lambda match: match[1] + replacement, content)
        name = target.removeprefix('/etc/caddy/')
        if '/' in name or not re.fullmatch(r'[A-Za-z0-9_.-]+', name):
            raise RuntimeError('Unsupported Caddy configuration mount')
        files[name] = content
    if admin_count != 1 or 'wiki.caddy' not in files or 'custom-domains.caddy' not in files:
        raise RuntimeError('Expected one production admin directive and wiki/custom-domain snippets')
    combined = '\n'.join(files.values())
    if any('import ' + MOUNT + '/' + name not in combined for name in ('wiki.caddy', 'custom-domains.caddy')):
        raise RuntimeError('Unsupported production imports; generated wiki and domain snippets must be used')
    return files


def gateway_wiki(slot):
    upstream = slot_name(slot, 'web')
    return '''(wiki) {
    route {
        @numericFarmHost {
            host *.{$MW_DOMAIN:localhost} *.{$MW_BETA_DOMAIN:beta.localhost}
            expression `'{$MW_NUMERIC_WIKI_IDS:0}' == '1' && {http.request.host}.matches('^[0-9]+[.]')`
        }
        forward_auth @numericFarmHost 127.0.0.1:8081 {
            uri /route-numeric
            header_up Host 127.0.0.1:8081
            header_up X-CloudWiki-Lookup-Host {http.request.host}
            header_up X-CloudWiki-Lookup-SNI {http.request.tls.server_name}
            header_up X-CloudWiki-Lookup-Scheme {scheme}
            header_up -Cookie
            header_up -Authorization
        }
        reverse_proxy UPSTREAM:8080 {
            stream_close_delay 5m
            header_up X-CloudWiki-Client-IP {client_ip}
            header_up X-CloudWiki-Route-Wiki {http.vars.cloudwiki_custom_wiki}
            header_up X-CloudWiki-Route-Host {http.vars.cloudwiki_custom_host}
        }
    }
}
http://:8083 {
    reverse_proxy UPSTREAM:8083
}
'''.replace('UPSTREAM', upstream)


def gateway_override(directory):
    return {'services': {'web': {'volumes': [bind(directory, MOUNT)],
                                'command': ['caddy', 'run', '--config', MOUNT + '/Caddyfile',
                                            '--adapter', 'caddyfile']}}}
