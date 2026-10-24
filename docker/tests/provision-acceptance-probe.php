<?php
if (getenv('PROVISION_ACCEPTANCE_ISOLATED') !== '1' || getenv('MW_ENV') !== 'production' ||
    !str_ends_with(getenv('MW_DOMAIN') ?: '', '.example.test')) {
    throw new RuntimeException('Isolated reserved-domain production rehearsal required');
}
require '/opt/farm/private.php';
require '/opt/farm/runtime.php';
require '/opt/farm/dispatch.php';
$wiki = $argv[2] ?? '';
if ($wiki !== '' && !preg_match('/^9provision[a-z0-9]+wiki$/D', $wiki)) {
    throw new RuntimeException('Invalid acceptance fixture');
}
$cache = '/var/www/mediawiki/cw_cache';
if ($argv[1] === 'state') {
    $redis = FarmDispatch::connect();
    $db = new PDO('mysql:host=database;dbname=wikidb', 'mediawiki', $wgDBpassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $query = $db->prepare('SELECT s_extensions FROM mw_settings WHERE s_dbname=?');
    $query->execute([$wiki]);
    $storedExtensions = json_decode($query->fetchColumn() ?: '[]', true);
    $data = @(static fn($path) => include $path)("$cache/$wiki.php");
    $entries = [];
    foreach (['databases', 'deleted', 'active', 'private', 'public', 'alpha-wikis', 'beta-wikis', 'stable-wikis'] as $name) {
        $list = (static fn($path) => include $path)("$cache/$name.php");
        $entries[$name] = $list['databases'][$wiki] ?? null;
    }
    $lock = fopen("$cache/.provision-$wiki.metadata.lock", 'ce');
    $lockHeld = !flock($lock, LOCK_EX | LOCK_NB);
    fclose($lock);
    $log = @file_get_contents("$cache/.provision-$wiki.log") ?: '';
    preg_match_all('/^Provisioning metadata: (creator|repair)$/m', $log, $matches);
    echo json_encode([
        'status' => $redis->hGetAll(FarmDispatch::statusKey($wiki)),
        'pending' => $redis->hExists(FarmDispatch::PROVISION_PENDING, $wiki),
        'marker' => is_file("$cache/.provision-$wiki"),
        'receipt' => is_file("$cache/.provision-$wiki.metadata.json"),
        'paused' => is_file("$cache/.accept-$wiki"), 'lock_held' => $lockHeld,
        'metadata_complete' => farmWikiMetadataIsComplete($wiki),
        'private' => $data['states']['private'] ?? null, 'lists' => $entries,
        'updater_invocations' => preg_match_all('/^MediaWiki [^\n]+ Updater$/m', $log),
        'metadata_sources' => $matches[1],
        'stored_extensions' => $storedExtensions,
    ]), "\n";
} elseif ($argv[1] === 'continue') {
    $pid = (int)file_get_contents("$cache/.accept-$wiki");
    if (!posix_kill($pid, SIGCONT)) {
        throw new RuntimeException('Could not resume creator');
    }
} elseif ($argv[1] === 'http') {
    // Talk to the image's actual PHP-FPM over FastCGI, without replacing its runtime.
    $script = $argv[3] ?? 'api.php';
    if (!in_array($script, ['api.php', 'img_auth.php'], true)) {
        throw new RuntimeException('Invalid script');
    }
    $host = preg_replace('/wiki$/', '', $wiki) . '.' . getenv('MW_DOMAIN');
    $query = $script === 'api.php' ? 'action=query&meta=siteinfo&format=json' : '';
    $params = [
        'GATEWAY_INTERFACE'=>'CGI/1.1', 'REQUEST_METHOD'=>'GET',
        'SCRIPT_FILENAME'=>"/var/www/mediawiki/$script", 'SCRIPT_NAME'=>"/$script",
        'QUERY_STRING'=>$query, 'REQUEST_URI'=>"/$script?" . $query,
        'SERVER_PROTOCOL'=>'HTTP/1.1', 'SERVER_NAME'=>$host, 'HTTP_HOST'=>$host,
        'SERVER_PORT'=>'443', 'HTTPS'=>'on', 'REMOTE_ADDR'=>'127.0.0.1',
        'DOCUMENT_ROOT'=>'/var/www/mediawiki',
    ];
    if ($script === 'img_auth.php') {
        $params['PATH_INFO'] = '/acceptance-private.txt';
        $params['REQUEST_URI'] = '/img_auth.php/acceptance-private.txt';
        $directory = '/var/www/mediawiki/images/' . $wiki;
        if (!is_dir($directory)) { mkdir($directory, 0775, true); }
        file_put_contents($directory . '/acceptance-private.txt', 'Private acceptance content');
    }
    $record = static function (int $type, string $body): string {
        return pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0) . $body;
    };
    $length = static fn(int $n): string => $n < 128 ? chr($n) : pack('N', $n | 0x80000000);
    $encoded = '';
    foreach ($params as $name => $value) {
        $encoded .= $length(strlen($name)) . $length(strlen($value)) . $name . $value;
    }
    $socket = fsockopen('php', 9000, $errno, $error, 10);
    if (!$socket) { throw new RuntimeException($error); }
    stream_set_timeout($socket, 30);
    $request = $record(1, pack('nCxxxxx', 1, 0)) . $record(4, $encoded) . $record(4, '') . $record(5, '');
    while ($request !== '') {
        $written = fwrite($socket, $request);
        if (!$written) { throw new RuntimeException('FastCGI write failed'); }
        $request = substr($request, $written);
    }
    $read = static function (int $bytes) use ($socket): string {
        $buffer = '';
        while (strlen($buffer) < $bytes) {
            $chunk = fread($socket, $bytes - strlen($buffer));
            if ($chunk === '' || $chunk === false) { throw new RuntimeException('FastCGI response ended early'); }
            $buffer .= $chunk;
        }
        return $buffer;
    };
    $output = '';
    do {
        $header = unpack('Cversion/Ctype/nrequest/nlength/Cpadding/Creserved', $read(8));
        $body = $header['length'] ? $read($header['length']) : '';
        if ($header['padding']) { $read($header['padding']); }
        if ($header['type'] === 6) { $output .= $body; }
    } while ($header['type'] !== 3);
    fclose($socket);
    [$headers, $body] = explode("\r\n\r\n", $output, 2);
    preg_match('/^Status: (\d+)/mi', $headers, $status);
    echo json_encode(['status'=>(int)($status[1] ?? 200), 'body'=>$body]), "\n";
} elseif ($argv[1] === 'queue') {
    $redis = FarmDispatch::connect();
    echo json_encode(['pending_wikis'=>$redis->hLen(FarmDispatch::PROVISION_PENDING),
        'pending_deliveries'=>$redis->rawCommand('XPENDING', FarmDispatch::PROVISION, FarmDispatch::GROUP)[0]]), "\n";
} else {
    throw new RuntimeException('Unknown probe action');
}
