<?php
/**
 * @license GPL-2.0-or-later
 *
 * @file
 */

namespace MediaWiki\Extension\GlobalAccountClosure\Special;

use MediaWiki\Auth\Throttler;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use StatusValue;

class SpecialCloseMyAccount extends SpecialPage {

	public function __construct() {
		parent::__construct( 'CloseMyAccount' );
	}

	/** @inheritDoc */
	public function doesWrites(): bool {
		return true;
	}

	/** @inheritDoc */
	public function execute( $subPage ): void {
		$this->setHeaders();
		$this->requireLogin();
		$this->getOutput()->addWikiMsg( 'closemyaccount-intro' );

		$centralUser = CentralAuthUser::getPrimaryInstanceByName( $this->getUser()->getName() );
		if ( !$centralUser->exists() ) {
			$this->getOutput()->addWikiMsg( 'closeaccount-no-global-account', $this->getUser()->getName() );
			return;
		}
		if ( $centralUser->isClosed() ) {
			$this->getOutput()->addWikiMsg( 'closemyaccount-already-closed' );
			return;
		}

		$form = HTMLForm::factory( 'ooui', [
			'password' => [
				'type' => 'password',
				'label-message' => 'closemyaccount-password',
				'required' => true,
			],
		], $this->getContext() );

		$form->setMethod( 'post' )
			->setSubmitCallback( [ $this, 'onSubmit' ] )
			->setSubmitTextMsg( 'closemyaccount-submit' )
			->show();
	}

	/** @param array $data */
	public function onSubmit( array $data ): StatusValue {
		$username = $this->getUser()->getName();
		$throttler = new Throttler();
		$throttleResult = $throttler->increase( $username, $this->getRequest()->getIP(), __METHOD__ );
		if ( $throttleResult ) {
			return StatusValue::newFatal(
				wfMessage( 'login-throttled' )->durationParams( $throttleResult['wait'] )
			);
		}

		$centralUser = CentralAuthUser::getPrimaryInstanceByName( $username );
		$status = $centralUser->closeAccountWithPassword( $data['password'] );
		if ( !$status->isGood() ) {
			return $status;
		}

		$throttler->clear( $username, $this->getRequest()->getIP() );
		$this->getOutput()->addHTML(
			Html::successBox( $this->msg( 'closemyaccount-success' )->parse() )
		);
		return $status;
	}
}