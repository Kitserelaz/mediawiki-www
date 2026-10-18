<?php
// Only a reserved-domain rehearsal may create/remove this empty sentinel database.
if (getenv('MW_ENV') !== 'production' || !str_ends_with(getenv('MW_DOMAIN') ?: '', '.example.test')) {
    throw new RuntimeException('Refusal check requires an isolated reserved-domain production rehearsal');
}
$db = new PDO('mysql:host=' . (getenv('MW_DB_HOST') ?: 'database'), 'root', trim(file_get_contents('/run/secrets/db-root-password')), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (($argv[1] ?? '') === 'create') {
    $db->exec('CREATE DATABASE docker_existing_guard');
} elseif (($argv[1] ?? '') === 'remove') {
    if ((int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='docker_existing_guard'")->fetchColumn() !== 0) {
        throw new RuntimeException('Sentinel is not empty; refusing to remove it');
    }
    $db->exec('DROP DATABASE docker_existing_guard');
} else { throw new RuntimeException('Expected create or remove'); }
echo "Empty production guard sentinel updated.\n";
