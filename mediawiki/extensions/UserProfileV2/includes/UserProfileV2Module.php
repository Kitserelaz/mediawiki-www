<?php

namespace Telepedia\UserProfileV2;

use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\SkinModule;

class UserProfileV2Module extends SkinModule {

	public function getLessVars( Context $context ) {
		$background = $this->config->get( 'UserProfileV2Color' );
		$borderRadius = $this->config->get( 'UserProfileV2AvatarBorderRadius' );

		$lessVars = parent::getLessVars( $context );

		$lessVars['userprofile-secondary'] = $background;
		$lessVars['userprofile-avatar-border-radius'] = $borderRadius;

		return $lessVars;
	}
}