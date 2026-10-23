<?php

namespace MediaWiki\Extension\CentralAuth\Special;

use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserNameUtils;
use StatusValue;

class SpecialDisableAccount extends SpecialPage {

	public function __construct( private readonly UserNameUtils $userNameUtils ) {
		parent::__construct( 'DisableAccount' );
	}

	/** @inheritDoc */
	public function getRestriction(): string {
		return 'disable-account';
	}

	/** @inheritDoc */
	public function doesWrites(): bool {
		return true;
	}

	/** @inheritDoc */
	public function execute( $subpage ): void {
		$this->setHeaders();
		$this->checkPermissions();
		$this->getOutput()->addWikiMsg( 'disableaccount-intro' );

		$form = HTMLForm::factory( 'ooui', [
			'target' => [
				'type' => 'text',
				'label-message' => 'disableaccount-username',
				'required' => true,
			],
			'reason' => [
				'type' => 'text',
				'label-message' => 'disableaccount-reason',
				'required' => true,
			],
		], $this->getContext() );

		$form->setMethod( 'post' )
			->setSubmitCallback( [ $this, 'onSubmit' ] )
			->setSubmitTextMsg( 'disableaccount-submit' )
			->setWrapperLegendMsg( 'disableaccount-legend' )
			->show();
	}

	/** @param array $data */
	public function onSubmit( array $data ): StatusValue {
		$target = $this->userNameUtils->getCanonical( $data['target'] );
		if ( $target === false ) {
			return StatusValue::newFatal( 'disableaccount-invalid-username' );
		}

		$globalUser = CentralAuthUser::getPrimaryInstanceByName( $target );
		if (
			!$globalUser->exists() ||
			( ( $globalUser->isSuppressed() || $globalUser->isHidden() ) &&
				!$this->getContext()->getAuthority()->isAllowed( 'centralauth-suppress' ) )
		) {
			return StatusValue::newFatal( 'disableaccount-no-global-account', $target );
		}

		$status = $globalUser->adminLockHide( true, null, $data['reason'], $this->getContext() );
		if ( !$status->isGood() ) {
			return $status;
		}

		$this->getOutput()->addHTML(
			Html::successBox( $this->msg( 'disableaccount-success', $target )->parse() )
		);

		return $status;
	}
}