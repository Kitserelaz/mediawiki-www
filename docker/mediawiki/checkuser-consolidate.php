<?php
/** Consolidate legacy local central indexes without deleting source data. */
require __DIR__ . '/private.php';
$apply = in_array('--apply', $argv, true);
if ($apply && !in_array('--writers-stopped', $argv, true)) {
    throw new RuntimeException('Stop web, PHP, scheduler, worker, provisioner and chron; then use --apply --writers-stopped');
}
$db = new PDO('mysql:host=' . (getenv('MW_DB_HOST') ?: 'database') . ';dbname=wikidb',
    getenv('MW_DB_USER') ?: 'mediawiki', $wgDBpassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
if ((int)$db->query("SELECT GET_LOCK('farm-checkuser-consolidate',0)")->fetchColumn() !== 1) {
    throw new RuntimeException('Another consolidation is running');
}
$quote = static function (string $value): string {
    if (!preg_match('/^[a-z0-9_]{1,64}$/D', $value)) { throw new RuntimeException('Invalid database identifier'); }
    return '`' . $value . '`';
};
$shared = [];
foreach ($db->query('SELECT ciwm_id,ciwm_wiki FROM wikidb.cuci_wiki_map') as $row) {
    $shared[(int)$row['ciwm_id']] = $row['ciwm_wiki'];
}
$schemas = $db->query("SELECT DISTINCT TABLE_SCHEMA FROM information_schema.TABLES
    WHERE TABLE_NAME IN ('cuci_user','cuci_temp_edit') AND TABLE_SCHEMA <> 'wikidb'
    ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$plan = [];
foreach ($schemas as $schema) {
    $s = $quote($schema);
    $local = [];
    foreach ($db->query("SELECT ciwm_id,ciwm_wiki FROM $s.cuci_wiki_map") as $row) {
        $local[(int)$row['ciwm_id']] = $row['ciwm_wiki'];
    }
    $maps = [];
    foreach (['cuci_user' => 'ciu', 'cuci_temp_edit' => 'cite'] as $table => $prefix) {
        foreach ($db->query("SELECT {$prefix}_ciwm_id AS id,COUNT(*) AS n FROM $s.$table GROUP BY {$prefix}_ciwm_id") as $row) {
            $id = (int)$row['id'];
            // Old jobs sometimes used the shared map ID while writing locally.
            // Accept that fallback only when its name identifies this source wiki.
            $wiki = $local[$id] ?? (($shared[$id] ?? null) === $schema ? $schema : null);
            if ($wiki === null) { throw new RuntimeException("Unresolved $schema/$table map ID $id; no writes performed"); }
            $quote($wiki);
            $maps[$id]['wiki'] = $wiki;
            $maps[$id][$table] = (int)$row['n'];
        }
    }
    if ($maps) { $plan[$schema] = $maps; }
}
$verified = 0;
if ($apply) {
    $db->beginTransaction();
    try {
        $addMap = $db->prepare('INSERT IGNORE INTO wikidb.cuci_wiki_map (ciwm_wiki) VALUES (?)');
        $getMap = $db->prepare('SELECT ciwm_id FROM wikidb.cuci_wiki_map WHERE ciwm_wiki=?');
        foreach ($plan as $schema => $maps) {
            $s = $quote($schema);
            foreach ($maps as $old => $map) {
                $addMap->execute([$map['wiki']]); $getMap->execute([$map['wiki']]);
                $new = (int)$getMap->fetchColumn();
                if (!$new) { throw new RuntimeException('Cannot resolve destination wiki map'); }
                foreach (['cuci_user' => ['ciu','central_id'], 'cuci_temp_edit' => ['cite','ip_hex']] as $table => [$prefix,$identity]) {
                    if (!isset($map[$table])) { continue; }
                    $identity = $prefix . '_' . $identity;
                    $sql = "INSERT INTO wikidb.$table ($identity,{$prefix}_ciwm_id,{$prefix}_timestamp)
                        SELECT $identity,?,{$prefix}_timestamp FROM $s.$table WHERE {$prefix}_ciwm_id=?
                        ON DUPLICATE KEY UPDATE {$prefix}_timestamp=GREATEST(wikidb.$table.{$prefix}_timestamp,VALUES({$prefix}_timestamp))";
                    $db->prepare($sql)->execute([$new,$old]);
                    $check = $db->prepare("SELECT COUNT(*) FROM $s.$table a LEFT JOIN wikidb.$table b
                        ON b.$identity=a.$identity AND b.{$prefix}_ciwm_id=?
                        WHERE a.{$prefix}_ciwm_id=? AND (b.{$prefix}_ciwm_id IS NULL OR b.{$prefix}_timestamp<a.{$prefix}_timestamp)");
                    $check->execute([$new,$old]);
                    if ((int)$check->fetchColumn()) { throw new RuntimeException('Destination verification failed'); }
                    $verified += $map[$table];
                }
            }
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}
echo json_encode(['mode'=>$apply?'applied':'dry-run','source_plan'=>$plan,
    'verified_source_rows'=>$verified,'source_tables_retained'=>true], JSON_PRETTY_PRINT),"\n";
