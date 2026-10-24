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
use MediaWiki\User\UserNameUtils;
use StatusValue;

class SpecialRestoreClosedAccount extends SpecialPage {

	public function __construct( private readonly UserNameUtils $userNameUtils ) {
		parent::__construct( 'RestoreClosedAccount' );
	}

	/** @inheritDoc */
	public function doesWrites(): bool {
		return true;
	}

	/** @inheritDoc */
	public function execute( $subPage ): void {
		$this->setHeaders();
		$this->getOutput()->addWikiMsg( 'restoreaccount-intro' );

		$form = HTMLForm::factory( 'ooui', [
			'username' => [
				'type' => 'text',
				'label-message' => 'restoreaccount-username',
				'default' => $this->getRequest()->getText( 'user', $subPage ?? '' ),
				'required' => true,
			],
			'password' => [
				'type' => 'password',
				'label-message' => 'restoreaccount-password',
				'required' => true,
			],
		], $this->getContext() );

		$form->setMethod( 'post' )
			->setSubmitCallback( [ $this, 'onSubmit' ] )
			->setSubmitTextMsg( 'restoreaccount-submit' )
			->show();
	}

	/** @param array $data */
	public function onSubmit( array $data ): StatusValue {
		$username = $this->userNameUtils->getCanonical( $data['username'] );
		if ( $username === false ) {
			return StatusValue::newFatal( 'restoreaccount-invalid' );
		}

		$throttler = new Throttler();
		$throttleResult = $throttler->increase(
			$username,
			$this->getRequest()->getIP(),
			__METHOD__
		);
		if ( $throttleResult ) {
			return StatusValue::newFatal(
				wfMessage( 'login-throttled' )->durationParams( $throttleResult['wait'] )
			);
		}

		$centralUser = CentralAuthUser::getPrimaryInstanceByName( $username );
		$status = $centralUser->restoreAccountWithPassword( $data['password'] );
		if ( !$status->isGood() ) {
			return StatusValue::newFatal( 'restoreaccount-invalid' );
		}

		$throttler->clear( $username, $this->getRequest()->getIP() );
		$this->getOutput()->addHTML(
			Html::successBox( $this->msg( 'restoreaccount-success', $username )->parse() )
		);
		return $status;
	}
}