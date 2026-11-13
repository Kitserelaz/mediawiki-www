<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Html\Html;
use MediaWiki\Parser\Hook\ParserFirstCallInitHook;
use MediaWiki\Parser\Parser;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use WikiOasis\WikiOasisMagic\SpecialCreateNewWiki;

class CreateWikiForm implements ParserFirstCallInitHook {

	public function __construct( private readonly CreateWikiDatabaseUtils $databaseUtils ) {
	}

	public function onParserFirstCallInit( $parser ): void {
		$parser->setHook( 'createwikiform', [ $this, 'render' ] );
	}

	public function render( ?string $input, array $args, Parser $parser ): string {
		if ( !$this->databaseUtils->isCurrentWikiCentral() ) {
			return '';
		}

		$parser->getOutput()->addModuleStyles( [ 'ext.skywiki.createWikiForm.styles' ] );
		$language = $parser->getTargetLanguage();
		$title = SpecialPage::getTitleFor( 'CreateNewWiki' );

		return Html::rawElement( 'form', [
			'action' => wfScript(),
			'method' => 'get',
			'class' => 'mw-createwikiform',
		],
			Html::hidden( 'title', $title->getPrefixedDBkey() ) .
			Html::rawElement( 'label', [],
				Html::element( 'span', [], wfMessage( 'createnewwiki-inline-label' )->inLanguage( $language )->text() ) .
				Html::input( 'sitename', '', 'text', [
					'required' => true,
					'maxlength' => SpecialCreateNewWiki::MAX_SITENAME_LENGTH,
					'placeholder' => wfMessage( 'createnewwiki-inline-example' )->inLanguage( $language )->text(),
				] )
			) .
			Html::element( 'button', [ 'type' => 'submit' ],
				wfMessage( 'createnewwiki-continue' )->inLanguage( $language )->text()
			)
		);
	}
}
