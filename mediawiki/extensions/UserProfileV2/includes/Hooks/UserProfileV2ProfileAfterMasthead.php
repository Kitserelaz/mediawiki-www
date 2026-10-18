<?php

namespace Telepedia\UserProfileV2\Hooks;

use MediaWiki\User\User;

interface UserProfileV2ProfileAfterMasthead {

	/**
	 * This hook runs after the header has been generated, and before the main user profile content is shown
	 * Can be used to add stuff like blocked notices or anything else you might want between the header and the
	 * page text
	 * @param User $user the user whose profile you're currently viewing
	 * @param &$html html output of the masthead
	 * @return void
	 */
	public function onUserProfileV2ProfileAfterMasthead( User $user, &$html ): void;

}
