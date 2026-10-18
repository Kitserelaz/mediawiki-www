<?php
// Exercise the real coordinator's signal handling without running database migrations.
$cache = '/var/www/mediawiki/cw_cache';
$wiki = 'dockersignalwiki';
$dir = sys_get_temp_dir() . '/provision-signal-' . bin2hex(random_bytes(6));
mkdir($dir . '/bin', 0700, true);
file_put_contents($cache . '/.provision-' . $wiki, 'test');
file_put_contents($cache . '/' . $wiki . '.php', '<?php return [];');
file_put_contents($dir . '/bin/php', "#!/bin/sh\nprintf '%s' \"\$\$\" > \"\$SIGNAL_TEST_DIR/child.pid\"\nexec /bin/sleep 30\n");
chmod($dir . '/bin/php', 0700);
$child = proc_open(['/usr/local/bin/php', '/opt/farm/provision.php', $wiki],
    [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, '/var/www/mediawiki',
    array_merge(getenv(), ['PATH' => $dir . '/bin:' . getenv('PATH'), 'SIGNAL_TEST_DIR' => $dir]));
try {
    for ($n = 0; $n < 50 && !is_file($dir . '/child.pid'); $n++) { usleep(100000); }
    if (!is_file($dir . '/child.pid')) { throw new RuntimeException('Updater child did not start'); }
    $pid = (int)file_get_contents($dir . '/child.pid');
    $started = microtime(true);
    proc_terminate($child, SIGTERM);
    $status = proc_close($child);
    $child = null;
    if (microtime(true) - $started > 5) { throw new RuntimeException('Shutdown waited for the updater instead of interrupting it'); }
    if ($status === 0 || posix_kill($pid, 0)) { throw new RuntimeException('Updater survived coordinator shutdown'); }
    if (!is_file($cache . '/.provision-' . $wiki)) { throw new RuntimeException('Interrupted provisioning lost its retry marker'); }
    $lock = fopen($cache . '/.provision-' . $wiki . '.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Provisioning lock was not released'); }
    echo "Provisioning shutdown stopped its updater child, released the lock and retained the retry marker.\n";
} finally {
    if (is_resource($child)) { proc_terminate($child, SIGKILL); proc_close($child); }
    foreach (glob($cache . '/.provision-' . $wiki . '*') as $file) { unlink($file); }
    @unlink($cache . '/' . $wiki . '.php');
    @unlink($dir . '/child.pid'); @unlink($dir . '/bin/php'); @rmdir($dir . '/bin'); @rmdir($dir);
}
