<?php
try {
    require __DIR__ . '/private.php';
    $db = new PDO('mysql:host=' . (getenv('MW_DB_HOST') ?: 'database') . ';dbname=wikidb', getenv('MW_DB_USER') ?: 'mediawiki', $wgDBpassword, [PDO::ATTR_TIMEOUT => 2]);
    $db->query('SELECT 1 FROM cw_wikis LIMIT 1');
    $socket = fsockopen('127.0.0.1', 9000, $errno, $error, 2);
    if (!$socket) { exit(1); }
    fclose($socket);
} catch (Throwable $e) { exit(1); }
