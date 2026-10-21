<?php
if (getenv('MW_TEST_SUITE_ISOLATED') !== '1' || getenv('MW_ENV') !== 'development' ||
    getenv('MW_DB_HOST') !== 'database') {
    throw new RuntimeException('Reset requires the explicitly isolated upstream-test project');
}
$db = new PDO('mysql:host=database', 'root', trim(file_get_contents('/run/secrets/db-root-password')),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE USER IF NOT EXISTS 'wikiuser'@'localhost' IDENTIFIED BY " . $db->quote(bin2hex(random_bytes(24))));
// These fixed names are created by the upstream tests outside PHPUnit's
// prefixed main schema. Never infer a deletion list from existing databases.
foreach (['remotewikitest', 'activetest', 'inactivetest', 'closuretest',
    'closureinactivetest', 'closureinactiveineligibletest', 'removaltest',
    'removalineligibletest', 'createwikitest', 'createwikiprivatetest',
    'deletewikitest', 'recreatewikitest', 'renamewikitest'] as $name) {
    $db->exec('DROP DATABASE IF EXISTS `' . $name . '`');
}
foreach (glob('/var/www/mediawiki/cache/upstream/*.php') as $file) { unlink($file); }
echo "Reserved upstream-test databases and metadata caches reset.\n";
