<?php
// Only the separate disposable upstream-test project should load this file.
if (PHP_SAPI !== 'cli' || getenv('MW_TEST_SUITE_ISOLATED') !== '1' ||
    getenv('MW_ENV') !== 'development' || getenv('MW_DB_HOST') !== 'database') {
    throw new RuntimeException('Upstream tests require an explicitly isolated development database');
}
global $wgDBserver, $wgDBuser, $wgDBpassword, $wgDBname;
$wgDBtype = 'mysql';
$wgDBserver = 'database';
$wgDBuser = 'root';
$wgDBpassword = trim(file_get_contents('/run/secrets/db-root-password'));
$wgDBname = 'testwiki';
$wgServer = 'https://test.localhost';
$wgScriptPath = '';
$wgLanguageCode = 'en';
$wgSitename = 'Upstream integration tests';
$wgMainCacheType = CACHE_NONE;
$wgLocalisationCacheConf = array_replace(array_map(static fn($property) => $property['default'],
    MediaWiki\MainConfigSchema::LocalisationCacheConf['properties']), ['store' => 'none']);
wfLoadSkin('Vector');
wfLoadExtensions(['CreateWiki', 'ManageWiki']);
$wgVirtualDomainsMapping = [
    'virtual-createwiki' => ['db' => 'testwiki'],
    'virtual-createwiki-central' => ['db' => 'testwiki'],
    'virtual-managewiki' => ['db' => 'testwiki'],
    'virtual-managewiki-central' => ['db' => 'testwiki'],
];
$wgCreateWikiDatabase = $wgCreateWikiGlobalWiki = 'testwiki';
$wgCreateWikiCacheDirectory = '/var/www/mediawiki/cache/upstream';
$wgManageWikiCacheDirectory = $wgCreateWikiCacheDirectory;
$wgCreateWikiUseJobQueue = false;
$wgHooks['SetupAfterCache'][] = static fn() => false;
if (!is_dir($wgCreateWikiCacheDirectory)) { mkdir($wgCreateWikiCacheDirectory, 0750, true); }
