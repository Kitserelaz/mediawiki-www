<?php
require_once __DIR__ . '/dispatch.php';
$kind = $argv[1] ?? 'jobs';
if (!in_array($kind, ['jobs', 'provision'], true)) { exit(1); }
$file = '/tmp/farm-dispatch-' . $kind . '-heartbeat';
try {
    if (!is_file($file) || time() - (int)file_get_contents($file) > 30) { exit(1); }
    FarmDispatch::connect()->ping();
} catch (Throwable $e) { exit(1); }
