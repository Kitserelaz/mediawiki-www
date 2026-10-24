<?php
// Explicit settings entry point for a fresh wiki's schema updater only.
if (PHP_SAPI !== 'cli' || getenv('MW_BOOTSTRAP_DB')) {
    throw new RuntimeException('Provisioning requires CLI and normal per-wiki settings');
}
// Early setup hooks can query extension tables before the updater creates them.
// Schema-update hooks remain enabled; ordinary requests use LocalSettings directly.
$wgHooks['SetupAfterCache'][] = static fn() => false;
require '/var/www/mediawiki/LocalSettings.php';
require_once __DIR__ . '/dispatch.php';
require_once __DIR__ . '/finalize-creation.php';
$wgHooks['LoadExtensionSchemaUpdates'][] = static function (MediaWiki\Installer\DatabaseUpdater $updater): void {
    global $wgDBname;
    if (FarmDispatch::connect()->hExists(FarmDispatch::CREATION_CONTEXT, $wgDBname)) {
        $updater->addPostDatabaseUpdateMaintenance(FarmFinalizeCreation::class);
    }
};
