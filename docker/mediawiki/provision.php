<?php
require_once __DIR__ . '/process.php';
// Process only explicit pending new-wiki provisioning, never existing wiki migrations.
require __DIR__ . '/runtime.php';
$wiki = $argv[1] ?? '';
if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $wiki)) { exit(1); }
$pending = "/var/www/mediawiki/cw_cache/.provision-$wiki";
if (!is_file($pending)) { exit(0); }
if (!is_file("/var/www/mediawiki/cw_cache/$wiki.php")) { exit(1); }
$lock = fopen($pending . '.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) { exit(1); }
// The worker signals this coordinator; forward shutdown to its updater child.
$stopping = false;
$process = null;
pcntl_async_signals(true);
$stop = static function () use (&$stopping, &$process): void {
    $stopping = true;
    if (is_resource($process)) { proc_terminate($process, SIGTERM); }
};
pcntl_signal(SIGTERM, $stop);
pcntl_signal(SIGINT, $stop);
$env = getenv();
foreach ([true, false] as $bootstrap) {
    if ($stopping) { exit(1); }
    $env['MW_BOOTSTRAP_DB'] = $bootstrap ? $wiki : '';
    $process = proc_open(['php', 'maintenance/run.php', 'update', '--quick', '--wiki=' . $wiki],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $pending . '.log', 'a'], 2 => ['file', $pending . '.log', 'a']],
        $pipes, '/var/www/mediawiki', $env);
    if (!is_resource($process)) { exit(1); }
    $status = farmWaitProcess($process);
    $process = null;
    if ($stopping || $status !== 0) { exit(1); }
}
unlink($pending);
