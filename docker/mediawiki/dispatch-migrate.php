<?php
// Compatibility bridge for rolling back to the former marker-based provisioner.
require_once __DIR__ . '/dispatch.php';
require __DIR__ . '/private.php';
if (($argv[1] ?? '') !== '--legacy-markers') {
    fwrite(STDERR, "Usage: dispatch-migrate.php --legacy-markers (stop writers first)\n");
    exit(1);
}
$redis = FarmDispatch::connect();
$db = new PDO('mysql:host=' . (getenv('MW_DB_HOST') ?: 'database') . ';dbname=wikidb',
    getenv('MW_DB_USER') ?: 'mediawiki', $wgDBpassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$query = $db->prepare('SELECT 1 FROM cw_wikis WHERE wiki_dbname=? AND wiki_deleted=0');
$count = 0;
foreach ($redis->hKeys(FarmDispatch::PROVISION_PENDING) as $wiki) {
    if (!FarmDispatch::validWiki($wiki)) { continue; }
    $query->execute([$wiki]);
    if (!$query->fetchColumn()) { continue; }
    $file = '/var/www/mediawiki/cw_cache/.provision-' . $wiki;
    if (!is_file($file) && file_put_contents($file, gmdate('c')) === false) {
        throw new RuntimeException('Cannot preserve legacy provisioning marker');
    }
    $count++;
}
echo "Preserved $count pending provisioning requests as legacy markers.\n";
