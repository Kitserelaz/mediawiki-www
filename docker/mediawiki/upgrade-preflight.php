<?php
/** Read-only prerequisites for upgrading an existing farm, before any updater runs. */
function farmUpgradePreflight(PDO $db, array $wikis): void {
    if (PHP_VERSION_ID < 80400) {
        throw new RuntimeException('Upgrade requires PHP 8.4 or newer');
    }
    $defines = file_get_contents('/var/www/mediawiki/includes/Defines.php');
    if (!preg_match("/define\( 'MW_VERSION', '([^']+)'/", $defines, $match) ||
        version_compare($match[1], '1.46.0', '<')) {
        throw new RuntimeException('Upgrade requires MediaWiki 1.46 or newer');
    }
    $columns = [
        'mw_namespaces' => ['ns_dbname', 'ns_namespace_id', 'ns_namespace_name', 'ns_content_model', 'ns_core', 'ns_additional'],
        'mw_permissions' => ['perm_dbname', 'perm_group', 'perm_addgroupstoself', 'perm_removegroupsfromself', 'perm_autopromote'],
        'cw_wikis' => ['wiki_dbname', 'wiki_extra', 'wiki_closed_timestamp', 'wiki_inactive_exempt_reason'],
    ];
    $query = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
    foreach ($columns as $table => $required) {
        $query->execute(['wikidb', $table]);
        $missing = array_diff($required, $query->fetchAll(PDO::FETCH_COLUMN));
        if ($missing) {
            throw new RuntimeException("Upgrade preflight: missing historical $table columns: " . implode(', ', $missing) . '. Apply the old-version updater before this upgrade.');
        }
    }
    $query = $db->prepare('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
    foreach (['mw_namespaces' => ['ns_dbname', 'uniquens'], 'mw_permissions' => ['perm_dbname', 'perm_group', 'uniqueperm']] as $table => $required) {
        $query->execute(['wikidb', $table]);
        if (array_diff($required, $query->fetchAll(PDO::FETCH_COLUMN))) {
            throw new RuntimeException("Upgrade preflight: missing historical $table indexes. Apply the old-version updater first.");
        }
    }
    // Inspect privilege scopes without exposing the account's grant text in logs.
    $grants = $db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_unique(array_merge(['wikidb', 'centralauth', 'metawiki', 'farmupgradeprobewiki'], $wikis)) as $wiki) {
        identifier($wiki);
        $allowed = false;
        foreach ($grants as $grant) {
            if (!preg_match('/^GRANT (.+) ON (\*|`(?:[^`]|``)+`)\.\* TO /', $grant, $parts)) { continue; }
            $scope = trim($parts[2], '`');
            $pattern = '';
            for ($i = 0; $i < strlen($scope); $i++) {
                $char = $scope[$i];
                if ($char === '\\' && $i + 1 < strlen($scope)) { $pattern .= preg_quote($scope[++$i], '/'); }
                elseif ($char === '%' || $scope === '*') { $pattern .= '.*'; }
                elseif ($char === '_') { $pattern .= '.'; }
                else { $pattern .= preg_quote($char, '/'); }
            }
            $privileges = explode(', ', $parts[1]);
            $required = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX'];
            if (preg_match('/^' . $pattern . '$/D', $wiki) &&
                (in_array('ALL PRIVILEGES', $privileges, true) || !array_diff($required, $privileges))) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) { throw new RuntimeException("Upgrade preflight: insufficient application schema/provisioning grants for $wiki"); }
    }
    foreach ($wikis as $wiki) {
        $db->query('SELECT 1 FROM ' . identifier($wiki) . '.page LIMIT 1');
    }
    echo "Read-only upgrade preflight passed: platform, historical schemas/indexes, database access and provisioning grants.\n";
}
