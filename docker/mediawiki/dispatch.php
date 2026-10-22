<?php
/** Durable wake-ups only; job payloads and claims remain owned by MediaWiki. */
final class FarmDispatch {
    public const JOBS = 'global:jobqueue:dispatch';
    public const JOB_PENDING = 'global:jobqueue:dispatch-pending';
    public const PROVISION = 'global:farm:provision';
    public const PROVISION_PENDING = 'global:farm:provision-pending';
    public const GROUP = 'farm-dispatch-v1';

    public static function connect(): Redis {
        $address = getenv('MW_REDIS') ?: 'redis:6379';
        $parts = parse_url('tcp://' . $address);
        if (!$parts || empty($parts['host'])) { throw new RuntimeException('Invalid MW_REDIS'); }
        $redis = new Redis();
        $redis->connect($parts['host'], $parts['port'] ?? 6379, 3);
        $redis->setOption(Redis::OPT_READ_TIMEOUT, 5);
        return $redis;
    }

    public static function validWiki(string $wiki): bool {
        return (bool)preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/D', $wiki);
    }

    public static function statusKey(string $wiki): string {
        return 'global:farm:provision-status:' . $wiki;
    }

    public static function provision(Redis $redis, string $wiki): string {
        if (!self::validWiki($wiki)) { throw new RuntimeException('Invalid provisioning wiki'); }
        // Publish before filesystem work: a crashed producer cannot strand a marker.
        $id = $redis->eval(<<<'LUA'
local stream, pending, status = unpack(KEYS)
local st, ht, tt = redis.call('TYPE',stream).ok, redis.call('TYPE',pending).ok, redis.call('TYPE',status).ok
if (st ~= 'none' and st ~= 'stream') or (ht ~= 'none' and ht ~= 'hash') or (tt ~= 'none' and tt ~= 'hash') then
    return redis.error_reply('Invalid provision dispatch key type')
end
local existing = redis.call('HGET',pending,ARGV[1])
if existing then return existing end
local id = redis.call('XADD',stream,'*','wiki',ARGV[1])
redis.call('HSET',pending,ARGV[1],id)
redis.call('HSET',status,'state','queued','queued_ms',string.match(id,'^(%d+)-'),'event',id)
redis.call('EXPIRE',status,604800)
return id
LUA, [self::PROVISION, self::PROVISION_PENDING, self::statusKey($wiki), $wiki], 3);
        if (!is_string($id) || $id === '') { throw new RuntimeException('Provision dispatch was not persisted'); }
        return $id;
    }

    public static function job(Redis $redis, string $queue): void {
        $decoded = json_decode($queue, true);
        if (!is_array($decoded) || count($decoded) !== 2 || !is_string($decoded[0]) ||
            !is_string($decoded[1]) || !self::validWiki($decoded[1])) { return; }
        [$type, $wiki] = $decoded;
        $ready = rawurlencode($wiki) . ':jobqueue:' . rawurlencode($type) . ':l-unclaimed';
        $redis->eval(<<<'LUA'
if redis.call('LLEN',KEYS[3]) > 0 and redis.call('HEXISTS',KEYS[2],ARGV[1]) == 0 then
    local id = redis.call('XADD',KEYS[1],'*','queue',ARGV[1],'ready',KEYS[3])
    redis.call('HSET',KEYS[2],ARGV[1],id)
end
return 1
LUA, [self::JOBS, self::JOB_PENDING, $ready, $queue], 3);
    }

    public static function group(Redis $redis, string $stream): void {
        try { $redis->rawCommand('XGROUP', 'CREATE', $stream, self::GROUP, '0', 'MKSTREAM'); }
        catch (RedisException $e) { if (!str_contains($e->getMessage(), 'BUSYGROUP')) { throw $e; } }
    }

    public static function fields(array $flat): array {
        $fields = [];
        for ($i = 0; $i < count($flat); $i += 2) { $fields[$flat[$i]] = $flat[$i + 1]; }
        return $fields;
    }

    public static function renew(Redis $redis, string $stream, string $consumer, string $id, int $idle = 0): void {
        $ok = $redis->eval(<<<'LUA'
local p = redis.call('XPENDING',KEYS[1],ARGV[1],ARGV[3],ARGV[3],1)
if #p == 0 or p[1][2] ~= ARGV[2] then return 0 end
redis.call('XCLAIM',KEYS[1],ARGV[1],ARGV[2],0,ARGV[3],'IDLE',ARGV[4],'JUSTID')
return 1
LUA, [$stream, self::GROUP, $consumer, $id, (string)$idle], 1);
        if (!$ok) { throw new RuntimeException('Dispatch lease lost'); }
    }

    public static function finish(Redis $redis, string $stream, string $pending, string $consumer,
        string $id, string $key, string $ready = '', bool $reschedule = true, string $status = ''): void {
        $redis->eval(<<<'LUA'
local stream, pending, ready, status = unpack(KEYS)
local group, consumer, id, queue, again = unpack(ARGV)
local p = redis.call('XPENDING',stream,group,id,id,1)
if #p == 0 or p[1][2] ~= consumer then return 0 end
if redis.call('HGET',pending,queue) == id then
    if ready ~= '' and again == '1' and redis.call('LLEN',ready) > 0 then
        local next = redis.call('XADD',stream,'*','queue',queue,'ready',ready)
        redis.call('HSET',pending,queue,next)
    else
        redis.call('HDEL',pending,queue)
    end
end
if status ~= '' then
    local now = redis.call('TIME')
    redis.call('HSET',status,'state','complete','finished_ms',now[1]*1000+math.floor(now[2]/1000))
    redis.call('EXPIRE',status,604800)
end
redis.call('XACK',stream,group,id)
redis.call('XDEL',stream,id)
return 1
LUA, [$stream, $pending, $ready, $status, self::GROUP, $consumer, $id, $key, $reschedule ? '1' : '0'], 4);
    }
}
