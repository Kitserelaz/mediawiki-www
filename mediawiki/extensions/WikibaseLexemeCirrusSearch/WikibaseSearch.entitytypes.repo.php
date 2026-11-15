<?php

use MediaWiki\MediaWikiServices;
use Wikibase\DataModel\Services\Lookup\InProcessCachingDataTypeLookup;
use Wikibase\Lexeme\Search\Elastic\LexemeFieldDefinitions;
use Wikibase\Lexeme\Search\Elastic\LexemeFullTextQueryBuilder;
use Wikibase\Lib\EntityTypeDefinitions as Def;
use Wikibase\Lib\SettingsArray;
use Wikibase\Repo\WikibaseRepo;
use Wikibase\Search\Elastic\Fields\StatementProviderFieldDefinitions;

return [
	'lexeme' => [
		Def::SEARCH_FIELD_DEFINITIONS => static function ( array $languageCodes, SettingsArray $searchSettings ) {
			$services = MediaWikiServices::getInstance();
			$config = $services->getMainConfig();
			if ( $config->has( 'LexemeLanguageCodePropertyId' ) ) {
				$lcID = $config->get( 'LexemeLanguageCodePropertyId' );
			} else {
				$lcID = null;
			}
			return new LexemeFieldDefinitions(
				StatementProviderFieldDefinitions::newFromSettings(
					WikibaseRepo::getDataTypeFactory( $services ),
					new InProcessCachingDataTypeLookup(
						WikibaseRepo::getPropertyDataTypeLookup( $services ) ),
					WikibaseRepo::getDataTypeDefinitions( $services )
						->getSearchIndexDataFormatterCallbacks(),
					$searchSettings,
					WikibaseRepo::getLogger( $services ),
					[ LexemeFieldDefinitions::class, 'getSearchStatements' ]
				),
				WikibaseRepo::getEntityLookup( $services ),
				$lcID
					? WikibaseRepo::getEntityIdParser( $services )->parse( $lcID )
					: null
			);
		},
		Def::FULLTEXT_SEARCH_CONTEXT => LexemeFullTextQueryBuilder::CONTEXT_LEXEME_FULLTEXT,
	],
];
