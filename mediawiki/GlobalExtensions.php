<?php
wfLoadSkins( [
        'Vector',
] );

$farmExtensions = [
    'ArchiveWikiImport',
    'AbuseFilter',
    'AntiSpoof',
    'BetaFeatures',
    'CentralAuth',
    'GlobalAccountClosure',
    'CentralNotice',
    'CheckUser',
    'CFCachePurge',
    'CreateWiki',
    'CookieWarning',
    'ConfirmEdit',
    'ConfirmEdit/hCaptcha',
    'DataDump',
    'DiscordNotifications',
    'DiscordAuth',
    'DismissableSiteNotice',
    'Echo',
    //'EventBus',
    'EventLogging',
    //'EventStreamConfig',
    'GlobalBlocking',
    'GlobalCssJs',
    'GlobalPreferences',
    'GlobalContributions',
    //'GlobalNewFiles',
    // 'GlobalUserPage',
    'ImportDump',
    'Interwiki',
    'InterwikiDispatcher',
    //'IPInfo',
    //'LoginNotify',
    'ManageWiki',
    'MatomoAnalytics',
    // 'MirahezeMagic',
    //'MobileDetect',
    //'MultiPurge',
    'NativeSvgHandler',
    'Nuke',
    'OATHAuth',
    'OAuth',
    'ParserFunctions',
    //'ParserMigration',
    'QuickInstantCommons',
    'RemovePII',
    // 'ReportIncident',
    //'RottenLinks',
    'Scribunto',
    // 'SecureLinkFixer',
    'SkyWikiMagic',
    'SpamBlacklist',
    // 'StopForumSpam',
    'TitleBlacklist',
    'TorBlock',
    //'WebAuthn',
    'WikiDiscover',
    'WikiEditor',
    'cldr',
];
if (getenv('MW_DOCKER')) {
    foreach (['MW_CACHE_PURGE' => 'CFCachePurge', 'MW_NOTIFICATIONS' => 'DiscordNotifications', 'MW_ANALYTICS' => 'MatomoAnalytics'] as $flag => $extension) {
        if (getenv($flag) !== '1') { $farmExtensions = array_values(array_diff($farmExtensions, [$extension])); }
    }
}
if (getenv('MW_DOCKER') && getenv('MW_SEARCH') === '1') { $farmExtensions = array_merge($farmExtensions, ['Elastica', 'CirrusSearch']); }
wfLoadExtensions($farmExtensions);

