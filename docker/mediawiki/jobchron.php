<?php
declare(strict_types=1);

// Share the farm's locked wikimedia/ip-utils dependency; do not bootstrap MediaWiki.
require '/var/www/mediawiki/vendor/autoload.php';
require __DIR__ . '/jobrunner/src/RedisJobChronService.php';

const FARM_JOBCHRON_HEARTBEAT = '/tmp/farm-jobchron-heartbeat';

class FarmJobChronService extends RedisJobChronService {
    public function incrStats($event, $delta = 1) {
        parent::incrStats($event, $delta);
        // Only a completed, successful pass proves that Redis maintenance works.
        if (str_starts_with($event, 'periodictasks.done.') && $delta > 0) {
            if (file_put_contents(FARM_JOBCHRON_HEARTBEAT, (string)time(), LOCK_EX) === false) {
                throw new RuntimeException('Cannot update jobchron heartbeat');
            }
        }
    }
}

// Enable atomic ready/retry wake-ups in the locally patched chron Lua script.
putenv('MW_STREAM_DISPATCH=1');
RedisJobService::checkEnvironment();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '256M');
set_time_limit(0);

$policyFile = getenv('MW_JOBCHRON_POLICY') ?: __DIR__ . '/jobchron-policy.json';
$policy = json_decode(file_get_contents($policyFile), true, 512, JSON_THROW_ON_ERROR);
foreach (['attempts', 'claimTTL'] as $limit) {
    $values = $policy['limits'][$limit] ?? null;
    if (!is_array($values) || !isset($values['*'])) {
        throw new RuntimeException("Missing jobchron policy: $limit");
    }
    foreach ($values as $type => $value) {
        if (!is_string($type) || $type === '' || !is_int($value) || $value < 1) {
            throw new RuntimeException("Invalid jobchron policy: $limit");
        }
    }
}
$redis = getenv('MW_REDIS') ?: 'redis:6379';
$server = Wikimedia\IPUtils::splitHostAndPort($redis);
if (!$server || !$server[0] || !$server[1] || $server[1] < 1 || $server[1] > 65535) {
    throw new RuntimeException('MW_REDIS requires a host and port');
}
$config = [
    // This service never executes jobs. worker.php remains the dispatcher.
    'groups' => [],
    'dispatcher' => '/bin/false',
    'redis' => ['aggregators' => [$redis], 'queues' => [$redis]],
    'limits' => $policy['limits'],
];
$configFile = tempnam('/tmp', 'farm-jobchron-');
if ($configFile === false || file_put_contents($configFile, json_encode($config, JSON_THROW_ON_ERROR)) === false) {
    throw new RuntimeException('Cannot write jobchron configuration');
}
try {
    $service = FarmJobChronService::init(['config-file' => $configFile]);
} finally {
    unlink($configFile);
}
if (is_file(FARM_JOBCHRON_HEARTBEAT)) { unlink(FARM_JOBCHRON_HEARTBEAT); }
$service->main();
