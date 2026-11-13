<?php

namespace Miraheze\CreateWiki\Specials;

use MediaWiki\Html\Html;
use MediaWiki\MainConfigNames;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Status\Status;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use MirahezeFunctions;

class SpecialUpgrade extends FormSpecialPage {

	private const LATEST_VERSION = 'stable';

	public function __construct(
		private readonly RemoteWikiFactory $remoteWikiFactory,
	) {
		parent::__construct( 'Upgrade', 'upgrade-mediawiki' );
	}

	/** @inheritDoc */
	protected function getFormFields(): array {
		return [
			'info' => [
				'type' => 'info',
				'help' => $this->msg( 'upgrade-mediawiki-info' )->parse(),
				'raw-help' => true,
			],
		];
	}

	/** @inheritDoc */
	public function onSubmit( array $formData ): bool|Status {
		if ( !is_dir( '/srv/mediawiki/' . self::LATEST_VERSION ) ) {
			return Status::newFatal( 'upgrade-mediawiki-not-available' );
		}

		$dbname = $this->getConfig()->get( MainConfigNames::DBname );
		$currentVersion = MirahezeFunctions::getMediaWikiVersion( $dbname );
		if ( $currentVersion === self::LATEST_VERSION ) {
			$this->getOutput()->addHTML(
				Html::successBox( $this->msg( 'upgrade-mediawiki-already-latest' )->escaped() )
			);
			return true;
		}

		$remoteWiki = $this->remoteWikiFactory->newInstance( $dbname );
		$remoteWiki->setExtraFieldData(
			'mediawiki-version', self::LATEST_VERSION, default: $currentVersion
		);
		$remoteWiki->commit();

		$this->getOutput()->addHTML(
			Html::successBox( $this->msg( 'upgrade-mediawiki-success' )->escaped() )
		);
		return true;
	}
}