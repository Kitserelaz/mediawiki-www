<?php

use MediaWiki\MediaWikiServices;
use Telepedia\UserProfileV2\Hooks\UserProfileV2HookRunner;

return [
	'UserProfileV2HookRunner' => static function ( MediaWikiServices $services ): UserProfileV2HookRunner {
		return new UserProfileV2HookRunner( $services->getHookContainer() );
	},
];
