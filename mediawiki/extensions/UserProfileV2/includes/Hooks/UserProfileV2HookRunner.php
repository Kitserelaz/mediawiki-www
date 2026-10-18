<?php

namespace Telepedia\UserProfileV2\Hooks;

use MediaWiki\HookContainer\HookContainer;
use MediaWiki\User\User;

class UserProfileV2HookRunner implements UserProfileV2ProfileAfterMasthead {

	private $container;

	public function __construct( HookContainer $container ) {
		$this->container = $container;
	}

	/**
	 * This hook runs after the masthead contents and before the user profile text
	 */
	public function onUserProfileV2ProfileAfterMasthead( User $user, &$html ): void {
		$this->container->run(
			'UserProfileV2ProfileAfterMasthead',
			[ $user, &$html ]
		);
	}
}
