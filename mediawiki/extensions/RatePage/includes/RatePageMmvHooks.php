<?php

use MediaWiki\MediaWikiServices;

/**
 * Enables access to MMV's internal functionality.
 * Class RatePage\MultimediaViewer\RatePageMmvHooks
 *
 * No namespace because it apparently breaks the autoloader (???).
 */
class RatePageMmvHooks extends \MediaWiki\Extension\MultimediaViewer\Hooks {
	/**
	 * Returns whether MMV should be enabled for this user.
	 *
	 * @param User $user
	 *
	 * @return bool
	 */
	public static function isMmvEnabled( User $user ) : bool {
		// TODO: use proper DI here
		$mwInstance = MediaWikiServices::getInstance();
		$instance = new self( $mwInstance->getMainConfig(), $mwInstance->getSpecialPageFactory(), $mwInstance->getUserOptionsLookup(), null );
		return $instance->shouldHandleClicks( $user );
	}
}
