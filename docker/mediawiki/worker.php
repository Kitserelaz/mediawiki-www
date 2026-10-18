<?php
require_once __DIR__ . '/process.php';
require __DIR__ . '/private.php';
$stopping = false;
$child = null;
pcntl_async_signals(true);
$stop = function () use (&$stopping, &$child) { $stopping = true; if (is_resource($child)) { proc_terminate($child, SIGTERM); } };
pcntl_signal(SIGTERM, $stop); pcntl_signal(SIGINT, $stop);
while (!$stopping) {
    try {
        $db = new PDO('mysql:host=' . (getenv('MW_DB_HOST') ?: 'database') . ';dbname=wikidb', 'mediawiki', $wgDBpassword, [PDO::ATTR_TIMEOUT => 3]);
        $wikis = $db->query('SELECT wiki_dbname FROM cw_wikis WHERE wiki_deleted=0 ORDER BY wiki_dbname')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($wikis as $wiki) {
            if ($stopping) { break; }
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $wiki)) { throw new RuntimeException('Invalid wiki identifier'); }
            if (is_file('/var/www/mediawiki/cw_cache/.provision-' . $wiki)) {
                $child = proc_open(['php', '/opt/farm/provision.php', $wiki], [STDIN, STDOUT, STDERR], $pipes);
                $status = is_resource($child) ? farmWaitProcess($child) : 1;
                $child = null;
                if ($status !== 0) { fwrite(STDERR, "Wiki provisioning will retry: $wiki\n"); continue; }
            }
            $child = proc_open(['php', 'maintenance/run.php', 'runJobs', '--wiki=' . $wiki, '--maxjobs=20', '--maxtime=10'], [STDIN, STDOUT, STDERR], $pipes, '/var/www/mediawiki');
            if (!is_resource($child) || farmWaitProcess($child) !== 0) { fwrite(STDERR, "Job batch failed: $wiki\n"); }
            $child = null;
        }
    } catch (Throwable $e) { fwrite(STDERR, "Worker waiting for initialized farm: " . $e->getMessage() . "\n"); }
    for ($n = 0; $n < 5 && !$stopping; $n++) { sleep(1); }
}
