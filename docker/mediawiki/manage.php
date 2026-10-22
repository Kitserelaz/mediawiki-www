<?php
require_once __DIR__ . '/process.php';
// Schema and import operations are explicit and serialized with a database advisory lock.
require __DIR__ . '/private.php';
require __DIR__ . '/runtime.php';
require __DIR__ . '/upgrade-preflight.php';

$managementStopping = false;
$managementChild = null;
pcntl_async_signals(true);
$stopManagement = static function () use (&$managementStopping, &$managementChild): void {
    $managementStopping = true;
    if (is_resource($managementChild)) { proc_terminate($managementChild, SIGTERM); }
};
pcntl_signal(SIGTERM, $stopManagement);
pcntl_signal(SIGINT, $stopManagement);
function command(array $args, array $env = []): void {
    global $managementStopping, $managementChild;
    if ($managementStopping) { throw new RuntimeException('Maintenance interrupted'); }
    $proc = proc_open($args, [STDIN, STDOUT, STDERR], $pipes, '/var/www/mediawiki', array_merge(getenv(), $env));
    $managementChild = $proc;
    $status = farmWaitProcess($proc);
    $managementChild = null;
    if ($managementStopping || $status !== 0) { throw new RuntimeException('Command failed: ' . implode(' ', array_slice($args, 0, 3))); }
}
function identifier(string $value): string {
    if (!preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/D', $value)) { throw new RuntimeException('Invalid database identifier'); }
    return "`$value`";
}
function connectDb(?string $database = null): PDO {
    global $farmSecrets;
    return new PDO('mysql:host=' . farmEnv('MW_DB_HOST', 'database') . ($database ? ';dbname=' . $database : ''),
        farmEnv('MW_DB_USER', 'mediawiki'), $farmSecrets['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
function wikiList(): array {
    return connectDb('wikidb')->query('SELECT wiki_dbname FROM cw_wikis ORDER BY wiki_dbname')->fetchAll(PDO::FETCH_COLUMN);
}
function updateWiki(string $wiki, bool $bootstrap = true): void {
    identifier($wiki);
    command(['php', 'maintenance/run.php', 'update', '--quick', '--doshared', '--wiki=' . $wiki], ['MW_BOOTSTRAP_DB' => $bootstrap ? $wiki : '']);
}
function auxiliary(): void {
    command(['php', 'maintenance/run.php', '/opt/farm/auxiliary-schema.php', '--wiki=centralauth'], ['MW_BOOTSTRAP_DB' => 'centralauth', 'MW_BOOTSTRAP_AUX' => '1']);
}
function refresh(): void {
    command(['php', 'maintenance/run.php', '/opt/farm/refresh.php', '--wiki=metawiki'], ['MW_BOOTSTRAP_DB' => 'metawiki']);
    // Rebuild again with the complete runtime configuration, including additional rights.
    command(['php', 'maintenance/run.php', '/opt/farm/refresh.php', '--wiki=metawiki'], ['MW_BOOTSTRAP_DB' => '']);
}
function verifyDomains(): void {
    command(['php', 'maintenance/run.php', '/opt/farm/verify-domains.php', '--wiki=metawiki'], ['MW_BOOTSTRAP_DB' => 'metawiki']);
}
function seedFarmDefaults(bool $freshProduction = false): void {
    if (farmEnv('MW_ENV', 'development') === 'production' && !$freshProduction) { throw new RuntimeException('Use explicit fresh-production initialization for a new production farm'); }
    command(['php', 'maintenance/run.php', '/var/www/mediawiki/extensions/ManageWiki/maintenance/PopulateDefaults.php', '--wiki=metawiki'], ['MW_BOOTSTRAP_DB' => 'metawiki']);
    foreach (['metawiki', 'loginwiki', 'testwiki'] as $wiki) {
        foreach (['PopulateGroupPermissionsWithDefaults', 'PopulateNamespacesWithDefaults'] as $script) {
            command(['php', 'maintenance/run.php', '/var/www/mediawiki/extensions/ManageWiki/maintenance/' . $script . '.php', '--wiki=' . $wiki], ['MW_BOOTSTRAP_DB' => $wiki]);
        }
    }
}
function grantApplication(PDO $root): void {
    global $farmSecrets;
    $password = $root->quote($farmSecrets['db_password']);
    $root->exec("CREATE USER IF NOT EXISTS 'mediawiki'@'%' IDENTIFIED BY $password");
    // Database-level wildcard privileges let CreateWiki provision new *wiki databases.
    foreach (['%wiki', '%wikibeta', 'wikidb', 'centralauth', 'incidents'] as $db) {
        $root->exec("GRANT ALL PRIVILEGES ON `$db`.* TO 'mediawiki'@'%'");
    }
}
$mode = $argv[1] ?? 'help';
try {
    if ($mode === 'help') {
        echo "Commands: fresh | fresh-production | seed-development | preflight | update | refresh | list | backup DIRECTORY | restore DIRECTORY\n"; exit(0);
    }
    if (in_array($mode, ['fresh', 'fresh-production', 'restore'], true)) {
        $root = new PDO('mysql:host=' . farmEnv('MW_DB_HOST', 'database'), 'root', trim(file_get_contents('/run/secrets/db-root-password')), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ((int)$root->query("SELECT GET_LOCK('farm-initialization', 0)")->fetchColumn() !== 1) { throw new RuntimeException('Another initialization is running'); }
        grantApplication($root);
    } else {
        $lock = connectDb();
        if ((int)$lock->query("SELECT GET_LOCK('farm-initialization', 0)")->fetchColumn() !== 1) { throw new RuntimeException('Another maintenance operation is running'); }
    }
    if (in_array($mode, ['fresh', 'fresh-production'], true)) {
        $freshProduction = $mode === 'fresh-production';
        if ($freshProduction !== (farmEnv('MW_ENV', 'development') === 'production')) {
            throw new RuntimeException('Use fresh for development or fresh-production for a new production farm');
        }
        $state = '/var/www/mediawiki/cw_cache/' . ($freshProduction ? '.fresh-production-complete' : '.fresh-complete');
        if (file_exists($state)) { echo "Already initialized; use update for schema changes.\n"; exit(0); }
        if ($freshProduction) {
            $pending = '/var/www/mediawiki/cw_cache/.fresh-production-pending.json';
            $identity = ['domain' => farmEnv('MW_DOMAIN', ''), 'admin' => farmEnv('ADMIN_USER', 'Admin')];
            if (is_file($pending)) {
                if (json_decode(file_get_contents($pending), true) !== $identity) {
                    throw new RuntimeException('Interrupted production initialization has different settings');
                }
            } else {
                $existing = array_diff($root->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN), ['mysql', 'information_schema', 'performance_schema', 'sys']);
                if ($existing || glob(rtrim(farmEnv('MW_UPLOAD_ROOT', '/var/www/mediawiki/images'), '/') . '/*') || glob(rtrim(farmEnv('MW_DUMP_DIRECTORY', '/var/www/dumps'), '/') . '/*')) {
                    throw new RuntimeException('Fresh production initialization requires empty databases, uploads and dumps; use restore for existing data');
                }
                file_put_contents($pending, json_encode($identity, JSON_THROW_ON_ERROR));
            }
        }
        foreach (['wikidb', 'centralauth', 'metawiki', 'loginwiki', 'testwiki'] as $wiki) {
            $root->exec('CREATE DATABASE IF NOT EXISTS ' . identifier($wiki) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
            $db = connectDb($wiki);
            if ($db->query("SHOW TABLES LIKE 'page'")->fetch()) { continue; }
            $conf = sys_get_temp_dir() . '/farm-install-' . $wiki;
            @mkdir($conf, 0700);
            $passfile = tempnam(sys_get_temp_dir(), 'farm-db-');
            file_put_contents($passfile, $farmSecrets['db_password']);
            try {
                command(['php', 'maintenance/install.php', '--dbtype=mysql', '--dbserver=' . farmEnv('MW_DB_HOST', 'database'),
                    '--dbname=' . $wiki, '--dbuser=mediawiki', '--dbpassfile=' . $passfile,
                    '--passfile=/run/secrets/admin-password', '--server=https://' . preg_replace('/wiki$/', '', $wiki) . '.' . farmEnv('MW_DOMAIN', 'localhost'),
                    '--scriptpath=', '--confpath=' . $conf, '--skins=Vector', $wiki, farmEnv('ADMIN_USER', 'Admin')], ['MW_BOOTSTRAP_DB' => $wiki, 'MW_INSTALL_CORE' => '1', 'MW_CONFIG_FILE' => $conf . '/not-installed.php']);
            } finally { unlink($passfile); @unlink($conf . '/LocalSettings.php'); @rmdir($conf); }
        }
        foreach (['metawiki', 'loginwiki', 'testwiki'] as $wiki) { updateWiki($wiki); }
        $db = connectDb('wikidb');
        $stmt = $db->prepare("INSERT IGNORE INTO cw_wikis (wiki_dbname,wiki_sitename,wiki_language,wiki_private,wiki_creation,wiki_dbcluster,wiki_category,wiki_url) VALUES (?,?,'en',0,?,'c1','uncategorised',?)");
        foreach (['metawiki', 'loginwiki', 'testwiki'] as $wiki) {
            $stmt->execute([$wiki, ucfirst(preg_replace('/wiki$/', '', $wiki)) . ' Wiki', gmdate('YmdHis'), 'https://' . preg_replace('/wiki$/', '', $wiki) . '.' . farmEnv('MW_DOMAIN', 'localhost')]);
        }
        auxiliary();
        seedFarmDefaults($freshProduction);
        refresh();
        foreach (['metawiki', 'loginwiki', 'testwiki'] as $wiki) {
            command(['php', 'maintenance/run.php', '/var/www/mediawiki/extensions/CentralAuth/maintenance/migratePass0.php', '--wiki=' . $wiki], ['MW_BOOTSTRAP_DB' => $wiki]);
        }
        command(['php', 'maintenance/run.php', '/var/www/mediawiki/extensions/CentralAuth/maintenance/migrateAccount.php', '--wiki=metawiki', '--auto', '--attachmissing', '--username=' . farmEnv('ADMIN_USER', 'Admin'), '--homewiki=metawiki'], ['MW_BOOTSTRAP_DB' => 'metawiki']);
        command(['php', 'maintenance/run.php', '/opt/farm/seed-admin.php', '--wiki=metawiki'], ['MW_BOOTSTRAP_DB' => '', 'MW_FRESH_PRODUCTION' => $freshProduction ? '1' : '']);
        foreach (['metawiki', 'loginwiki', 'testwiki'] as $wiki) { updateWiki($wiki, false); }
        file_put_contents($state, gmdate('c'));
        if ($freshProduction) { @unlink($pending); }
    } elseif ($mode === 'seed-development') {
        seedFarmDefaults();
        refresh();
    } elseif ($mode === 'list') {
        echo implode("\n", wikiList()) . "\n";
    } elseif ($mode === 'refresh') { refresh();
    } elseif ($mode === 'preflight') {
        farmUpgradePreflight(connectDb(), wikiList());
        verifyDomains();
    } elseif ($mode === 'update') {
        farmUpgradePreflight(connectDb(), wikiList());
        verifyDomains();
        auxiliary();
        foreach (wikiList() as $wiki) { updateWiki($wiki); }
        refresh();
        foreach (wikiList() as $wiki) { updateWiki($wiki, false); }
    } elseif ($mode === 'backup') {
        $dir = $argv[2] ?? '';
        if (!preg_match('#^/backups/[A-Za-z0-9_-]+$#D', $dir)) { throw new RuntimeException('Backup directory must be below /backups/'); }
        if (file_exists($dir)) { throw new RuntimeException('Backup destination must not exist'); }
        mkdir($dir, 0700, true);
        $databases = array_values(array_filter(connectDb()->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN), static fn($name) => !in_array($name, ['information_schema', 'performance_schema', 'mysql', 'sys'], true)));
        $options = tempnam(sys_get_temp_dir(), 'farm-client-');
        file_put_contents($options, "[client]\nhost=" . farmEnv('MW_DB_HOST', 'database') . "\nuser=mediawiki\npassword=" . json_encode($farmSecrets['db_password']) . "\n");
        try {
            foreach ($databases as $wiki) {
                identifier($wiki);
                $out = fopen("$dir/$wiki.sql", 'x');
                $proc = proc_open(['mariadb-dump', '--defaults-extra-file=' . $options, '--single-transaction', '--skip-lock-tables', $wiki], [STDIN, $out, STDERR], $pipes);
                if (farmWaitProcess($proc) !== 0) { throw new RuntimeException('Database backup failed'); }
                fclose($out);
            }
        } finally { unlink($options); }
        command(['tar', '-czf', "$dir/uploads.tar.gz", '-C', farmEnv('MW_UPLOAD_ROOT', '/var/www/mediawiki/images'), '.']);
        command(['tar', '-czf', "$dir/dumps.tar.gz", '-C', farmEnv('MW_DUMP_DIRECTORY', '/var/www/dumps'), '.']);
        foreach (['redis', 'counter', 'caddy'] as $volume) {
            command(['tar', '-czf', "$dir/$volume.tar.gz", '-C', "/snapshot/$volume", '.']);
        }
        file_put_contents("$dir/manifest.json", json_encode(['databases' => array_values($databases), 'created' => gmdate('c'), 'volumes' => ['redis', 'counter', 'caddy'], 'dumps' => true], JSON_PRETTY_PRINT));
        command(['chown', '-R', fileowner('/backups') . ':' . filegroup('/backups'), $dir]);
    } elseif ($mode === 'restore') {
        $dir = $argv[2] ?? '';
        if (!preg_match('#^/backups/[A-Za-z0-9_-]+$#D', $dir)) { throw new RuntimeException('Restore directory must be below /backups/'); }
        $manifest = json_decode(file_get_contents("$dir/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
        if (empty($manifest['databases']) || !is_array($manifest['databases'])) { throw new RuntimeException('Backup manifest has no databases'); }
        if (!empty($manifest['dumps']) && !is_file("$dir/dumps.tar.gz")) { throw new RuntimeException('Missing dumps archive'); }
        foreach ($manifest['databases'] as $wiki) {
            identifier($wiki);
            if (!is_file("$dir/$wiki.sql")) { throw new RuntimeException("Missing database backup: $wiki"); }
        }
        foreach (array_merge(['uploads'], $manifest['volumes'] ?? []) as $volume) {
            if (!in_array($volume, ['uploads', 'redis', 'counter', 'caddy'], true) || !is_file("$dir/$volume.tar.gz")) { throw new RuntimeException('Missing or invalid backup archive'); }
        }
        foreach ($manifest['volumes'] ?? [] as $volume) {
            if (!in_array($volume, ['redis', 'counter', 'caddy'], true)) { throw new RuntimeException('Invalid volume in backup'); }
            if (count(glob("/snapshot/$volume/*")) > 0) { throw new RuntimeException("Restore requires an empty $volume volume"); }
        }
        foreach ($manifest['databases'] as $wiki) {
            identifier($wiki);
            if ($root->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . $root->quote($wiki))->fetchColumn() > 0) { throw new RuntimeException("Restore requires empty databases: $wiki"); }
        }
        if (count(glob(rtrim(farmEnv('MW_UPLOAD_ROOT', '/var/www/mediawiki/images'), '/') . '/*')) > 0) { throw new RuntimeException('Restore requires an empty uploads volume'); }
        if (count(glob(rtrim(farmEnv('MW_DUMP_DIRECTORY', '/var/www/dumps'), '/') . '/*')) > 0) { throw new RuntimeException('Restore requires an empty dumps volume'); }
        $options = tempnam(sys_get_temp_dir(), 'farm-client-');
        file_put_contents($options, "[client]\nhost=" . farmEnv('MW_DB_HOST', 'database') . "\nuser=mediawiki\npassword=" . json_encode($farmSecrets['db_password']) . "\n");
        try {
            foreach ($manifest['databases'] as $wiki) {
                $root->exec('CREATE DATABASE IF NOT EXISTS ' . identifier($wiki) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
                $root->exec('GRANT ALL PRIVILEGES ON ' . identifier($wiki) . ".* TO 'mediawiki'@'%'");
                $in = fopen("$dir/$wiki.sql", 'r');
                $proc = proc_open(['mariadb', '--defaults-extra-file=' . $options, $wiki], [$in, STDOUT, STDERR], $pipes);
                if (farmWaitProcess($proc) !== 0) { throw new RuntimeException("Restore failed: $wiki"); }
                fclose($in);
            }
        } finally { unlink($options); }
        command(['tar', '-xzf', "$dir/uploads.tar.gz", '--no-same-owner', '-C', farmEnv('MW_UPLOAD_ROOT', '/var/www/mediawiki/images')]);
        if (!empty($manifest['dumps'])) { command(['tar', '-xzf', "$dir/dumps.tar.gz", '--no-same-owner', '-C', farmEnv('MW_DUMP_DIRECTORY', '/var/www/dumps')]); }
        foreach ($manifest['volumes'] ?? [] as $volume) {
            command(['tar', '-xzf', "$dir/$volume.tar.gz", '--no-same-owner', '-C', "/snapshot/$volume"]);
            if ($volume === 'counter') { command(['chown', '-R', '1000:1000', '/snapshot/counter']); }
            if ($volume === 'redis') { command(['chown', '-R', '999:999', '/snapshot/redis']); }
        }
        echo "Restore complete. Run update explicitly before starting web and workers.\n";
    } else { throw new RuntimeException('Unknown command'); }
    if (getenv('MW_MANAGEMENT') && function_exists('posix_geteuid') && posix_geteuid() === 0) {
        command(['chown', '-R', 'www-data:www-data', farmEnv('MW_UPLOAD_ROOT', '/var/www/mediawiki/images'), '/var/www/mediawiki/cw_cache', '/var/www/mediawiki/cache', farmEnv('MW_DUMP_DIRECTORY', '/var/www/dumps')]);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Farm operation failed: " . $e->getMessage() . "\n"); exit(1);
}
