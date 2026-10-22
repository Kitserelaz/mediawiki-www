#!/usr/bin/env python3
"""Verify MediaWiki's actual FPM cache across requests.

Run: docker compose exec -T php python3 - < docker/tests/local-cache.py
The probe is temporary, outside the document root, and uses no PHP overrides.
"""
import json
import os
from pathlib import Path
import socket
import struct
import tempfile
import uuid


def record(kind, data=b''):
    padding = -len(data) % 8
    return struct.pack('!BBHHBB', 1, kind, 1, len(data), padding, 0) + data + bytes(padding)


def field_length(value):
    return bytes([len(value)]) if len(value) < 128 else struct.pack('!I', len(value) | 0x80000000)


def request(script, phase):
    host = 'meta.' + os.environ.get('MW_DOMAIN', 'localhost')
    params = {
        'REQUEST_METHOD': 'GET', 'SCRIPT_FILENAME': str(script),
        'SCRIPT_NAME': '/index.php', 'REQUEST_URI': '/index.php?phase=' + phase,
        'QUERY_STRING': 'phase=' + phase, 'SERVER_NAME': host, 'HTTP_HOST': host,
        'SERVER_PORT': '443', 'HTTPS': 'on', 'SERVER_PROTOCOL': 'HTTP/1.1',
        'GATEWAY_INTERFACE': 'CGI/1.1', 'REMOTE_ADDR': '127.0.0.1',
    }
    fields = []
    for key, value in params.items():
        key, value = key.encode(), value.encode()
        fields.append(field_length(key) + field_length(value) + key + value)
    with socket.create_connection(('127.0.0.1', 9000), timeout=30) as connection:
        connection.sendall(record(1, struct.pack('!HB5x', 1, 0)) +
                           record(4, b''.join(fields)) + record(4) + record(5))

        def read(size):
            result = b''
            while len(result) < size:
                part = connection.recv(size - len(result))
                if not part:
                    raise RuntimeError('FPM closed the probe connection early')
                result += part
            return result

        output, errors = b'', b''
        while True:
            _, kind, _, size, padding, _ = struct.unpack('!BBHHBB', read(8))
            data = read(size)
            read(padding)
            if kind == 6:
                output += data
            elif kind == 7:
                errors += data
            elif kind == 3:
                break
    if errors:
        raise RuntimeError(errors.decode())
    body = output.split(b'\r\n\r\n', 1)[1]
    if not body.lstrip().startswith(b'{'):
        raise RuntimeError('FPM local-cache probe failed; check PHP logs for a missing or unusable APCu cache')
    return json.loads(body)


key = 'farm-fpm-cache-check:' + uuid.uuid4().hex
source = r'''<?php
// Bootstrap the normal web configuration without serving a page or session.
define('MW_ENTRY_POINT', 'load');
define('MW_NO_SESSION', 1);
require '/var/www/mediawiki/includes/WebStart.php';
$cache = MediaWiki\MediaWikiServices::getInstance()->getLocalServerObjectCache();
if (!extension_loaded('apcu') || !apcu_enabled() ||
    !($cache instanceof Wikimedia\ObjectCache\APCUBagOStuff)) {
    throw new RuntimeException('FPM requires an enabled APCu local cache');
}
$key = '__KEY__';
if (($_GET['phase'] ?? '') === 'write') {
    if (!$cache->set($key, 'shared-value', 30)) {
        throw new RuntimeException('Local cache write failed');
    }
} else {
    if ($cache->get($key) !== 'shared-value') {
        throw new RuntimeException('Local cache did not retain data across FPM requests');
    }
    $cache->delete($key);
}
echo json_encode(['cache_class' => get_class($cache), 'apcu_version' => phpversion('apcu'),
    'shm_size' => ini_get('apc.shm_size'), 'php' => PHP_VERSION, 'mediawiki' => MW_VERSION]);
'''.replace('__KEY__', key)
with tempfile.NamedTemporaryFile(suffix='.php', mode='w', delete=False) as probe:
    probe.write(source)
path = Path(probe.name)
try:
    request(path, 'write')
    result = request(path, 'read')
    print(json.dumps({'cross_request_cache': 'passed', **result}))
finally:
    path.unlink(missing_ok=True)
