<?php

// This file is loaded only by the farm management container.
function farmIdentityTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS farm_wiki_identity (
        numeric_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        legacy_id VARCHAR(64) NULL UNIQUE,
        request_id INT NULL UNIQUE,
        hostname VARCHAR(253) NOT NULL UNIQUE,
        farm_host VARCHAR(253) NULL UNIQUE,
        storage_key VARCHAR(64) NOT NULL,
        realm VARCHAR(8) NOT NULL,
        state ENUM('reserved','active') NOT NULL DEFAULT 'reserved'
    ) ENGINE=InnoDB");
}

function farmIdentityRows(PDO $db): array {
    return $db->query('SELECT * FROM farm_wiki_identity ORDER BY numeric_id')->fetchAll(PDO::FETCH_ASSOC);
}

function farmPublishIdentityRegistry(PDO $db): void {
    $registry = ['wikis' => [], 'hosts' => [], 'aliases' => []];
    foreach (farmIdentityRows($db) as $row) {
        $wiki = $row['numeric_id'] . 'wiki';
        $registry['wikis'][$wiki] = [
            'url' => 'https://' . $row['hostname'],
            'storage' => $row['storage_key'],
            'realm' => $row['realm'],
            'farm_host' => $row['farm_host'] ?? $row['hostname'],
            'request' => $row['request_id'] === null ? null : (int)$row['request_id'],
        ];
        if ($row['state'] === 'active' && $row['legacy_id'] !== null) {
            $registry['aliases'][$row['legacy_id']] = $wiki;
        }
        // Reserved wikis still pass through the ordinary missing/pending and privacy checks.
        $domain = $row['realm'] === 'beta'
            ? farmEnv('MW_BETA_DOMAIN', 'beta.localhost') : farmEnv('MW_DOMAIN', 'localhost');
        $hosts = [$row['farm_host'], $row['hostname']];
        foreach ($hosts as $host) {
            // Custom hosts are authorized separately by the verified edge routing table.
            if (!$host || !str_ends_with($host, '.' . $domain)) {
                continue;
            }
            if (isset($registry['hosts'][$host]) && $registry['hosts'][$host] !== $wiki) {
                throw new RuntimeException('Ambiguous wiki hostname: ' . $host);
            }
            $registry['hosts'][$host] = $wiki;
        }
    }
    $directory = '/var/www/mediawiki/cw_cache';
    $temporary = tempnam($directory, '.wiki-identities-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot write wiki identity registry');
    }
    try {
        $json = json_encode($registry, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        if (file_put_contents($temporary, $json . "\n") === false || !chmod($temporary, 0644)
            || !rename($temporary, $directory . '/wiki-identities.json')) {
            throw new RuntimeException('Cannot publish wiki identity registry');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function farmValidHostname(string $host): bool {
    return strlen($host) <= 253 && substr_count($host, '.') >= 1
        && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host);
}

function farmIdentityInventory(PDO $db): array {
    $domain = farmEnv('MW_DOMAIN', 'localhost');
    $beta = farmEnv('MW_BETA_DOMAIN', 'beta.localhost');
    $rows = $db->query('SELECT wiki_dbname, wiki_url FROM cw_wikis ORDER BY BINARY wiki_dbname')
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $wiki = $row['wiki_dbname'];
        identifier($wiki);
        $suffix = str_ends_with($wiki, 'wikibeta') ? 'wikibeta' : 'wiki';
        if (!str_ends_with($wiki, $suffix)) {
            throw new RuntimeException('Unrecognized wiki identifier: ' . $wiki);
        }
        $realm = $suffix === 'wikibeta' ? 'beta' : 'default';
        $row['farm_host'] = substr($wiki, 0, -strlen($suffix)) . '.' . ($realm === 'beta' ? $beta : $domain);
        $identity = farmWikiIdentity($wiki);
        if ($identity) {
            $realm = $identity['realm'];
            $row['farm_host'] = $identity['farm_host'];
        }
        $url = $row['wiki_url'] ?: 'https://' . $row['farm_host'];
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? null) !== 'https' || !farmValidHostname($parts['host'] ?? '')
            || array_diff(array_keys($parts), ['scheme', 'host', 'path'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('Review canonical URL for ' . $wiki);
        }
        $row = ['old' => $wiki, 'host' => $parts['host'], 'farm_host' => $row['farm_host'], 'realm' => $realm];
    }
    return $rows;
}

function farmReserveWikiRequest(PDO $db, int $requestId): string {
    $domain = farmEnv('MW_DOMAIN', 'localhost');
    if (farmEnv('MW_REALM', 'default') !== 'default') {
        throw new RuntimeException('Numeric reservation requires the primary farm');
    }
    $requests = identifier(farmWikiId('metawiki')) . '.cw_requests';
    $db->beginTransaction();
    try {
        $query = $db->prepare("SELECT cw_status, cw_url, cw_dbname FROM $requests WHERE cw_id = ? FOR UPDATE");
        $query->execute([$requestId]);
        $request = $query->fetch(PDO::FETCH_ASSOC);
        if (!$request || !in_array($request['cw_status'], ['inreview', 'onhold', 'moredetails'], true)) {
            throw new RuntimeException('Request must be open for approval');
        }
        $existing = $db->prepare('SELECT numeric_id FROM farm_wiki_identity WHERE request_id = ?');
        $existing->execute([$requestId]);
        $id = $existing->fetchColumn();
        if (!$id) {
            $host = strtolower($request['cw_url']);
            if (!farmValidHostname($host) || !str_ends_with($host, '.' . $domain)
                || substr_count($host, '.') !== substr_count($domain, '.') + 1
                || preg_match('/^[0-9]+\./', $host)) {
                throw new RuntimeException('Request needs an original named farm subdomain');
            }
            $inventory = farmIdentityInventory($db);
            $occupiedHosts = array_merge(array_column($inventory, 'host'), array_column($inventory, 'farm_host'));
            if (in_array($host, $occupiedHosts, true) || isset(farmWikiRegistry()['hosts'][$host])) {
                throw new RuntimeException('Requested hostname is already assigned');
            }
            $occupied = $db->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
            $insert = $db->prepare('INSERT INTO farm_wiki_identity (request_id,hostname,storage_key,realm) VALUES (?,?,?,?)');
            $insert->execute([$requestId, $host, '', 'default']);
            $id = $db->lastInsertId();
            $move = $db->prepare('UPDATE farm_wiki_identity SET numeric_id = ?, storage_key = ? WHERE request_id = ?');
            $used = array_column(farmIdentityRows($db), 'numeric_id');
            $pending = $db->prepare("SELECT COUNT(*) FROM $requests WHERE cw_dbname = ? AND cw_id <> ? AND cw_status IN ('inreview','onhold','moredetails','approved')");
            while (true) {
                $pending->execute([$id . 'wiki', $requestId]);
                if (!in_array($id . 'wiki', $occupied, true)
                    && !in_array($id . '.' . $domain, $occupiedHosts, true) && !$pending->fetchColumn()) {
                    break;
                }
                do { $id++; } while (in_array((string)$id, array_map('strval', $used), true));
            }
            $move->execute([$id, $id . 'wiki', $requestId]);
        }
        $wiki = $id . 'wiki';
        $update = $db->prepare("UPDATE $requests SET cw_dbname = ?, cw_url = ? WHERE cw_id = ?");
        $update->execute([$wiki, $id . '.' . $domain, $requestId]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    farmPublishIdentityRegistry($db);
    return $wiki;
}

function farmWikiMigrationPlan(PDO $db): array {
    $inventory = farmIdentityInventory($db);
    $occupied = array_fill_keys($db->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN), true);
    $hosts = [];
    foreach ($inventory as $wiki) {
        foreach ([$wiki['host'], $wiki['farm_host']] as $host) {
            if (isset($hosts[$host]) && $hosts[$host] !== $wiki['old']) {
                throw new RuntimeException('Ambiguous wiki hostname: ' . $host);
            }
            $hosts[$host] = $wiki['old'];
        }
    }
    $requests = identifier(farmWikiId('metawiki')) . '.cw_requests';
    $requested = $db->query("SELECT cw_dbname FROM $requests WHERE cw_status IN ('inreview','onhold','moredetails')")
        ->fetchAll(PDO::FETCH_COLUMN);
    $id = 1;
    foreach ($inventory as &$wiki) {
        if (!isset($occupied[$wiki['old']])) {
            throw new RuntimeException('Registered wiki database missing: ' . $wiki['old']);
        }
        if (preg_match('/^[1-9][0-9]*wiki$/D', $wiki['old'])) {
            $wiki['new'] = $wiki['old'];
            continue;
        }
        $domain = $wiki['realm'] === 'beta'
            ? farmEnv('MW_BETA_DOMAIN', 'beta.localhost') : farmEnv('MW_DOMAIN', 'localhost');
        while (isset($occupied[$id . 'wiki']) || isset($hosts[$id . '.' . $domain])
            || in_array($id . 'wiki', $requested, true)) {
            $id++;
        }
        $wiki['new'] = $id++ . 'wiki';
        $occupied[$wiki['new']] = true;
    }
    return $inventory;
}

function farmQuoteCatalogIdentifier(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function farmIdentityColumns(PDO $db): array {
    return $db->query("SELECT c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME, t.ENGINE
        FROM information_schema.COLUMNS c JOIN information_schema.TABLES t
        ON c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME
        WHERE (LOWER(c.COLUMN_NAME) REGEXP '(^|_)(wiki|wikiid|wiki_id|dbname|db_name|home_db|origin_wiki|searchwiki|sourcewiki|targetwiki)$'
            OR (c.TABLE_NAME = 'sites' AND c.COLUMN_NAME = 'site_global_key'))
        AND c.DATA_TYPE IN ('varchar','char','binary','varbinary','text','tinytext','mediumtext','longtext','blob')
        AND t.TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
}

function farmCheckIdentityQueue(): void {
    require_once __DIR__ . '/dispatch.php';
    $redis = FarmDispatch::connect();
    if ($redis->hLen(FarmDispatch::JOB_PENDING) || $redis->hLen(FarmDispatch::PROVISION_PENDING)) {
        throw new RuntimeException('Drain job dispatch and provisioning before the cutover');
    }
    $cursor = null;
    do {
        foreach ($redis->scan($cursor, '*:jobqueue:*', 500) ?: [] as $key) {
            $type = $redis->type($key);
            if (($type === Redis::REDIS_LIST && $redis->lLen($key))
                || ($type === Redis::REDIS_ZSET && $redis->zCard($key))
                || ($type === Redis::REDIS_HASH && $redis->hLen($key))) {
                throw new RuntimeException('Drain delayed and in-flight wiki jobs before the cutover: ' . $key);
            }
        }
    } while ($cursor);
}

function farmIdentityReferences(PDO $db, array $plan): array {
    $old = array_column(array_filter($plan, static fn($row) => $row['old'] !== $row['new']), 'old');
    if (!$old) {
        return [];
    }
    $quoted = implode(',', array_map($db->quote(...), $old));
    $schemas = array_fill_keys([...array_column($plan, 'old'), 'wikidb', 'centralauth', 'incidents'], true);
    $result = [];
    foreach (farmIdentityColumns($db) as [$schema, $table, $column, $engine]) {
        if (!isset($schemas[$schema])) {
            continue;
        }
        $qualified = identifier($schema) . '.' . farmQuoteCatalogIdentifier($table);
        $field = farmQuoteCatalogIdentifier($column);
        if ($db->query("SELECT 1 FROM $qualified WHERE BINARY $field IN ($quoted) LIMIT 1")->fetchColumn()) {
            if ($engine !== 'InnoDB') {
                throw new RuntimeException("Wiki reference table is not transactional: $schema.$table");
            }
            $result[] = [$schema, $table, $column];
        }
    }
    sort($result);
    return $result;
}

function farmImportWikiSchema(PDO $db, string $old, string $new, string $dump, string $options): void {
    $schema = $db->prepare('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
        FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $schema->execute([$old]);
    [$charset, $collation] = $schema->fetch(PDO::FETCH_NUM);
    $objects = $db->prepare("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?");
    $objects->execute([$old]);
    $tables = $objects->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!$tables || array_diff(array_values($tables), ['BASE TABLE'])) {
        throw new RuntimeException('Review missing tables or schema objects for ' . $old);
    }
    foreach (['TRIGGERS' => 'TRIGGER_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $table => $key) {
        $check = $db->prepare("SELECT 1 FROM information_schema.$table WHERE $key = ? LIMIT 1");
        $check->execute([$old]);
        if ($check->fetchColumn()) {
            throw new RuntimeException('Review non-table schema objects for ' . $old);
        }
    }
    $db->exec('CREATE DATABASE ' . identifier($new) . ' CHARACTER SET ' . identifier($charset)
        . ' COLLATE ' . identifier($collation));
    $process = proc_open(['mariadb', '--defaults-extra-file=' . $options, $new],
        [0 => ['file', $dump, 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process) || farmWaitProcess($process) !== 0) {
        throw new RuntimeException('Schema import failed: ' . $new);
    }
    $objects->execute([$new]);
    if ($objects->fetchAll(PDO::FETCH_KEY_PAIR) != $tables) {
        throw new RuntimeException('Schema objects differ after import: ' . $new);
    }
    foreach ($tables as $table => $_) {
        $table = farmQuoteCatalogIdentifier($table);
        if ($db->query('SELECT COUNT(*) FROM ' . identifier($old) . '.' . $table)->fetchColumn()
            !== $db->query('SELECT COUNT(*) FROM ' . identifier($new) . '.' . $table)->fetchColumn()) {
            throw new RuntimeException("Row counts differ for $new.$table");
        }
    }
}

function farmApplyWikiIdentityPlan(PDO $db, string $backup, string $planFile): void {
    if (getenv('MW_FARM_READ_ONLY') !== '1' || farmNumericIdsEnabled()) {
        throw new RuntimeException('Enable read-only and leave numeric routing disabled before importing');
    }
    farmCheckIdentityQueue();
    if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $backup) || !preg_match('/^[a-zA-Z0-9_-]+\.json$/D', $planFile)) {
        throw new RuntimeException('Pass a snapshot name and a reviewed plan filename under /backups');
    }
    $dir = "/backups/$backup";
    $expected = json_decode(file_get_contents("/backups/$planFile"), true, 512, JSON_THROW_ON_ERROR);
    $plan = farmWikiMigrationPlan($db);
    $references = farmIdentityReferences($db, $plan);
    if ($expected !== ['wikis' => $plan, 'references' => $references]) {
        throw new RuntimeException('Live wiki inventory or references differ from the reviewed plan');
    }
    $manifest = json_decode(file_get_contents("$dir/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
    $databases = ['wikidb', 'centralauth', ...array_column($plan, 'old')];
    $files = ['uploads.tar.gz', 'dumps.tar.gz', 'redis.tar.gz', 'counter.tar.gz', 'caddy.tar.gz'];
    foreach (array_unique($databases) as $database) {
        if (!in_array($database, $manifest['databases'] ?? [], true)) {
            throw new RuntimeException('Missing wiki snapshot: ' . $database);
        }
        $files[] = $database . '.sql';
    }
    foreach ($files as $file) {
        if (!is_file("$dir/$file") || !filesize("$dir/$file")
            || hash_file('sha256', "$dir/$file") !== ($manifest['sha256'][$file] ?? null)) {
            throw new RuntimeException('Backup checksum missing or invalid: ' . $file);
        }
    }
    farmIdentityTable($db);
    if (farmIdentityRows($db)) {
        throw new RuntimeException('Wiki identity registry already has entries');
    }
    $options = tempnam(sys_get_temp_dir(), 'wiki-migration-');
    if ($options === false) {
        throw new RuntimeException('Cannot create MariaDB options file');
    }
    try {
        $password = trim(file_get_contents('/run/secrets/db-root-password'));
        file_put_contents($options, "[client]\nhost=" . farmEnv('MW_DB_HOST', 'database')
            . "\nuser=root\npassword=" . json_encode($password, JSON_THROW_ON_ERROR) . "\n");
        $mapping = [];
        foreach ($plan as $row) {
            if ($row['old'] !== $row['new']) {
                farmImportWikiSchema($db, $row['old'], $row['new'], "$dir/{$row['old']}.sql", $options);
                $mapping[$row['old']] = $row['new'];
            }
        }
        $db->beginTransaction();
        try {
            $insert = $db->prepare("INSERT INTO farm_wiki_identity
                (numeric_id,legacy_id,hostname,farm_host,storage_key,realm,state) VALUES (?,?,?,?,?,?,'active')");
            foreach ($plan as $row) {
                $insert->execute([substr($row['new'], 0, -4), $row['old'], $row['host'],
                    $row['farm_host'], $row['old'], $row['realm']]);
            }
            if ($mapping) {
                $cases = implode(' ', array_map(
                    static fn($old, $new) => 'WHEN ' . $db->quote($old) . ' THEN ' . $db->quote($new),
                    array_keys($mapping), array_values($mapping)
                ));
                $keys = implode(',', array_map($db->quote(...), array_keys($mapping)));
                foreach ($references as [$schema, $table, $column]) {
                    $table = identifier($mapping[$schema] ?? $schema) . '.' . farmQuoteCatalogIdentifier($table);
                    $field = farmQuoteCatalogIdentifier($column);
                    $db->exec("UPDATE $table SET $field = CASE BINARY $field $cases END WHERE BINARY $field IN ($keys)");
                }
            }
            $db->commit();
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    } finally {
        unlink($options);
    }
    farmPublishIdentityRegistry($db);
}
