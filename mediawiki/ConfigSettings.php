<?php
$wgConf->settings += [
	'wgSVGConverter' => [
		'default' => 'ImageMagick',
	],
	'wgServer' => [
		'default' => "https://" . trim($wgDBname, "wiki") . ".cloudwiki.us",
		'customdomainwiki' => "https://wiki.skywikibeta.site",
		'trendpediawiki' => "https://trendspedia.org",
	],/*
	'wgCanonicalServer' => [
		'default' => "https://" . $_SERVER['HTTP_HOST']
	],*/
	'wgCookieDomain' => [
		'default' => '.cloudwiki.us'
	],
	'wgForceHTTPS' => [
		'default' => true
	],
	'wgCookieSecure' => [
		'default' => true
	],
	'wgCentralAuthEnableSul3' => [
		'default' => false
	],
	'wgCentralAuthRestrictSharedDomain' => [
		'default' => true,
	],
	'wgSharedDB' => [
		'default' => null,
	],
	'wgSharedTables' => [
		'default' => [],
	],
	// AbuseFilter
	'wgAbuseFilterCentralDB' => [
		'default' => 'metawiki',
		# 'mirabeta' => 'metawikibeta',
	],
	'wgAbuseFilterIsCentral' => [
		'default' => false,
		'metawiki' => true,
		# 'metawikibeta' => true,
	],

	'wgLocalisationCacheConf' => [
		'default' => [
			'class' => LocalisationCache::class,
			'store' => 'files',
			'storeDirectory' => "/var/www/mediawiki/cache/$wgDBname",
		],
	],

	// CentralAuth
	'wgCentralAuthAutoCreateWikis' => [
		'default' => [
			'loginwiki',
			'metawiki',
		],
		'beta' => [
			'loginwikibeta',
			'metawikibeta',
		],
	],
	'wgCentralAuthAutoMigrate' => [
		'default' => true,
	],
	'wgCentralAuthAutoMigrateNonGlobalAccounts' => [
		'default' => true,
	],
	'wgCentralAuthCookies' => [
		'default' => true,
	],
	'wgCentralAuthCookiePrefix' => [
		'default' => 'centralauth_',
		'beta' => 'betacentralauth_',
	],
	'wgCentralAuthDatabase' => [
		'default' => 'centralauth',
		'beta' => 'testglobal',
	],
	'wgCentralAuthEnableGlobalRenameRequest' => [
		'default' => true,
	],
	'wgCentralAuthGlobalBlockInterwikiPrefix' => [
		'default' => 'meta',
	],
	'wgCentralAuthLoginWiki' => [
		'default' => 'loginwiki',
		'beta' => 'loginwikibeta',
	],
	'wgCentralAuthOldNameAntiSpoofWiki' => [
		'default' => 'metawiki',
		'beta' => 'metawikibeta',
	],
	'wgCentralAuthPreventUnattached' => [
		'default' => true,
	],
	'wgCentralAuthTokenCacheType' => [
		'default' => CACHE_MEMCACHED,
	],
	'wmgCentralAuthAutoLoginWikis' => [
		'default' => [
			'.cloudwiki.us' => 'metawiki'
		],
	],

	// CheckUser
	'wgCheckUserForceSummary' => [
		'default' => true,
	],

	'wgCookieWarningEnabled' => [
		'default' => true,
	],
	// CentralNotice
	'wgNoticeInfrastructure' => [
		'default' => false,
		'metawiki' => true,
	],
	'wgCentralSelectedBannerDispatcher' => [
		'default' => 'https://meta.cloudwiki.us/wiki/Special:BannerLoader',
	],
	'wgCentralBannerRecorder' => [
		'default' => 'https://meta.cloudwiki.us/wiki/Special:RecordImpression',
	],
	'wgCentralDBname' => [
		'default' => 'metawiki',
		'beta' => 'metawikibeta',
	],
	'wgCentralHost' => [
		'default' => 'https://meta.cloudwiki.us',
	],
	'wgNoticeProjects' => [
		'default' => [
			'all',
			'optout',
		],
	],
	'wgNoticeUseTranslateExtension' => [
		'default' => false,
	],

	'wgContribScoreDisableCache' => [
		'default' => true,
	],
        
        // LoginNotify
	'wgLoginNotifyAttemptsNewIP' => [
		'default' => 3,
	],
	'wgLoginNotifySeenBucketSize' => [
		'default' => 8 * 86400,
	],
	'wgLoginNotifySeenExpiry' => [
		'default' => 80 * 86400,
	],
	'wgLoginNotifyUseSeenTable' => [
		'default' => true,
	],

        // GlobalUserPage
	'wgGlobalUserPageAPIUrl' => [
		'default' => 'https://meta.cloudwiki.us/api.php'
	],
	'wgGlobalUserPageDBname' => [
		'default' => 'metawiki'
        ],

	// RequestSSL
	'wgRequestSSLScriptCommand' => [
		'default' => 'sudo /root/ssl-certificate -d {customdomain} -g -p',
	],
	'wgRequestSSLUsersNotifiedOnAllRequests' => [
		'default' => [
			'Cocopuff2018',
		],
	],

	// Site notice opt out
	'wmgSiteNoticeOptOut' => [
		'default' => false,
	],
	// DismissableSiteNotice
	'wgDismissableSiteNoticeForAnons' => [
		'default' => true,
	],

	// Cookies
	'wgCookieExpiration' => [
		'default' => 30 * 86400,
	],
	'wgCookieSameSite' => [
		'default' => 'None',
	],
	'wgCookieSetOnAutoblock' => [
		'default' => true,
	],
	'wgCookieSetOnIpBlock' => [
		'default' => true,
	],
	'wgExtendedLoginCookieExpiration' => [
		'default' => 365 * 86400,
	],

	// CreateWiki
	'wgRequestWikiConfirmEmail' => [
		'default' => false,
	],
	'wgCreateWikiDatabase' => [
		'default' => 'wikidb',
	],
	'wgCreateWikiUseJobQueue' => [
		'default' => true,
	],
	'wgCreateWikiCategories' => [
		'default' => [
			'Art & Architecture' => 'artarc',
			'Automotive' => 'automotive',
			'Business & Finance' => 'businessfinance',
			'Community' => 'community',
			'Education' => 'education',
			'Electronics' => 'electronics',
			'Entertainment' => 'entertainment',
			'Fandom' => 'fandom',
			'Fantasy' => 'fantasy',
			'Gaming' => 'gaming',
			'Geography' => 'geography',
			'History' => 'history',
			'Humour/Satire' => 'humour',
			'Language/Linguistics' => 'langling',
			'Leisure' => 'leisure',
			'Literature/Writing' => 'literature',
			'Media/Journalism' => 'media',
			'Medicine/Medical' => 'medical',
			'Military/War' => 'military',
			'Music' => 'music',
			'Podcast' => 'podcast',
			'Politics' => 'politics',
			'Private' => 'private',
			'Religion' => 'religion',
			'Science' => 'science',
			'Software/Computing' => 'software',
			'Sports' => 'sport',
			'Uncategorised' => 'uncategorised',
		],
	],
	'wgCreateWikiUseCategories' => [
		'default' => true,
	],
	'wgCreateWikiSubdomain' => [
		'default' => 'cloudwiki.us',
	],
	'wgCreateWikiUseClosedWikis' => [
		'default' => true,
	],
	'wgCreateWikiUseCustomDomains' => [
		'default' => true,
	],
	'wgCreateWikiUseEchoNotifications' => [
		'default' => true,
	],
	'wgCreateWikiUseExperimental' => [
		'default' => true,
	],
	'wgCreateWikiUseInactiveWikis' => [
		'default' => true,
	],
	'wgCreateWikiUsePrivateWikis' => [
		'default' => true,
	],
	'wgCreateWikiCacheDirectory' => [
		'default' => '/var/www/mediawiki/cw_cache',
	],
	'wgCreateWikiDatabaseSuffix' => [
		'default' => 'wiki',
	],
	'wgCreateWikiDisableRESTAPI' => [
		'default' => true,
		'metawiki' => false,
	],
	'wgCreateWikiGlobalWiki' => [
		'default' => 'metawiki',
	],
	'wgCreateWikiEmailNotifications' => [
		'default' => true,
	],
	'wgCreateWikiInactiveExemptReasonOptions' => [
		'default' => [
			'Wiki completed and made to be read' => 'comp',
			'Wiki made for time-based gathering' => 'tbg',
			'Wiki made to be read' => 'mtr',
			'Temporary exemption for exceptional hardship, see DPE' => 'temphardship',
			'Other, see DPE' => 'other',
		],
	],
	'wgCreateWikiCannedResponses' => [
		'default' => [
			'Approval reasons' => [
				'Approved' => 'Please make sure that you follow the Content Policy at all times and not deviate from the mentioned scope or your wiki may be closed. Thank you for choosing SkyWiki.',
			],
		],
	],
	'wgCreateWikiDisallowedSubdomains' => [
		'default' => [
                        '(.*)skywiki(.*)',
			'subdomain',
			'example',
			'beta(meta)?',
			'prueba',
			'community',
			'testwiki',
			'wikitest',
			'help',
			'noc',
			'wc',
			'dc',
			'm',
			'sandbox',
			'outreach',
			'gazett?eer',
			'semantic(mediawiki)?',
			'accounts(internal)?',
			'(internal)?tech(internal)?',
			'sre',
			'smw',
			'wikitech',
			'wikis?',
			'www',
			'security',
			'donate',
			'blog',
			'health',
			'status',
			'acme',
			'ssl',
			'sslhost',
			'sslrequest',
			'letsencrypt',
			'deployment',
			'hostmaster',
			'wildcard',
			'list',
			'localhost',
			'mailman',
			'webmail',
			'phabricator',
			'static',
			'upload',
			'grafana',
			'icinga',
			'logging',
			'monitoring',
			'analytics',
			'csw(\d+)?',
			'matomo(\d+)?',
			'prometheus(\d+)?',
			'misc\d+',
			'db\d+',
			'cp\d+',
			'mw\d+',
			'jobrunner\d+',
			'gluster(fs)?(\d+)?',
			'ns\d+',
			'bacula\d+',
			'mail(\d+)?',
			'ldap(wiki)?(\d+)?',
			'cloud\d+',
			'mon\d+',
			'bots(\d+)',
			'kafka(\d+)?',
			'swift(ac|fs|object|proxy)?(\d+)?',
			'lizardfs\d+',
			'elasticsearch(\d+)?',
			'rdb\d+',
			'phab(\d+)?',
			'services\d+',
			'puppet\d+',
			'test\d+',
			'dbbackup\d+',
			'graylog(\d+)?',
			'mem\d+',
			'jobchron\d+',
			'mwtask(\d+)?',
			'es\d+',
			'os\d+',
			'bast(ion)?(\d+)?',
			'reports(\d+)?',
			'(.*)wiki(pedi)?a(.*)',
			'opensearch(\d+)?',
			'mywiki',
			'phorge(\d+)?',
                        'files(\d+)?',
                        'count(\d+)?',
		]
	],
	'wgCreateWikiStateDays' => [
		'default' => [
			'inactive' => 45,
			'closed' => 15,
			'removed' => 30,
			'deleted' => 15,
		]
	],
	'wgCreateWikiEnableManageInactiveWikis' => [
		'default' => true,
	],
	'wgCreateWikiSQLFiles' => [
		'default' => [
			"$IP/sql/mysql/tables-generated.sql",
			"$IP/extensions/AbuseFilter/db_patches/mysql/tables-generated.sql",
			"$IP/extensions/AntiSpoof/sql/mysql/tables-generated.sql",
			"$IP/extensions/BetaFeatures/sql/tables-generated.sql",
			"$IP/extensions/CheckUser/schema/mysql/tables-generated.sql",
			"$IP/extensions/CheckUser/schema/mysql/tables-virtual-checkuser-generated.sql",
			"$IP/extensions/DataDump/sql/data_dump.sql",
			"$IP/extensions/Echo/sql/mysql/tables-generated.sql",
			"$IP/extensions/GlobalBlocking/sql/mysql/tables-generated-global_block_whitelist.sql",
			"$IP/extensions/Linter/sql/mysql/tables-generated.sql",
			"$IP/extensions/MediaModeration/schema/mysql/tables-generated.sql",
			"$IP/extensions/OAuth/schema/mysql/tables-generated.sql",
			//"$IP/extensions/RottenLinks/sql/rottenlinks.sql",
			//"$IP/extensions/UrlShortener/schemas/mysql/tables-generated.sql",
		],
	],

	// CreateWiki Defined Special Variables
	'cwClosed' => [
		'default' => false,
	],
	'cwDeleted' => [
		'default' => false,
	],
	'cwExperimental' => [
		'default' => false,
	],
	'cwInactive' => [
		'default' => false,
	],
	'cwPrivate' => [
		'default' => false,
	],

	// for Cloudflare
	'wgUsePrivateIPs' => [
		'default' => true,
	],
	'wgCdnServersNoPurge' => [
		'default' => [
			// IPv4 addresses
			"103.21.244.0/22",
			"103.22.200.0/22",
			"103.31.4.0/22",
			"104.16.0.0/13",
			"104.24.0.0/14",
			"108.162.192.0/18",
			"131.0.72.0/22",
			"141.101.64.0/18",
			"162.158.0.0/15",
			"172.64.0.0/13",
			"173.245.48.0/20",
			"188.114.96.0/20",
			"190.93.240.0/20",
			"197.234.240.0/22",
			"198.41.128.0/17",

			// IPv6 addresses
			"2400:cb00::/32",
			"2606:4700::/32",
			"2803:f800::/32",
			"2405:b500::/32",
			"2405:8100::/32",
			"2a06:98c0::/29",
		],
	],


	// RSS Settings
	'wgRSSAllowImageTag' => [
		'default' => false,
		'mijopediawiki' => true,
	],
	'wgRSSAllowLinkTag' => [
		'default' => false,
	],
	'wgRSSCacheAge' => [
		'default' => 3600,
	],
	'wgRSSItemMaxLength' => [
		'default' => 200,
	],
	'wgRSSProxy' => [
		'default' => '',
	],
	'wgRSSDateDefaultFormat' => [
		'default' => 'Y-m-d H:i:s',
	],
	'wgRSSUrlWhitelist' => [
		'ext-RSSfeed' => [
			'*',
		],
	],

	'wmgSharedUploadDBname' => [
		'default' => false,
	],
	'wgFileExtensions' => [
		'default' => [
			'djvu',
			'gif',
			'ico',
			'jpg',
			'jpeg',
			'ogg',
			'pdf',
			'png',
			'svg',
			'webp',
		],
	],
	'wgArticlePath' => [
		'default' => '/wiki/$1',
	],
	'wgScriptPath' => [
		'default' => '',
	],
	'wgUsePathInfo' => [
		'default' => true,
	],
	'wgResourceBasePath' => [
		'default' => '',
	],
	'wgManageWikiModulesEnabled' => [
		'default' => [
			'core' => true,
			'extensions' => true,
			'namespaces' => true,
			'permissions' => true,
			'settings' => true,
		],
	],
	'wgManageWikiPermissionsAdditionalAddGroups' => [
		'default' => [],
	],
	'wgManageWikiPermissionsAdditionalRights' => [
		'default' => [
			'*' => [
				'autocreateaccount' => true,
				'read' => true,
				'oathauth-enable' => true,
				'viewmyprivateinfo' => true,
				'editmyoptions' => true,
				'editmyprivateinfo' => true,
				'editmywatchlist' => true,
				'writeapi' => true,
			],
			'checkuser' => [
				'checkuser' => true,
				'checkuser-log' => true,
				'abusefilter-privatedetails' => true,
				'abusefilter-privatedetails-log' => true,
			],
			'suppress' => [
				'abusefilter-hidden-log' => true,
				'abusefilter-hide-log' => true,
				'browsearchive' => true,
				'deletedhistory' => true,
				'deletedtext' => true,
				'deletelogentry' => true,
				'deleterevision' => true,
				'hideuser' => true,
				'suppressionlog' => true,
				'suppressrevision' => true,
				'viewsuppressed' => true,
			],
			'steward' => [
				'userrights' => true,
			],
			'user' => [
				'mwoauthmanagemygrants' => true,
				'user' => true,
			],
		],
		'+metawiki' => [
			'steward' => [
				'abusefilter-modify-global' => true,
				'centralauth-lock' => true,
				'centralauth-suppress' => true,
				'centralauth-rename' => true,
				'centralauth-unmerge' => true,
				'createwiki' => true,
				'createwiki-deleterequest' => true,
				'globalblock' => true,
				'handle-import-request-interwiki' => true,
				'handle-import-requests' => true,
				'handle-ssl-requests' => true,
				'managewiki-core' => true,
				'managewiki-extensions' => true,
				'managewiki-namespaces' => true,
				'managewiki-permissions' => true,
				'managewiki-settings' => true,
				'managewiki-restricted' => true,
				'managewiki-privacy' => true,
				'noratelimit' => true,
				'oathauth-verify-user' => true,
				'userrights' => true,
				'userrights-interwiki' => true,
				'globalgroupmembership' => true,
				'globalgrouppermissions' => true,
				'view-private-import-requests' => true,
				'view-private-ssl-requests' => true,
			],
			'user' => [
				'createnewwiki' => true,
				'request-ssl' => true,
			]
		]
	],
	'wgManageWikiPermissionsAdditionalRemoveGroups' => [
		'default' => [],
	],
	'wgManageWikiPermissionsDisallowedRights' => [
		'default' => [
			'any' => [
				'abusefilter-hide-log',
				'abusefilter-hidden-log',
				'abusefilter-modify-global',
				'abusefilter-private',
				'abusefilter-private-log',
				'abusefilter-privatedetails',
				'abusefilter-privatedetails-log',
				'aft-oversighter',
				'autocreateaccount',
				'bigdelete',
				'centralauth-createlocal',
				'centralauth-lock',
				'centralauth-suppress',
				'centralauth-rename',
				'centralauth-unmerge',
				'checkuser',
				'checkuser-log',
				'createwiki',
				'createwiki-deleterequest',
				'createwiki-suppressionlog',
				'createwiki-suppressrequest',
				'editincidents',
				'editothersprofiles-private',
				'flow-suppress',
				'generate-random-hash',
				'globalblock',
				'globalblock-exempt',
				'globalgroupmembership',
				'globalgrouppermissions',
				'handle-import-request-interwiki',
				'handle-import-requests',
				'handle-pii',
				'handle-ssl-requests',
				'hideuser',
				'investigate',
				'ipinfo',
				'ipinfo-view-basic',
				'ipinfo-view-full',
				'ipinfo-view-log',
				'managewiki-restricted',
				'managewiki-privacy',
				'managewiki-editdefault',
				'moderation-checkuser',
				'mwoauthmanageconsumer',
				'mwoauthmanagemygrants',
				'mwoauthsuppress',
				'mwoauthviewprivate',
				'mwoauthviewsuppressed',
				'oathauth-api-all',
				'oathauth-enable',
				'oathauth-disable-for-user',
				'oathauth-verify-user',
				'oathauth-view-log',
				'renameuser',
				'request-import',
				'request-ssl',
				'requestwiki',
				'siteadmin',
				'securepoll-view-voter-pii',
				'smw-admin',
				'smw-patternedit',
				'smw-viewjobqueuewatchlist',
				'stopforumspam',
				'suppressionlog',
				'suppressrevision',
				'themedesigner',
				'titleblacklistlog',
				'updatepoints',
				'userrights',
				'userrights-interwiki',
				'view-private-import-requests',
				'view-private-ssl-requests',
				'viewglobalprivatefiles',
				'viewpmlog',
				'viewsuppressed',
				'writeapi',
			],
			'user' => [
				'autoconfirmed',
				'noratelimit',
				'skipcaptcha',
				'managewiki-core',
				'managewiki-extensions',
				'managewiki-namespaces',
				'managewiki-permissions',
				'managewiki-settings',
				'globalblock-whitelist',
				'ipblock-exempt',
				'interwiki',
			],
			'*' => [
				'read',
				'skipcaptcha',
				'torunblocked',
				'centralauth-merge',
				'generate-dump',
				'editsitecss',
				'editsitejson',
				'editsitejs',
				'editusercss',
				'edituserjson',
				'edituserjs',
				'editmyoptions',
				'editmyprivateinfo',
				'editmywatchlist',
				'globalblock-whitelist',
				'interwiki',
				'ipblock-exempt',
				'viewmyprivateinfo',
				'viewmywatchlist',
				'managewiki-core',
				'managewiki-extensions',
				'managewiki-namespaces',
				'managewiki-permissions',
				'managewiki-settings',
				'noratelimit',
				'autoconfirmed',
			],
		],
	],
	'wgManageWikiModulesEnabled' => [
		'default' => [
			'core' => true,
			'extensions' => true,
			'namespaces' => true,
			'permissions' => true,
			'settings' => true,
		],
	],
	'wgManageWikiPermissionsDisallowedGroups' => [
		'default' => [
			'checkuser',
			'smwadministrator',
			'oversight',
			'steward',
			'staff',
			'suppress',
			'techteam',
			'trustandsafety',
		],
	],
	'+wgVirtualDomainsMapping' => [
		'default' => [
			'virtual-createwiki' => [ 'db' => 'wikidb' ],
			'virtual-createwiki-central' => [ 'db' => 'metawiki' ],
			'virtual-managewiki' => [ 'db' => 'wikidb' ],
			'virtual-managewiki-central' => [ 'db' => 'metawiki' ],
			'virtual-requestssl' => ['db' => 'metawiki'],
		],
	],
	'wgManageWikiPermissionsDefaultPrivateGroup' => [
		'default' => 'member',
	],
        'wgLocaltimezone' => [
		'default' => 'UTC',
	],      

	'wgWhitelistRead' => [
		'default' => [
			'Special:UserLogin',
			'Special:UserLogout',
			'Special:CreateAccount',
			'Main Page',
		],
	],
	'wgWhitelistReadRegexp' => [
		'default' => [
			'/^(Special):CentralAutoLogin.*/',
			'/^(Special):CentralLogin.*/',
		],
	],

	'wgManageWikiExtensionsDefault' => [
		'default' => [
			'categorytree',
			'cite',
			'citethispage',
			'codeeditor',
			'codemirror',
			'darkmode',
			'globaluserpage',
			'minervaneue',
			'mobilefrontend',
			'syntaxhighlight_geshi',
			'textextracts',
			'urlshortener',
			'wikiseo',
		],
	],

	'wgUseImageMagick' => [
		'default' => true,
	],
	'wgImageMagickConvertCommand' => [
		'default' => '/usr/bin/convert',
	],

	'wgSharedTables' => [
		'default' => [],
	],

	'wgEnableUploads' => [
		'default' => true,
	],

	'+wgGroupPermissions' => [
		'default' => [
			'checkuser' => [
				'abusefilter-privatedetails-log' => false,
			],
			'sysop' => [
				'globalblock-whitelist' => false,
			],
			'createwiki-blocked' => [
				'createnewwiki' => false,
			],
		],
	],

	// OATHAuth
	'wgOATHExclusiveRights' => [
		'default' => [
			'abusefilter-privatedetails',
			'abusefilter-privatedetails-log',
			'centralauth-lock',
			'centralauth-rename',
			'centralauth-suppress',
			'checkuser',
			'checkuser-log',
			'globalblock',
			'globalgrouppermissions',
			'globalgroupmembership',
			'securepoll-view-voter-pii',
			'suppressionlog',
			'suppressrevision',
			'userrights',
			'userrights-interwiki',
		],
		'+metawiki' => [
			'edituserjs',
			'editsitejs',
		],
	],
	'wgOATHRequiredForGroups' => [
		'default' => [
			'checkuser',
			'suppress',
			'steward',
		],
		'+metawiki' => [
			'electionadmin',
			'global-admin',
			'interface-admin',
			'techteam',
			'trustandsafety'
		],
		// metawikibeta should mirror metawiki
		'+metawikibeta' => [
			'global-admin',
			'interface-admin',
			'techteam',
			'trustandsafety'
		],
	],
	// OAuth
	'wgMWOAuthCentralWiki' => [
		'default' => 'metawiki',
		'ldapwikiwiki' => false,
		'srewiki' => false,
		'beta' => 'metawikibeta',
	],
	'wgOAuth2GrantExpirationInterval' => [
		'default' => 'PT4H',
	],
	'wgOAuth2RefreshTokenTTL' => [
		'default' => 'P365D',
	],
	'wgMWOAuthSharedUserSource' => [
		'default' => 'CentralAuth',
	],
	'wgMWOAuthSecureTokenTransfer' => [
		'default' => true,
	],
	'wgOAuth2PublicKey' => [
		'default' => '/var/www/mediawiki/OAuth2.key.pub',
	],
	'wgOAuth2PrivateKey' => [
		'default' => '/var/www/mediawiki/OAuth2.key',
	],


	// Interwiki
	'wgEnableScaryTranscluding' => [
		'default' => true,
	],
	'wgInterwikiCentralDB' => [
		'default' => 'metawiki',
		'beta' => 'metawikibeta',
	],
	'wgExtraInterlanguageLinkPrefixes' => [
		'default' => [
			'simple',
		],
	],
	'wgExtraLanguageNames' => [
		'default' => [
			// Prevent mh from being treated as an interlanguage link (T11615)
			'mh' => '',
		],
	],

	// InterwikiDispatcher
	'wgIWDPrefixes' => [
		'default' => [
			'fandom' => [
				/** Fandom */
				'interwiki' => 'fandom',
				'url' => 'https://$2.fandom.com/wiki/$1',
				'urlInt' => 'https://$2.fandom.com/$3/wiki/$1',
				'baseTransOnly' => true,
			],
			'miraheze' => [
				/** Miraheze */
				'interwiki' => 'mh',
				'url' => 'https://$2.miraheze.org/wiki/$1',
				'dbname' => '$2wiki',
				'baseTransOnly' => true,
			],
			'wikitide' => [
				/** WikiTide */
				'interwiki' => 'wt',
				'url' => 'https://$2.wikitide.org/wiki/$1',
				'baseTransOnly' => true,
			],
			'wikiforge' => [
				/** WikiForge */
				'interwiki' => 'wf',
				'url' => 'https://$2.wikiforge.net/wiki/$1',
				'baseTransOnly' => true,
			],
			'skywiki' => [
				/** SkyWiki */
				'interwiki' => 'skywiki',
				'url' => 'https://$2.cloudwiki.us/wiki/$1',
				'baseTransOnly' => true,
			],
			'cloudwiki' => [
				/** Cloudwiki */
				'interwiki' => 'cloudwiki',
				'url' => 'https://$2.cloudwiki.us/wiki/$1',
				'baseTransOnly' => true,
			],
			'wikioasis' => [
				/** WikiOasis */
				'interwiki' => 'wikioasis',
				'url' => 'https://$2.wikioasis.org/wiki/$1',
				'baseTransOnly' => true,
			],
		],
	],

	// InterwikiSorting
	'wgInterwikiSortingSort' => [
		'ext-InterwikiSorting' => 'code',
	],

	// ImportDump
	'wgImportDumpEnableAutomatedJob' => [
		'default' => true,
	],
	'wgImportDumpInterwikiMap' => [
		'default' => [
			'fandom.com' => 'wikia',
			'miraheze.org' => 'mh',
			'cloudwiki.us' => 'cloudwiki',
			'wikioasis.org' => 'wikioasis'
		],
	],
	'wgImportDumpScriptCommand' => [
		'default' => 'php {IP}/maintenance/importDump.php --wiki={wiki} --username-prefix="{username-prefix}" {file-path}',
	],
	'wgImportDumpUsersNotifiedOnAllRequests' => [
		'default' => [
		],
	],
	'wgImportDumpUsersNotifiedOnFailedImports' => [
		'default' => [
		],
	],
        'wgMaxUploadSize' => [
                'default' => 1024 * 1024 * 25,   // 25MB default upload size
                'heroeswiki' => 1024 * 1024 * 50, // T55
        ],

	// Imports
	'wgImportSources' => [
		'default' => [
			'meta',
			'mh',
			'mhdev',
			'loginwiki',
			'mw',
			'wikipedia',
			'metawikimedia',
		],
	],
          //Licenses
		'wgRightsIcon' => [
			'piklodexwiki' => 'https://upload.wikimedia.org/wikipedia/commons/e/e5/CC_BY-SA_icon.svg',
			'supermariowiki' => 'https://upload.wikimedia.org/wikipedia/commons/1/11/Gnu-fdl.png',
		],
		'wgRightsText' => [
			'piklodexwiki' => 'Creative Commons Attribution-ShareAlike',
			'supermariowiki' => 'GNU Free Documentation License 1.3 or later',
		],
		'wgRightsUrl' => [
			'piklodexwiki' => 'https://creativecommons.org/licenses/by-sa/4.0/',
			'supermariowiki' => 'https://www.gnu.org/copyleft/fdl.html',

		],
          // Footer-icons
         '+wgFooterIcons' => [
              'default' => [
                  'skywiki' => [
                      'skywiki' => [
                          'src' => 'https://meta.cloudwiki.us/images/metawiki/f/f8/Hosted_by_SkyWiki.svg',
                          'url' => 'https://meta.cloudwiki.us/wiki/Main_Page',
                          'alt' => 'Hosted by SkyWiki',
                      ],
                  ],
              ],
              'topicalballswiki' => [
			'poweredby' => [
				'mediawiki' => [
					'src' => 'https://upload.wikimedia.org/wikipedia/commons/b/b6/Poweredby_mediawikiball.png',
					'url' => 'https://www.mediawiki.org/',
					'alt' => 'Powered by MediaWiki',
				],
                       ],
                 ],
              'fanontopicalballswiki' => [
                        'poweredby' => [
                                'mediawiki' => [
                                        'src' => 'https://upload.wikimedia.org/wikipedia/commons/b/b6/Poweredby_mediawikiball.png',
                                        'url' => 'https://www.mediawiki.org/',
                                        'alt' => 'Powered by MediaWiki',
                                ],
                       ],
                 ],
               'topicalballsfrancaiswiki' => [
                        'poweredby' => [
                                'mediawiki' => [
                                        'src' => 'https://upload.wikimedia.org/wikipedia/commons/b/b6/Poweredby_mediawikiball.png',
                                        'url' => 'https://www.mediawiki.org/',
                                        'alt' => 'Powered by MediaWiki',
                                ],
                       ],
               ],
               'zhtopicalballswiki' => [
                        'poweredby' => [
                                'mediawiki' => [
                                        'src' => 'https://upload.wikimedia.org/wikipedia/commons/b/b6/Poweredby_mediawikiball.png',
                                        'url' => 'https://www.mediawiki.org/',
                                        'alt' => 'Powered by MediaWiki',
                                ],
                       ],
            
               ],

            ], 
	// Preferences
	'+wgDefaultUserOptions' => [
		'default' => [
			'enotifwatchlistpages' => 0,
			'math' => 'mathml',
			'usebetatoolbar' => 1,
			'usebetatoolbar-cgd' => 1,
		],
		'+piklodexwiki' => [
		    'rcenhancedfilters-disable' => 1,
		    'wlenhancedfilters-disable' => 1,
		    'usenewrc' => 0,
		    'thumbsize' => 3,
		],
		   '+supermariowiki' => [
                    'rcenhancedfilters-disable' => 1,
                    'wlenhancedfilters-disable' => 1,
                    'usenewrc' => 0,
                    'thumbsize' => 3,
                ],

	],

	'wgEnotifWatchlist' => [
		'default' => true,
	],
	'wgSkipSkins' => [
		'default' => [],
	],

	'wgExportMaxHistory' => [
		'default' => 1000
	],

	'wgIgnoreImageErrors' => [
		'default' => true
	],

	'wgJobRunRate' => [
		'default' => 0,
	],

	'wgDefaultSkin' => [
		'default' => 'vector-2022',
	],
	'wgFallbackSkin' => [
		'default' => 'vector-2022',
	],
	'wgFavicon' => [
		'default' => '/favicon.ico',
	],
	'wgIcon' => [
		'default' => false,
	],
	'wgWordmark' => [
		'default' => false,
	],
	'wgWordmarkHeight' => [
		'default' => 18,
	],
	'wgWordmarkWidth' => [
		'default' => 116,
	],
        '+wgVirtualDomainsMapping' => [
                'default' => [
                        'virtual-botpasswords' => [
                                'db' => 'centralauth',
                        ],
                        'virtual-centralauth' => [
                                'db' => 'centralauth',
                        ],
                        'virtual-globalblocking' => [
                                'db' => 'centralauth',
                        ],
                        'virtual-oathauth' => [
                                'db' => 'centralauth',
                        ],
                        'virtual-importdump' => [
                                'db' => 'metawiki',
                        ],
                        'virtual-createwiki' => [
                                'db' => 'wikidb',
                        ],
                        'virtual-createwiki-central' => [
                                'db' => 'metawiki',
                        ],
                        'virtual-managewiki' => [
                                'db' => 'wikidb',
                        ],
                        'virtual-managewiki-central' => [
                                'db' => 'metawiki',
                        ],
			'virtual-matomoanalytics' => [
				'db' => 'matomo',
			],
                        'virtual-requestssl' => [
                                'db' => 'metawiki',
                        ],
                ],
	],
	'wgMetaNamespace' => [
		'default' => str_replace( [ ' ', ':' ], '_', $wi->sitename ),
	],
	'wgMetaNamespaceTalk' => [
		'default' => str_replace( [ ' ', ':' ], '_', "{$wi->sitename}_talk" ),
	],
	'wgMatomoAnalyticsServerURL' => [
		'default' => 'https://analytics.cloudwiki.us/',
	],
	'wgMatomoAnalyticsUseDB' => [
		'default' => true,
	],
	'wgMatomoAnalyticsSiteID' => [
		'default' => 1,
	],
	'wgMatomoAnalyticsGlobalID' => [
		'default' => 1,
	],
	'wgMatomoAnalyticsDisableCookie' => [
		'default' => true,
	],
];
