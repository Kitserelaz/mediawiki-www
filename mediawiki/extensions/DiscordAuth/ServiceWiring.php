<?php
/** @license GPL-2.0-or-later */

use MediaWiki\Extension\DiscordAuth\DiscordClient;
use MediaWiki\Extension\DiscordAuth\AuthorizationStore;
use MediaWiki\Extension\DiscordAuth\LinkStore;
use MediaWiki\MediaWikiServices;

return [
	'DiscordAuth.AuthorizationStore' => static fn ( MediaWikiServices $services ) => new AuthorizationStore(
		$services->getService( 'CentralAuth.CentralAuthDatabaseManager' )
	),
	'DiscordAuth.Client' => static fn ( MediaWikiServices $services ) => new DiscordClient(
		$services->getMainConfig(), $services->getHttpRequestFactory()
	),
	'DiscordAuth.LinkStore' => static fn ( MediaWikiServices $services ) => new LinkStore(
		$services->getService( 'CentralAuth.CentralAuthDatabaseManager' )
	),
];
