<?php

use MediaWiki\MediaWikiServices;
use WikiOasis\WikiOasisMagic\CustomDomainManager;

return [
	'SkyWikiCustomDomains' => static fn ( MediaWikiServices $services ) => new CustomDomainManager( $services ),
];
