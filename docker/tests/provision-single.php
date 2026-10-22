<?php
// Isolated comparison harness: candidate executes the actual production code;
// the remaining coordinator is a frozen two-pass reference.
if (getenv('MW_JOBCHRON_TEST_ISOLATED') !== '1') { exit(1); }
if (getenv('MW_PROVISION_SINGLE_PASS') === '1') {
    require '/var/www/mediawiki/docker/mediawiki/provision.php';
    return;
}
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
pcntl_signal(SIGTERM, $stop);
pcntl_signal(SIGINT, $stop);
$env = getenv();
// An immediate dispatch may beat the request's deferred metadata writer.
if (!is_file("/var/www/mediawiki/cw_cache/$wiki.php")) {
    $process = proc_open(['php', 'maintenance/run.php', '/opt/farm/prepare-wiki.php',
        '--wiki=metawiki', '--target=' . $wiki],
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes,
        '/var/www/mediawiki', array_merge($env, ['MW_BOOTSTRAP_DB' => 'metawiki']));
    $status = is_resource($process) ? farmWaitProcess($process) : 1;
    $process = null;
    if ($stopping || $status !== 0) { exit(1); }
}
foreach ([true, false] as $bootstrap) {
    if ($stopping) { exit(1); }
    $env['MW_BOOTSTRAP_DB'] = $bootstrap ? $wiki : '';
    $command = ['php', 'maintenance/run.php', 'update', '--quick', '--wiki=' . $wiki];
    $process = proc_open($command,
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $pending . '.log', 'a'], 2 => ['file', $pending . '.log', 'a']],
        $pipes, '/var/www/mediawiki', $env);
    if (!is_resource($process)) { exit(1); }
    $status = farmWaitProcess($process);
    $process = null;
    if ($stopping || $status !== 0) { exit(1); }
}
unlink($pending);
