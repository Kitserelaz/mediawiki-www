<?php
// Explicit CLI-only bootstrap; ordinary requests can never enter this configuration.
if (PHP_SAPI !== 'cli') { throw new RuntimeException('Bootstrap is CLI-only'); }
// PHPUnit loads settings inside a function; helper methods populate this global.
global $wgConf, $wgManageWikiExtensions, $wgManageWikiSettings, $wgManageWikiNamespacesAdditional, $farmSecrets;
require __DIR__ . '/private.php';
require __DIR__ . '/runtime.php';
$wgDBtype = 'mysql';
$wgDBserver = farmEnv('MW_DB_HOST', 'database');
$wgDBuser = farmEnv('MW_DB_USER', 'mediawiki');
$wgDBname = getenv('MW_BOOTSTRAP_DB');
$wgServer = 'https://' . preg_replace('/wiki$/', '', $wgDBname) . '.' . farmEnv('MW_DOMAIN', 'localhost');
$wgScriptPath = '';
$wgArticlePath = '/wiki/$1';
$wgLanguageCode = 'en';
$wgSitename = $wgDBname;
$wgEnableUploads = true;
if (getenv('MW_INSTALL_CORE')) { wfLoadSkin('Vector'); return; }
if (getenv('MW_BOOTSTRAP_AUX')) {
    wfLoadSkin('Vector');
    wfLoadExtensions(['AntiSpoof', 'GlobalPreferences']);
    $wgGlobalPreferencesDB = 'centralauth';
    $wgMainCacheType = CACHE_NONE;
    return;
}
$wgMainCacheType = CACHE_NONE;
$wgLocalDatabases = ['metawiki', 'loginwiki', 'testwiki'];
$wgVirtualDomainsMapping = [
    'virtual-createwiki' => ['db' => 'wikidb'],
    'virtual-createwiki-central' => ['db' => 'metawiki'],
    'virtual-managewiki' => ['db' => 'wikidb'],
    'virtual-managewiki-central' => ['db' => 'metawiki'],
    'virtual-centralauth' => ['db' => 'centralauth'],
    'virtual-botpasswords' => ['db' => 'centralauth'],
    'virtual-globalblocking' => ['db' => 'centralauth'],
    'virtual-oathauth' => ['db' => 'centralauth'],
    'virtual-importdump' => ['db' => 'metawiki'],
    'virtual-checkuser-global' => ['db' => 'wikidb'],
];
defined('MW_DB') || define('MW_DB', $wgDBname);
require "$IP/SkyWikiFunctions.php";
require "$IP/GlobalExtensions.php";
$wi = new MirahezeFunctions();
require "$IP/ConfigSettings.php";
farmSiteConfiguration();
// Runtime setup hooks read farm tables before an updater can create them.
// Disable them only for this explicit CLI bootstrap; schema-update hooks stay enabled.
$wgHooks['SetupAfterCache'][] = static fn() => false;
$wgHooks['CreateWikiGenerateDatabaseLists'][] = 'MirahezeFunctions::onGenerateDatabaseLists';
$wgCreateWikiCacheDirectory = '/var/www/mediawiki/cw_cache';
$wgManageWikiCacheDirectory = $wgCreateWikiCacheDirectory;
$wgCentralAuthDatabase = 'centralauth';
$wgGlobalPreferencesDB = 'centralauth';
$wgCreateWikiDatabaseClusters = [];
$wgCreateWikiUseJobQueue = false;
$wgManageWiki = ['cdb' => true, 'core' => true, 'extensions' => true, 'namespaces' => true, 'permissions' => true, 'settings' => true];
$wmgSharedUploadDBname = '';
$wmgUploadHostname = 'meta.' . farmEnv('MW_DOMAIN', 'localhost');
require "$IP/ManageWikiExtensions.php";
require "$IP/ManageWikiNamespaces.php";
require "$IP/ManageWikiSettings.php";
farmRuntime();

require "$IP/S3Settings.php";
