<?php
require_once __DIR__ . '/process.php';
// Process only explicit pending new-wiki provisioning, never existing wiki migrations.
require __DIR__ . '/runtime.php';
$wiki = $argv[1] ?? '';
if (!preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/D', $wiki)) { exit(1); }
$pending = "/var/www/mediawiki/cw_cache/.provision-$wiki";
if (!is_file($pending)) { exit(0); }
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
pcntl_signal(SIGTERM, $stop, false);
pcntl_signal(SIGINT, $stop, false);
$env = getenv();
// The post-commit creator normally prepared this data using its existing services.
// Missing, changed or partial metadata still takes the full fresh-process repair.
$metadataSource = 'creator';
$metadataLock = fopen($pending . '.metadata.lock', 'c');
if (!is_resource($metadataLock) || !flock($metadataLock, LOCK_SH)) { exit(1); }
$metadataReady = farmPreparedWikiMetadataIsCurrent($wiki);
flock($metadataLock, LOCK_UN);
fclose($metadataLock);
if ($stopping) { exit(1); }
if (!$metadataReady) {
    $metadataSource = 'repair';
    $process = proc_open(['php', 'maintenance/run.php', '/opt/farm/prepare-wiki.php',
        '--wiki=metawiki', '--target=' . $wiki],
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes,
        '/var/www/mediawiki', array_merge($env, ['MW_BOOTSTRAP_DB' => '']));
    $status = is_resource($process) ? farmWaitProcess($process) : 1;
    $process = null;
    if ($stopping || $status !== 0 || !farmWikiMetadataIsComplete($wiki)) { exit(1); }
}
if ($stopping) { exit(1); }
file_put_contents($pending . '.log', "Provisioning metadata: $metadataSource\n", FILE_APPEND);
// Install enabled-extension schemas once with the target wiki's full settings.
$env['MW_BOOTSTRAP_DB'] = '';
$process = proc_open(['php', 'maintenance/run.php', 'update', '--quick', '--wiki=' . $wiki,
    '--conf=' . __DIR__ . '/provision-settings.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $pending . '.log', 'a'], 2 => ['file', $pending . '.log', 'a']],
    $pipes, '/var/www/mediawiki', $env);
if (!is_resource($process)) { exit(1); }
$status = farmWaitProcess($process);
$process = null;
if ($stopping || $status !== 0) { exit(1); }
@unlink($pending . '.metadata.json');
unlink($pending);
