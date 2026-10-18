<?php

namespace WikiOasis\WikiOasisMagic;

use ErrorPageError;
use MediaWiki\Html\Html;
use MediaWiki\Message\Message;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use ManualLogEntry;

class SpecialChangeDomain extends SpecialPage {

	private CreateWikiDatabaseUtils $databaseUtils;
	private RemoteWikiFactory $remoteWikiFactory;

	public function __construct(
		CreateWikiDatabaseUtils $databaseUtils,
		RemoteWikiFactory $remoteWikiFactory
	) {
		parent::__construct( 'ChangeDomain', 'managewiki-restricted' );

		$this->databaseUtils = $databaseUtils;
		$this->remoteWikiFactory = $remoteWikiFactory;
	}

	/**
	 * @param ?string $par
	 */
	public function execute( $par ): void {
		if ( !$this->databaseUtils->isCurrentWikiCentral() ) {
			throw new ErrorPageError( 'errorpagetitle', 'createwiki-wikinotcentralwiki' );
		}
		$par = explode( '/', $par ?? '', 3 );

		$this->getOutput()->setPageTitle( 'Change domain' );
		if ( $par[0] == '' ) {
			$this->showInputBox();
		} else {
			$this->showWikiForm( $par[0] );
		}
	}


	public function showInputBox() {
		$formDescriptor = [
			'info' => [
				'default' => $this->msg( 'changedomain-info' )->text(),
				'type' => 'info',
			],
			'dbname' => [
				'label-message' => 'managewiki-label-dbname',
				'type' => 'text',
				'size' => 20,
				'required' => true
			]
		];

		if ( !$this->getContext()->getUser()->isAllowed( 'managewiki-restricted' ) ) {
                        $this->getOutput()->addHTML(
                                Html::errorBox( $this->msg( 'managewiki-error-nopermission' )->escaped() )
                        );
		}

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext(), 'searchForm' );
		$htmlForm->setWrapperLegendMsg( 'managewiki-core-header' );
		$htmlForm->setMethod( 'post' )
			->setSubmitCallback( [ $this, 'onSubmitRedirectToWikiForm' ] )
			->prepareForm()
			->show();

		return true;
	}


	public function onSubmitRedirectToWikiForm( array $params ) {
		if ( $params['dbname'] !== '' ) {
			header( 'Location: ' . SpecialPage::getTitleFor( 'ChangeDomain' )->getFullURL() . '/' . $params['dbname'] );
		} else {
			return 'Invalid url.';
		}

		return true;
	}


	public function showWikiForm( $wiki ) {
		$out = $this->getOutput();

		$out->addModules( [ 'ext.managewiki.oouiform' ] );

		$out->addModuleStyles( [
			'ext.managewiki.oouiform.styles',
			'mediawiki.widgets.TagMultiselectWidget.styles',
		] );

		$out->addModuleStyles( [ 'oojs-ui-widgets.styles' ] );
		$out->addModules( [ 'mediawiki.special.userrights' ] );

		$remoteWiki = $this->remoteWikiFactory->newInstance( $wiki );

		if ( $remoteWiki->isLocked() ) {
			$out->addHTML( Html::errorBox( $this->msg( 'managewiki-mwlocked' )->escaped() ) );
		}

		$options = [];

                $formDescriptor = [
			'wiki' => [
			        'type' => 'hidden',
			        'name' => 'wiki',
			        'default' => $wiki,
    			],
                        'subdomain' => [
                                'default' => $remoteWiki->getServerName(),
                                'label-message' => 'changedomain-label-domain',
                                'type' => 'text',
                                'size' => 20
			]
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext(), 'changesubdomain' );

		$htmlForm->setSubmitTextMsg( 'managewiki-save' );

                $htmlForm->setSubmitCallback( [ $this, 'submitForm' ] );

		if ( !$this->getContext()->getUser()->isAllowed( 'managewiki-restricted' ) ) {
			$out->addHTML(
				Html::errorBox( $this->msg( 'managewiki-error-nopermission' )->escaped() )
			);
		}

		$htmlForm->show();
	}

        public function submitForm( array $params ) {
                $out = $this->getOutput();

                $remoteWiki = $this->remoteWikiFactory->newInstance( $params['wiki'] );

                if ( $remoteWiki->isLocked() ) {
			$out->addHTML( Html::errorBox( $this->msg( 'managewiki-mwlocked' )->escaped() ) );
			return;
		}

		if ( !$this->getContext()->getUser()->isAllowed( 'managewiki-restricted' ) ) {
			$out->addHTML(
				Html::errorBox( $this->msg( 'managewiki-error-nopermission' )->escaped() )
			);
			return;
		}

		$remoteWiki->setServerName( $params['subdomain'] );
		$remoteWiki->commit();
                $logEntry = new ManualLogEntry( 'managewiki', 'settings' );
                $logEntry->setPerformer( $this->getUser() );
                $logEntry->setTarget( SpecialPage::getTitleValueFor( 'ChangeDomain', (string)$params['wiki'] ) );
                $logEntry->setParameters( [ '4::wiki' => $params['wiki'], '5::changes' => 'servername' ] );
                $logID = $logEntry->insert();
                $logEntry->publish( $logID );
		$out->addHTML(
			Html::successBox(
				Html::element(
					'p',
					[],
					wfMessage( 'managewiki-success' )->plain()
				),
				'mw-notify-success'
			)
		);

                return true;
        }

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'wikimanage';
	}
}
