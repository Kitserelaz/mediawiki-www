<?php
require_once __DIR__ . '/process.php';
require_once __DIR__ . '/dispatch.php';
require __DIR__ . '/private.php';
$kind = $dispatchKind ?? ($argv[1] ?? 'jobs');
if (!in_array($kind, ['jobs', 'provision'], true)) { throw new RuntimeException('Unknown dispatch kind'); }
$stream = $kind === 'jobs' ? FarmDispatch::JOBS : FarmDispatch::PROVISION;
$pending = $kind === 'jobs' ? FarmDispatch::JOB_PENDING : FarmDispatch::PROVISION_PENDING;
$consumer = gethostname() . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
$claimMs = max(1000, (int)(getenv('MW_STREAM_CLAIM_MS') ?: 30000));
$heartbeatFile = '/tmp/farm-dispatch-' . $kind . '-heartbeat';
$stopping = false;
$child = null;
pcntl_async_signals(true);
$stop = static function () use (&$stopping, &$child): void {
    $stopping = true;
    if (is_resource($child)) { proc_terminate($child, SIGTERM); }
};
pcntl_signal(SIGTERM, $stop);
pcntl_signal(SIGINT, $stop);
$activeWiki = static function (string $wiki) use ($wgDBpassword): bool {
    $db = new PDO('mysql:host=' . (getenv('MW_DB_HOST') ?: 'database') . ';dbname=wikidb',
        getenv('MW_DB_USER') ?: 'mediawiki', $wgDBpassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $query = $db->prepare('SELECT 1 FROM cw_wikis WHERE wiki_dbname=? AND wiki_deleted=0');
    $query->execute([$wiki]);
    return (bool)$query->fetchColumn();
};
while (!$stopping) {
    try {
        $redis = FarmDispatch::connect();
        FarmDispatch::group($redis, $stream);
        // One reconciliation at connection/startup for migration/restore, never a wiki sweep.
        foreach ($redis->hGetAll($pending) as $key => $id) {
            if (!$redis->xRange($stream, $id, $id, 1)) {
                $redis->eval("if redis.call('HGET',KEYS[1],ARGV[1]) == ARGV[2] then return redis.call('HDEL',KEYS[1],ARGV[1]) end return 0", [$pending, $key, $id], 1);
                if ($kind === 'provision') { FarmDispatch::provision($redis, $key); }
            }
        }
        if ($kind === 'jobs') {
            foreach ($redis->sMembers('global:jobqueue:s-queuesWithJobs') as $queue) { FarmDispatch::job($redis, $queue); }
        } else {
            foreach (glob('/var/www/mediawiki/cw_cache/.provision-*') as $file) {
                if (preg_match('/^\.provision-([a-z0-9][a-z0-9_]{0,63})$/D', basename($file), $match)) {
                    FarmDispatch::provision($redis, $match[1]);
                }
            }
        }
        $cursor = '0-0';
        fwrite(STDOUT, "Stream consumer ready: $kind ($consumer)\n");
        while (!$stopping) {
            // This checks only unacknowledged deliveries, not wiki queues. New work
            // wakes XREADGROUP immediately; the timeout services leases and signals.
            $claimed = $redis->rawCommand('XAUTOCLAIM', $stream, FarmDispatch::GROUP, $consumer,
                (string)$claimMs, $cursor, 'COUNT', '1');
            $cursor = $claimed[0];
            $messages = $claimed[1];
            if (!$messages) {
                $read = $redis->rawCommand('XREADGROUP', 'GROUP', FarmDispatch::GROUP, $consumer,
                    'COUNT', '1', 'BLOCK', '1000', 'STREAMS', $stream, '>');
                $messages = $read ? $read[0][1] : [];
            }
            file_put_contents($heartbeatFile, (string)time());
            if ($stopping) { break; }
            foreach ($messages as [$id, $flat]) {
                $fields = FarmDispatch::fields($flat);
                $queue = $fields['queue'] ?? '';
                $wiki = $fields['wiki'] ?? '';
                $ready = '';
                try {
                    if ($kind === 'jobs') {
                        $decoded = json_decode($queue, true);
                        if (!is_array($decoded) || count($decoded) !== 2 || !is_string($decoded[0]) ||
                            !is_string($decoded[1]) || $decoded[0] === '' || strlen($decoded[0]) > 255 || str_contains($decoded[0], "\0")) {
                            throw new InvalidArgumentException('Invalid job dispatch queue');
                        }
                        [$type, $wiki] = $decoded;
                        $ready = rawurlencode($wiki) . ':jobqueue:' . rawurlencode($type) . ':l-unclaimed';
                        if (($fields['ready'] ?? '') !== $ready) { throw new InvalidArgumentException('Invalid ready queue key'); }
                    }
                    if (!FarmDispatch::validWiki($wiki)) { throw new InvalidArgumentException('Invalid dispatch wiki'); }
                    $key = $kind === 'jobs' ? $queue : $wiki;
                    // Retire a duplicate/superseded signal without touching its successor.
                    if ($redis->hGet($pending, $key) !== $id) {
                        FarmDispatch::finish($redis, $stream, $pending, $consumer, $id, $key, '', false);
                        continue;
                    }
                    if (!$activeWiki($wiki)) {
                        // A creation event can precede SQL visibility. Keep it durable,
                        // using short initial deferrals and the normal retry lease later.
                        if ((int)explode('-', $id)[0] > (int)(microtime(true) * 1000) - 60000) {
                            FarmDispatch::renew($redis, $stream, $consumer, $id, max(0, $claimMs - 1000));
                        } else {
                            fwrite(STDERR, "Dispatch awaiting registered/active wiki: $wiki\n");
                        }
                        continue;
                    }
                    if ($kind === 'jobs' && ($redis->hExists(FarmDispatch::PROVISION_PENDING, $wiki) ||
                        is_file('/var/www/mediawiki/cw_cache/.provision-' . $wiki))) {
                        FarmDispatch::renew($redis, $stream, $consumer, $id, max(0, $claimMs - 1000));
                        continue;
                    }
                    if ($kind === 'jobs' && $redis->lLen($ready) === 0) {
                        FarmDispatch::finish($redis, $stream, $pending, $consumer, $id, $key, $ready);
                        continue;
                    }
                    if ($kind === 'provision') {
                        $marker = '/var/www/mediawiki/cw_cache/.provision-' . $wiki;
                        if (!is_file($marker)) { file_put_contents($marker, gmdate('c')); }
                        $redis->hMSet(FarmDispatch::statusKey($wiki), ['state' => 'running', 'started_ms' => (string)round(microtime(true) * 1000)]);
                        $command = ['php', '/opt/farm/provision.php', $wiki];
                    } else {
                        $command = ['php', 'maintenance/run.php', 'runJobs', '--wiki=' . $wiki,
                            '--type=' . $type, '--maxjobs=20', '--maxtime=10'];
                    }
                    fwrite(STDOUT, "Dispatch $kind: $wiki" . ($kind === 'jobs' ? "/$type" : '') . "\n");
                    $child = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
                        $pipes, '/var/www/mediawiki');
                    $renew = static function () use ($redis, $stream, $consumer, $id, $heartbeatFile): void {
                        FarmDispatch::renew($redis, $stream, $consumer, $id);
                        file_put_contents($heartbeatFile, (string)time());
                    };
                    $status = is_resource($child) ? farmWaitProcess($child, $renew) : 1;
                    $child = null;
                    if ($stopping) { break; }
                    if ($status !== 0) { throw new RuntimeException("$kind child failed for $wiki (exit $status)"); }
                    FarmDispatch::finish($redis, $stream, $pending, $consumer, $id, $key, $ready, true,
                        $kind === 'provision' ? FarmDispatch::statusKey($wiki) : '');
                } catch (InvalidArgumentException $e) {
                    $redis->rawCommand('XADD', 'global:farm:dispatch-dead', 'MAXLEN', '~', '1000', '*',
                        'stream', $stream, 'id', $id, 'error', $e->getMessage());
                    FarmDispatch::finish($redis, $stream, $pending, $consumer, $id, $queue ?: $wiki, '', false);
                    fwrite(STDERR, 'Invalid dispatch quarantined: ' . mb_scrub($e->getMessage(), 'UTF-8') . "\n");
                } catch (Throwable $e) {
                    if (is_resource($child)) { proc_terminate($child, SIGTERM); farmWaitProcess($child); $child = null; }
                    fwrite(STDERR, 'Dispatch retained for retry: ' . mb_scrub($e->getMessage(), 'UTF-8') . "\n");
                    // Leave the delivery pending. Redis/jobchron retain the actual jobs.
                    if ($e instanceof RedisException) { throw $e; }
                }
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Dispatch reconnecting: ' . mb_scrub($e->getMessage(), 'UTF-8') . "\n");
        for ($n = 0; $n < 10 && !$stopping; $n++) { usleep(100000); }
    }
}
fwrite(STDOUT, "Stream consumer stopped: $kind\n");
