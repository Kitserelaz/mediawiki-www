<?php
// Shared by the already-initialized creator and the fresh-process repair helper.
function farmWikiMetadataIsComplete(string $wiki, string $directory = '/var/www/mediawiki/cw_cache'): bool {
    $data = @(static fn($path) => include $path)("$directory/$wiki.php");
    return is_array($data) && ($data['database'] ?? null) === $wiki
        && isset($data['core']['wgLanguageCode'], $data['core']['wgSitename'], $data['states']['private'])
        && is_array($data['extensions'] ?? null)
        && is_array($data['settings'] ?? null)
        && is_array($data['permissions'] ?? null);
}

function farmProvisionMetadataListEntries(string $wiki, bool $private, string $directory): ?array {
    $lists = [];
    foreach (['databases', 'deleted', 'active', 'private', 'public', 'alpha-wikis', 'beta-wikis', 'stable-wikis'] as $name) {
        $list = @(static fn($path) => include $path)("$directory/$name.php");
        if (!is_array($list['databases'] ?? null)) {
            return null;
        }
        // Other creations and timestamp-only rewrites do not invalidate this wiki.
        $lists[$name] = $list['databases'][$wiki] ?? null;
    }

    $expectedPrivacyList = $private ? 'private' : 'public';
    $oppositePrivacyList = $private ? 'public' : 'private';
    if (!is_array($lists['databases']) || $lists['deleted'] !== null ||
        !is_array($lists[$expectedPrivacyList]) || $lists[$oppositePrivacyList] !== null) {
        return null;
    }
    return $lists;
}

function farmProvisionMetadataSourceHashes(): ?array {
    $source = [
        'preparer' => hash_file('sha256', __FILE__),
        'runtime' => hash_file('sha256', __DIR__ . '/runtime.php'),
    ];
    $configurationFiles = [
        'LocalSettings.php', 'GlobalExtensions.php', 'SkyWikiFunctions.php', 'ConfigSettings.php',
        'ManageWikiSettings.php', 'ManageWikiExtensions.php', 'ManageWikiNamespaces.php',
    ];
    foreach ($configurationFiles as $file) {
        $source[$file] = hash_file('sha256', '/var/www/mediawiki/' . $file);
        if ($source[$file] === false) {
            return null;
        }
    }
    return $source;
}

function farmProvisionMetadataFingerprint(string $wiki, string $directory = '/var/www/mediawiki/cw_cache'): ?string {
    if (!preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/D', $wiki)) {
        return null;
    }
    try {
        if (!farmWikiMetadataIsComplete($wiki, $directory)) {
            return null;
        }
        $data = (static fn($path) => include $path)("$directory/$wiki.php");
        unset($data['mtime']);
        $lists = farmProvisionMetadataListEntries($wiki, (bool)$data['states']['private'], $directory);
        if ($lists === null) {
            return null;
        }
        $source = farmProvisionMetadataSourceHashes();
        if ($source === null) {
            return null;
        }
        return hash('sha256', serialize([$data, $lists, $source]));
    } catch (Throwable $error) {
        return null;
    }
}

function farmRecordPreparedWikiMetadata(string $wiki, string $directory = '/var/www/mediawiki/cw_cache'): void {
    $fingerprint = farmProvisionMetadataFingerprint($wiki, $directory);
    if ($fingerprint === null) {
        throw new RuntimeException('Incomplete provisioning metadata for ' . $wiki);
    }
    $temporary = tempnam($directory, '.prepared-');
    if ($temporary === false) {
        throw new RuntimeException('Could not create metadata receipt');
    }
    try {
        $receipt = json_encode(['wiki' => $wiki, 'fingerprint' => $fingerprint], JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $receipt) === false ||
            !rename($temporary, "$directory/.provision-$wiki.metadata.json")) {
            throw new RuntimeException('Could not publish metadata receipt');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function farmPreparedWikiMetadataIsCurrent(string $wiki, string $directory = '/var/www/mediawiki/cw_cache'): bool {
    if (!preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/D', $wiki)) {
        return false;
    }
    $receipt = json_decode(@file_get_contents("$directory/.provision-$wiki.metadata.json") ?: '', true);
    if (!is_array($receipt) || ($receipt['wiki'] ?? null) !== $wiki || !is_string($receipt['fingerprint'] ?? null)) {
        return false;
    }
    $fingerprint = farmProvisionMetadataFingerprint($wiki, $directory);
    return $fingerprint !== null && hash_equals($receipt['fingerprint'], $fingerprint);
}

function farmPrepareWikiMetadata(MediaWiki\MediaWikiServices $services, string $wiki): void {
    if (!preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/D', $wiki)) {
        throw new RuntimeException('Invalid provisioning target');
    }
    $services->get('CreateWikiDataStore')->resetDatabaseLists(true);
    $services->get('ManageWikiDataStoreFactory')->newInstance($wiki)->resetWikiData(true);
    if (!farmWikiMetadataIsComplete($wiki)) {
        throw new RuntimeException('Incomplete provisioning metadata for ' . $wiki);
    }
    @unlink('/var/www/mediawiki/cw_cache/config-' . $wiki . '.php');
    farmRecordPreparedWikiMetadata($wiki);
}

function farmPublishPreparedWikiMetadata(MediaWiki\MediaWikiServices $services, string $wiki, ?array $creation = null): void {
    require_once __DIR__ . '/dispatch.php';
    $lock = @fopen('/var/www/mediawiki/cw_cache/.provision-' . $wiki . '.metadata.lock', 'ce');
    $locked = is_resource($lock) && flock($lock, LOCK_EX);
    try {
        // Publish first. If this process dies during preparation, its lock is
        // released by the OS and the durable delivery takes the repair path.
        FarmDispatch::provision(FarmDispatch::connect(), $wiki, $creation);
        if ($locked) {
            try {
                farmPrepareWikiMetadata($services, $wiki);
            } catch (Throwable $error) {
                error_log('Creation metadata will be repaired by the provisioner: ' . $wiki);
            }
        }
    } finally {
        if (is_resource($lock)) {
            if ($locked) {
                flock($lock, LOCK_UN);
            }
            fclose($lock);
        }
    }
}
