<?php
require_once __DIR__ . '/provision-metadata.php';
require_once __DIR__ . '/wiki-identity.php';
function farmEnv(string $key, string $default): string {
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}
if (farmEnv('MW_ENV', 'development') === 'production' && str_ends_with(farmEnv('MW_DOMAIN', 'localhost'), 'localhost')) { throw new RuntimeException('Production requires a public MW_DOMAIN'); }
$domain = farmEnv('MW_DOMAIN', 'localhost');
$beta = farmEnv('MW_BETA_DOMAIN', 'beta.localhost');
defined('FARM_DOMAINS') || define('FARM_DOMAINS', ['default' => [$domain], 'beta' => [$beta]]);
defined('FARM_DEFAULT_SERVERS') || define('FARM_DEFAULT_SERVERS', ['default' => $domain, 'beta' => $beta]);
defined('FARM_SHARED_DOMAINS') || define('FARM_SHARED_DOMAINS', ['default' => "auth.$domain", 'beta' => "auth.$beta"]);

function farmSiteConfiguration(): void {
    global $wgConf;
    $domain = farmEnv('MW_DOMAIN', 'localhost');
    $settings = [
        'wgCreateWikiDatabase' => 'wikidb', 'wgCreateWikiGlobalWiki' => farmWikiId('metawiki'),
        'wgCreateWikiSubdomain' => $domain,
        'wgCreateWikiCacheDirectory' => (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/cw_cache'),
        'wgManageWikiCacheDirectory' => (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/cw_cache'),
        'wgManageWikiServers' => [],
        'wgCreateWikiDatabaseClusters' => [], 'wgCentralAuthDatabase' => 'centralauth',
        'wgCookieDomain' => '', 'wgCookieSecure' => true, 'wgForceHTTPS' => true,
        'wgJobRunRate' => 0,
        'wgCreateWikiSQLFiles' => array_values(array_filter([
            (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/sql/mysql/tables-generated.sql'),
            (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/extensions/AbuseFilter/db_patches/mysql/tables-generated.sql'),
            (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/extensions/AntiSpoof/sql/mysql/tables-generated.sql'),
            (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/extensions/BetaFeatures/sql/tables-generated.sql'),
            (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/extensions/CheckUser/schema/mysql/tables-generated.sql'),
            (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/extensions/DataDump/sql/data_dump.sql'),
            (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/extensions/Echo/sql/mysql/tables-generated.sql'),
            (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/extensions/GlobalBlocking/sql/mysql/tables-generated-global_block_whitelist.sql'),
            (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/extensions/Linter/sql/mysql/tables-generated.sql'),
            (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/extensions/OAuth/schema/mysql/tables-generated.sql'),
        ], 'is_file')),
    ];
    if (farmNumericIdsEnabled()) {
        foreach ($wgConf->settings as &$entries) {
            if (!is_array($entries)) {
                continue;
            }
            foreach (farmWikiRegistry()['aliases'] as $old => $new) {
                foreach (['', '+', '-'] as $prefix) {
                    if (array_key_exists($prefix . $old, $entries)) {
                        $entries[$prefix . $new] ??= $entries[$prefix . $old];
                    }
                }
            }
        }
        unset($entries);
        foreach (['wgAbuseFilterCentralDB', 'wgCreateWikiGlobalWiki', 'wgCentralAuthLoginWiki',
            'wgGlobalUserPageDBname', 'wgCentralAuthAutoCreateWikis', 'wgCentralAuthAutoLoginWikis',
            'wgCentralAuthOldNameAntiSpoofWiki', 'wmgCentralAuthAutoLoginWikis', 'wgCentralDBname',
            'wgMWOAuthCentralWiki', 'wgInterwikiCentralDB', 'wgVirtualDomainsMapping', 'wmgSharedUploadDBname'] as $setting) {
            if (isset($wgConf->settings[$setting])) {
                array_walk_recursive($wgConf->settings[$setting], static function (&$value): void {
                    if (is_string($value)) {
                        $value = farmWikiId($value);
                    }
                });
            }
        }
        $settings['wgCreateWikiAIThreshold'] = -1;
    }
    if (farmEnv('MW_ENV', 'development') !== 'production') {
        $settings['wgCentralAuthLoginWiki'] = farmWikiId('loginwiki');
        $settings['wgCentralAuthAutoCreateWikis'] = [farmWikiId('metawiki'), farmWikiId('loginwiki')];
        $settings['wgCentralAuthCookieDomain'] = '';
    }
    foreach ($settings as $key => $value) { $wgConf->settings[$key] = ['default' => $value]; }
    $servers = ['default' => 'https://meta.' . $domain];
    if (class_exists('MirahezeFunctions')) {
        foreach (MirahezeFunctions::readDbListFile('databases', false) as $wiki => $metadata) {
            $servers[$wiki] = $metadata['u'] ?? farmWikiBuiltinUrl($wiki) ?? 'https://' . preg_replace('/wiki$/', '', $wiki) . '.' . $domain;
        }
        $customRoute = MirahezeFunctions::getCustomDomainRoute();
        if ($customRoute) {
            $servers[farmWikiId($customRoute[0])] = 'https://' . $customRoute[1];
        }
    }
    $wgConf->settings['wgServer'] = $servers;
    // Share the populated farm configuration for ManageWiki remote lookups.
    require_once (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/FarmSiteConfiguration.php');
    $GLOBALS['wgManageWikiSiteConfiguration'] = new FarmManageWikiSiteConfiguration($wgConf);
    if (farmEnv('MW_LEGACY_BRANDING', '1') === '0') {
        $wgConf->settings['+wgFooterIcons']['default'] = [];
        $wgConf->settings['wgLogo']['default'] = '/resources/assets/change-your-logo.svg';
        $wgConf->settings['wgFavicon']['default'] = '/resources/assets/mediawiki.png';
    }

}

function farmRuntime(): void {
    global $wgDBserver, $wgDBuser, $wgDBpassword, $wgMemCachedServers, $wgObjectCaches,
        $wgJobTypeConf, $wgSMTP, $farmSecrets, $wgServer, $wgCanonicalServer,
        $wgUploadDirectory, $wgUploadPath, $wgDBname, $wgScriptPath, $wgResourceBasePath,
        $wgLocalisationCacheConf, $wgDebugLogFile, $wgDebugLogGroups, $wgDataDumpDirectory,
        $wgPingback, $wgCirrusSearchServers, $wgSearchType, $wgCaptchaTriggers,
        $wgDiscordIncomingWebhookUrl, $wgCFCachePurgeApiToken, $wgMatomoAnalyticsTokenAuth,
        $wgVirtualDomainsMapping, $wgCreateWikiCacheDirectory, $wgCreateWikiDatabase,
        $wgCreateWikiGlobalWiki, $wgOAuth2PrivateKey, $wgOAuth2PublicKey,
        $wgCentralAuthCookieDomain, $wgCookieDomain, $wgCentralAuthEnableSul3;
    $GLOBALS['wgPhpCli'] = getenv('MW_PHP_CLI') ?: '/usr/local/bin/php';
    if (getenv('MW_FARM_READ_ONLY') === '1' && PHP_SAPI !== 'cli') {
        $GLOBALS['wgReadOnly'] = 'This wiki is temporarily read-only for a farm database migration.';
    }
    $wgDBserver = farmEnv('MW_DB_HOST', 'database') . (getenv('MW_DB_PORT') ? ':' . getenv('MW_DB_PORT') : '');
    $wgDBuser = farmEnv('MW_DB_USER', 'mediawiki');
    $wgDBpassword = $farmSecrets['db_password'];
    $wgMemCachedServers = [farmEnv('MW_MEMCACHED', 'memcached:11211')];
    $GLOBALS['wgMainCacheType'] = getenv('MW_BOOTSTRAP_DB') ? CACHE_NONE : CACHE_MEMCACHED;
    $redis = farmEnv('MW_REDIS', 'redis:6379');
    $wgObjectCaches['redis'] = ['class' => 'RedisBagOStuff', 'servers' => [$redis]];
    $GLOBALS['wgSessionCacheType'] = 'redis';
    $GLOBALS['wgCentralAuthSessionCacheType'] = 'redis';
    $wgJobTypeConf['default'] = ['class' => 'JobQueueRedis', 'redisServer' => $redis, 'redisConfig' => [], 'daemonized' => true, 'streamDispatch' => true];
    $GLOBALS['wgEnableEmail'] = farmEnv('MW_EMAIL_ENABLED', '1') === '1';
    $wgSMTP = ['host' => farmEnv('MW_SMTP_HOST', 'mailpit'), 'port' => (int)farmEnv('MW_SMTP_PORT', '1025'),
        'IDHost' => farmEnv('MW_DOMAIN', 'localhost'), 'auth' => !empty($farmSecrets['smtp_user']),
        'username' => $farmSecrets['smtp_user'] ?? '', 'password' => $farmSecrets['smtp_password'] ?? ''];
    if (farmEnv('MW_CAPTCHA', '0') !== '1') {
        $wgCaptchaTriggers = array_fill_keys(['edit', 'create', 'addurl', 'createaccount', 'badlogin', 'badloginperuser'], false);
    }
    if (farmEnv('MW_NOTIFICATIONS', '0') !== '1') { $wgDiscordIncomingWebhookUrl = ''; }
    if (farmEnv('MW_CACHE_PURGE', '0') !== '1') {
        $wgCFCachePurgeApiToken = '';
    } else {
        // Prefer cloudflare_token from the farm secrets; otherwise use the zone-scoped
        // Cloudflare SaaS credential that also serves custom hostnames. That token must
        // carry Cache Purge in addition to SSL and Certificates Write.
        if ( $wgCFCachePurgeApiToken === '' ) {
            $saasTokenFile = farmEnv('MW_CLOUDFLARE_SAAS_TOKEN_FILE', (rtrim(getenv('MW_SECRETS_DIRECTORY') ?: '/run/secrets', '/') . '/cloudflare-saas-token'));
            if ( is_readable($saasTokenFile) ) {
                $saasToken = trim( (string)file_get_contents($saasTokenFile) );
                if ( $saasToken !== '' && !preg_match('/\s/', $saasToken) ) {
                    $wgCFCachePurgeApiToken = $saasToken;
                }
            }
        }
        if ( ( $GLOBALS['wgCFCachePurgeZoneId'] ?? '' ) === '' ) {
            $saasZone = farmEnv('MW_CLOUDFLARE_SAAS_ZONE_ID', '');
            if ( preg_match('/^[a-f0-9]{32}$/D', $saasZone) ) {
                $GLOBALS['wgCFCachePurgeZoneId'] = $saasZone;
            }
        }
    }
    if (farmEnv('MW_ANALYTICS', '0') !== '1') { $wgMatomoAnalyticsTokenAuth = ''; }
    $wgPingback = false;
    static $numericHooksRegistered = false;
    if (farmNumericIdsEnabled() && !$numericHooksRegistered) {
        $numericHooksRegistered = true;
        $GLOBALS['wgHooks']['SpecialPage_initList'][] = static function (array &$pages): void {
            unset($pages['CreateWiki']);
        };
        $GLOBALS['wgHooks']['RequestWikiQueueFormDescriptorModify'][] = static function (
            array &$form, $user, $request
        ): void {
            if (!isset($form['handle-action'])) {
                return;
            }
            $identity = farmWikiIdentity($request->getDBname());
            if (($identity['request'] ?? null) !== $request->getId()) {
                unset($form['handle-action']['options-messages']['requestwikiqueue-approve']);
                $form['handle-action']['validation-callback'] = static function ($value) {
                    return $value === 'approve' ? 'Reserve the numeric ID before approving this request.' : true;
                };
            }
        };
    }
    $wgCreateWikiDatabase = 'wikidb';
    $wgCreateWikiGlobalWiki = farmWikiId('metawiki');
    $GLOBALS['wgHooks']['CreateWikiGenerateDatabaseLists'] = ['MirahezeFunctions::onGenerateDatabaseLists'];
    $GLOBALS['wgExtensionFunctions'][] = static function (): void {
        // This hook follows all creation handlers, including ManageWiki defaults.
        MediaWiki\MediaWikiServices::getInstance()->getHookContainer()->register(
            'CreateWikiCreationTasks',
            static function (string $wiki, string $requester, array $extra): bool {
                if (!preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/D', $wiki)) {
                    throw new RuntimeException('Invalid new wiki identifier');
                }
                $services = MediaWiki\MediaWikiServices::getInstance();
                $db = $services->getConnectionProvider()->getPrimaryDatabase('virtual-createwiki');
                // Never publish before SQL registration and defaults are committed.
                $db->onTransactionCommitOrIdle(static function () use ($services, $wiki, $requester, $extra): void {
                    farmPublishPreparedWikiMetadata($services, $wiki, ['requester'=>$requester, 'extra'=>$extra]);
                }, 'farmCreateWikiDispatch');
                return false;
            }
        );
    };
    $wgCreateWikiCacheDirectory = (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/cw_cache');
    $GLOBALS['wgManageWikiCacheDirectory'] = $wgCreateWikiCacheDirectory;
    $GLOBALS['wgManageWikiServers'] = [];
    if (isset($GLOBALS['wgConf'])) {
        require_once (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/FarmSiteConfiguration.php');
        $GLOBALS['wgManageWikiSiteConfiguration'] = new FarmManageWikiSiteConfiguration($GLOBALS['wgConf']);
    }
    $wgVirtualDomainsMapping['virtual-createwiki'] = ['db' => 'wikidb'];
    $wgVirtualDomainsMapping['virtual-createwiki-central'] = ['db' => farmWikiId('metawiki')];
    $wgVirtualDomainsMapping['virtual-managewiki'] = ['db' => 'wikidb'];
    $wgVirtualDomainsMapping['virtual-managewiki-central'] = ['db' => farmWikiId('metawiki')];
    $wgVirtualDomainsMapping['virtual-centralauth'] = ['db' => 'centralauth'];
    $wgVirtualDomainsMapping['virtual-centralnotice'] ??= ['db' => farmWikiId('metawiki')];
    $wgVirtualDomainsMapping['virtual-botpasswords'] = ['db' => 'centralauth'];
    $wgVirtualDomainsMapping['virtual-globalblocking'] = ['db' => 'centralauth'];
    $wgVirtualDomainsMapping['virtual-oathauth'] = ['db' => 'centralauth'];
    $wgVirtualDomainsMapping['virtual-importdump'] = ['db' => farmWikiId('metawiki')];
    $wgVirtualDomainsMapping['virtual-checkuser-global'] = ['db' => 'wikidb'];
    if (farmNumericIdsEnabled()) {
        foreach ($wgVirtualDomainsMapping as &$mapping) {
            if (isset($mapping['db']) && is_string($mapping['db'])) {
                $mapping['db'] = farmWikiId($mapping['db']);
            }
        }
        unset($mapping);
    }
    $wgOAuth2PrivateKey = (rtrim(getenv('MW_SECRETS_DIRECTORY') ?: '/run/secrets', '/') . '/oauth-private.key');
    $wgOAuth2PublicKey = (rtrim(getenv('MW_SECRETS_DIRECTORY') ?: '/run/secrets', '/') . '/oauth-public.key');
    $wgLocalisationCacheConf = array_replace($wgLocalisationCacheConf ?? [], [
        'class' => MediaWiki\Language\LocalisationCache::class,
        'store' => 'files', 'storeClass' => false,
        'storeDirectory' => (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/cache/') . ($wgDBname ?? 'bootstrap'),
    ]);
    $wgDebugLogFile = '';
    $wgDebugLogGroups = [];
    $wgDataDumpDirectory = farmEnv('MW_DUMP_DIRECTORY', '/var/www/dumps');
    $GLOBALS['wgArchiveWikiImportDirectory'] = rtrim($wgDataDumpDirectory, '/') . '/archive-wiki-import';
    if (isset($wgDBname)) {
        $wgUploadDirectory = rtrim(farmEnv('MW_UPLOAD_ROOT', (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/images')), '/') . '/' . farmWikiStorageKey($wgDBname);
        if (isset($GLOBALS['wgDataDump']['zip']['generate']['options'])) {
            $GLOBALS['wgDataDump']['zip']['generate']['options'][1] = rtrim($wgDataDumpDirectory, '/') . '/${filename}';
            $GLOBALS['wgDataDump']['zip']['generate']['options'][2] = $wgUploadDirectory;
        }
        $wgUploadPath = '/img_auth.php';
        if (!empty($GLOBALS['cwPrivate'])) { $wgUploadPath = '/img_auth.php'; }
    }
    if (farmEnv('MW_SEARCH', '0') === '1') { $wgCirrusSearchServers = ['search']; $wgSearchType = 'CirrusSearch'; }
    else { $wgSearchType = 'SearchMySQL'; }
    $domain = farmEnv('MW_DOMAIN', 'localhost');
    $GLOBALS['wmgUploadHostname'] = "meta.$domain";
    $GLOBALS['wgDiscordAuthCallbackUrl'] = "https://auth.$domain/metawiki/wiki/Special:DiscordCallback";
    $discordRestrictions = &$GLOBALS['wgCentralAuthSul3SharedDomainRestrictions'];
    $discordRestrictions['allowedSpecialPages'] = array_values(array_unique(array_merge(
        $discordRestrictions['allowedSpecialPages'] ?? [], ['DiscordCallback', 'DiscordLogin', 'DiscordSignup']
    )));
    $discordRestrictions['centralSpecialPagesAuthenticated'] = array_values(array_unique(array_merge(
        $discordRestrictions['centralSpecialPagesAuthenticated'] ?? [], ['LinkAccounts']
    )));
    $discordRestrictions['centralSpecialPagesUnauthenticated'] = array_values(array_unique(array_merge(
        $discordRestrictions['centralSpecialPagesUnauthenticated'] ?? [], ['DiscordLogin', 'DiscordSignup']
    )));
    unset($discordRestrictions);
    $GLOBALS['wgGlobalUserPageAPIUrl'] = "https://meta.$domain/api.php";
    $GLOBALS['wgCentralHost'] = "https://meta.$domain";
    $GLOBALS['wgCentralSelectedBannerDispatcher'] = "https://meta.$domain/wiki/Special:BannerLoader";
    $GLOBALS['wgCentralBannerRecorder'] = "https://meta.$domain/wiki/Special:RecordImpression";
    $wgCentralAuthEnableSul3 = true;
    $wgCookieDomain = $wgCentralAuthCookieDomain = '';
    $GLOBALS['wgCentralAuthLoginWiki'] = false;
    $GLOBALS['wgCentralAuthAutoLoginWikis'] = [
        "meta.$domain" => farmWikiId('metawiki'), "test.$domain" => farmWikiId('testwiki'), "login.$domain" => farmWikiId('loginwiki'),
    ];
    if (farmEnv('MW_LEGACY_BRANDING', '1') === '0') {
        $GLOBALS['wgFooterIcons'] = [];
        $GLOBALS['wgRightsIcon'] = '';
        $GLOBALS['wgLogos'] = ['1x' => '/resources/assets/change-your-logo.svg', 'icon' => '/resources/assets/mediawiki_compact.svg'];
        $GLOBALS['wgLogo'] = '/resources/assets/change-your-logo.svg';
        $GLOBALS['wgFavicon'] = '/resources/assets/mediawiki.png';
    }
    if (farmEnv('MW_ENV', 'development') === 'production') {
        $wgCanonicalServer = (($_SERVER['HTTP_HOST'] ?? '') === 'auth.' . farmEnv('MW_DOMAIN', 'localhost'))
            ? 'https://auth.' . farmEnv('MW_DOMAIN', 'localhost') : $wgServer;
    }
    if (farmEnv('MW_ENV', 'development') !== 'production') {
        $GLOBALS['wgOATHRequiredForGroups'] = [];
        // Local login/OAuth iteration must not lock out the developer after failed fixtures.
        $GLOBALS['wgPasswordAttemptThrottle'] = [];
        $domain = farmEnv('MW_DOMAIN', 'localhost');
        // Retain deliberately configured local custom domains while translating
        // restored production hosts into the isolated development farm.
        $configuredHost = parse_url($wgServer, PHP_URL_HOST);
        $verifiedCustomHost = getenv('MW_CUSTOM_DOMAINS_ROUTING') === '1' &&
            $configuredHost && $configuredHost === ($_SERVER['CLOUDWIKI_CUSTOM_HOST'] ?? null);
        if (!$verifiedCustomHost && (!$configuredHost || ($configuredHost !== $domain && !str_ends_with($configuredHost, ".$domain")))) {
            $wgServer = 'https://' . preg_replace('/wiki$/', '', $wgDBname ?? farmWikiId('metawiki')) . ".$domain";
        }
        $wgCanonicalServer = (($_SERVER['HTTP_HOST'] ?? '') === "auth.$domain") ? "https://auth.$domain" : $wgServer;
        $wgCookieDomain = $wgCentralAuthCookieDomain = '';
        // CentralAuth uses redirects for host-only local cookies.
        $wgCentralAuthEnableSul3 = true;
        $GLOBALS['wgCentralAuthLoginWiki'] = false;
        $GLOBALS['wgCentralAuthAutoLoginWikis'] = [
            "meta.$domain" => farmWikiId('metawiki'), "test.$domain" => farmWikiId('testwiki'), "login.$domain" => farmWikiId('loginwiki'),
        ];
        if (farmEnv('MW_REMOTE_REPOSITORIES', '0') !== '1') {
            $GLOBALS['wgUseQuickInstantCommons'] = false;
            $GLOBALS['wgQuickInstantCommonsPrefetch'] = false;
            $GLOBALS['wgUseInstantCommons'] = false;
            $GLOBALS['wgForeignFileRepos'] = [];
        }
        $GLOBALS['wgGlobalUserPageAPIUrl'] = "https://meta.$domain/api.php";
        foreach (['generate-dump', 'view-dump', 'delete-dump'] as $right) {
            $GLOBALS['wgGroupPermissions']['sysop'][$right] = true;
        }
        $GLOBALS['wgCentralHost'] = "https://meta.$domain";
        $GLOBALS['wgCentralSelectedBannerDispatcher'] = "https://meta.$domain/wiki/Special:BannerLoader";
        $GLOBALS['wgCentralBannerRecorder'] = "https://meta.$domain/wiki/Special:RecordImpression";
        $GLOBALS['wgFooterIcons'] = [];
        $GLOBALS['wgLogos'] = ['1x' => '/resources/assets/change-your-logo.svg', 'icon' => '/resources/assets/mediawiki_compact.svg'];
        $GLOBALS['wgLogo'] = '/resources/assets/change-your-logo.svg';
        $GLOBALS['wgFavicon'] = '/resources/assets/mediawiki.png';
        $GLOBALS['wgRightsIcon'] = '';
        $GLOBALS['wgEventLoggingBaseUri'] = false;
        $GLOBALS['wgEventLoggingServiceUri'] = false;
        if (getenv('MW_AUTH_DEBUG') === '1') { $wgDebugLogGroups['authentication'] = (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/cache/authentication-debug.log'); $wgDebugLogGroups['session'] = (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/cache/session-debug.log'); }
        if (getenv('MW_EXCEPTION_DEBUG') === '1') { $wgDebugLogGroups['exception'] = (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/cache/exception-debug.log'); }
    }
}
