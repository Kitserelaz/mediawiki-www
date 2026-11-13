<?php

namespace Miraheze\CreateWiki\Specials;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\FormSpecialPage;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\WikiManagerFactory;

class SpecialPermanentlyDeleteWiki extends FormSpecialPage {

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly WikiManagerFactory $wikiManagerFactory,
	) {
		parent::__construct( 'PermanentlyDeleteWiki' );
	}

	/**
	 * @param ?string $par
	 * @throws ErrorPageError
	 */
	public function execute( $par ): void {
		if ( !$this->databaseUtils->isCurrentWikiCentral() ) {
			throw new ErrorPageError( 'errorpagetitle', 'createwiki-wikinotcentralwiki' );
		}

		$this->getOutput()->addHTML(
			Html::warningBox( $this->msg( 'createwiki-permanentlydelete-warning' )->escaped() )
		);
		parent::execute( $par );
	}

	/** @inheritDoc */
	protected function getFormFields(): array {
		return [
			'dbname' => [
				'type' => 'text',
				'label-message' => 'createwiki-label-dbname',
				'required' => true,
				'maxlength' => 64,
			],
			'confirmation' => [
				'type' => 'text',
				'label-message' => 'createwiki-permanentlydelete-confirm-label',
				'required' => true,
				'maxlength' => 64,
			],
			'reason' => [
				'type' => 'textarea',
				'label-message' => 'createwiki-label-reason',
				'required' => true,
				'rows' => 5,
				'useeditfont' => true,
			],
		];
	}

	/** @inheritDoc */
	public function onSubmit( array $formData ): bool {
		if ( $formData['dbname'] !== $formData['confirmation'] ) {
			$this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'createwiki-permanentlydelete-confirmation-mismatch' )->escaped() )
			);
			return false;
		}

		$wikiManager = $this->wikiManagerFactory->newInstance( $formData['dbname'] );
		if ( !$wikiManager->exists() ) {
			$this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'createwiki-error-missingwiki', $formData['dbname'] )->escaped() )
			);
			return false;
		}

		$error = $wikiManager->permanentlyDelete(
			$this->getUser()->getName(),
			$formData['reason']
		);
		if ( $error ) {
			$this->getOutput()->addHTML(
				Html::errorBox( Html::element( 'p', [], $error ) )
			);
			return false;
		}

		$this->getOutput()->addHTML(
			Html::successBox(
				$this->msg( 'createwiki-permanentlydelete-success', $formData['dbname'] )->escaped()
			)
		);
		return true;
	}

	/** @inheritDoc */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'wiki';
	}

	public function getRestriction(): string {
		return 'createwiki-permanentlydelete';
	}
}