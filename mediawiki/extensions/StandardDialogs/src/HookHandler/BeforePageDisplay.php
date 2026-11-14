<?php

namespace MediaWiki\Extension\StandardDialogs\HookHandler;

use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Permissions\PermissionManager;

class BeforePageDisplay implements BeforePageDisplayHook {

	public function __construct( private readonly PermissionManager $permissionManager ) {
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$user = $skin->getUser();
		if ( !$this->permissionManager->userHasRight( $user, 'createpage' ) ) {
			return;
		}
		$out->addModules( [ 'ext.standardDialogs.page-actions' ] );
	}

}
