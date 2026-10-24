<?php
/**
 * @license GPL-2.0-or-later
 *
 * @file
 */

namespace MediaWiki\Extension\GlobalAccountClosure\Special;

use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserNameUtils;
use StatusValue;

class SpecialCloseAccount extends SpecialPage {

	public function __construct( private readonly UserNameUtils $userNameUtils ) {
		parent::__construct( 'CloseAccount' );
	}

	/** @inheritDoc */
	public function getRestriction(): string {
		return 'close-account';
	}

	/** @inheritDoc */
	public function doesWrites(): bool {
		return true;
	}

	/** @inheritDoc */
	public function execute( $subpage ): void {
		$this->setHeaders();
		$this->checkPermissions();
		$this->getOutput()->addWikiMsg( 'closeaccount-intro' );

		$form = HTMLForm::factory( 'ooui', [
			'target' => [
				'type' => 'text',
				'label-message' => 'closeaccount-username',
				'required' => true,
			],
			'reason' => [
				'type' => 'text',
				'label-message' => 'closeaccount-reason',
				'required' => true,
			],
		], $this->getContext() );

		$form->setMethod( 'post' )
			->setSubmitCallback( [ $this, 'onSubmit' ] )
			->setSubmitTextMsg( 'closeaccount-submit' )
			->setWrapperLegendMsg( 'closeaccount-legend' )
			->show();
	}

	/** @param array $data */
	public function onSubmit( array $data ): StatusValue {
		$target = $this->userNameUtils->getCanonical( $data['target'] );
		if ( $target === false ) {
			return StatusValue::newFatal( 'closeaccount-invalid-username' );
		}

		$globalUser = CentralAuthUser::getPrimaryInstanceByName( $target );
		if ( !$globalUser->exists() ) {
			return StatusValue::newFatal( 'closeaccount-no-global-account', $target );
		}

		if ( $globalUser->isClosed() ) {
			$status = $globalUser->restoreAccount( $data['reason'], $this->getContext() );
			if ( !$status->isGood() ) {
				return $status;
			}

			$this->getOutput()->addHTML(
				Html::successBox( $this->msg( 'closeaccount-restored', $target )->parse() )
			);
			return $status;
		}

		$status = $globalUser->closeAccount( $data['reason'], $this->getContext() );
		if ( !$status->isGood() ) {
			return $status;
		}

		$this->getOutput()->addHTML(
			Html::successBox( $this->msg( 'closeaccount-success', $target )->parse() )
		);
		return $status;
	}
}