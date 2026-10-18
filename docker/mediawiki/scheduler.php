<?php
require_once __DIR__ . '/process.php';
// One scheduler per stack. Add production schedules only after deployment inventory.
require __DIR__ . '/private.php';
$stop = false;
$child = null;
pcntl_async_signals(true);
$terminate = function () use (&$stop, &$child) { $stop = true; if (is_resource($child)) { proc_terminate($child, SIGTERM); } };
pcntl_signal(SIGTERM, $terminate); pcntl_signal(SIGINT, $terminate);
$schedules = json_decode(file_get_contents('/etc/farm/maintenance.json'), true, 512, JSON_THROW_ON_ERROR);
$next = [];
foreach ($schedules as $entry) {
    if (empty($entry['name']) || ($entry['interval_seconds'] ?? 0) < 60 || !is_array($entry['command'] ?? null)) {
        throw new RuntimeException('Invalid maintenance schedule');
    }
}
while (!$stop) {
    foreach ($schedules as $entry) {
        if ($stop || time() < ($next[$entry['name']] ?? 0)) { continue; }
        $lock = fopen('/var/www/mediawiki/cw_cache/.scheduler.lock', 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); continue; }
        try {
            $targets = [null];
            if (!empty($entry['per_wiki'])) {
                $db = new PDO('mysql:host=' . (getenv('MW_DB_HOST') ?: 'database') . ';dbname=wikidb', 'mediawiki', $wgDBpassword);
                $targets = $db->query('SELECT wiki_dbname FROM cw_wikis WHERE wiki_deleted=0')->fetchAll(PDO::FETCH_COLUMN);
            }
            foreach ($targets as $wiki) {
                if ($stop) { break; }
                $args = $entry['command'];
                if ($wiki !== null) { $args[] = '--wiki=' . $wiki; }
                $child = proc_open($args, [STDIN, STDOUT, STDERR], $pipes, '/var/www/mediawiki');
                if (!is_resource($child) || farmWaitProcess($child) !== 0) { fwrite(STDERR, "Scheduled command failed: {$entry['name']}\n"); }
                $child = null;
            }
        } catch (Throwable $e) { fwrite(STDERR, "Scheduler waiting for initialized farm: " . $e->getMessage() . "\n"); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
        $next[$entry['name']] = time() + $entry['interval_seconds'];
    }
    sleep(1);
}
