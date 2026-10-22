<?php
// Prototype only: normal per-wiki configuration, with early setup hooks suppressed
// until the full updater has installed the initial schemas.
if (PHP_SAPI !== 'cli' || getenv('MW_PROVISION_SINGLE_PASS') !== '1') {
    throw new RuntimeException('Isolated CLI provisioning prototype only');
}
$wgHooks['SetupAfterCache'][] = static fn() => false;
require '/var/www/mediawiki/LocalSettings.php';
