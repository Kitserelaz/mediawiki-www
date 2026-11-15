<?php

use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use Wikibase\Lexeme\DataAccess\Store\NullLabelDescriptionLookup;
use Wikibase\Lexeme\Search\Elastic\FormSearchEntity;
use Wikibase\Lexeme\Search\Elastic\LexemeSearchEntity;
use Wikibase\Repo\Api\CombinedEntitySearchHelper;
use Wikibase\Repo\Api\EntityIdSearchHelper;
use Wikibase\Repo\Api\EntitySearchHelper;
use Wikibase\Repo\WikibaseRepo;

/** @phpcs-require-sorted-array */
return [
	'WikibaseLexemeCirrusSearch.FormSearchHelper' => static function (
		MediaWikiServices $services
	): EntitySearchHelper {
		$entityIdParser = WikibaseRepo::getEntityIdParser( $services );
		$language = RequestContext::getMain()->getLanguage();

		return new CombinedEntitySearchHelper( [
			new FormSearchEntity(
				$entityIdParser,
				RequestContext::getMain()->getRequest(),
				$language,
				WikibaseRepo::getFallbackLabelDescriptionLookupFactory( $services )
			),
			new EntityIdSearchHelper(
				WikibaseRepo::getEntityLookup( $services ),
				$entityIdParser,
				new NullLabelDescriptionLookup(),
				WikibaseRepo::getEnabledEntityTypes( $services )
			),
		] );
	},
	'WikibaseLexemeCirrusSearch.LexemeSearchHelper' => static function (
		MediaWikiServices $services
	): EntitySearchHelper {
		$fallbackTermLookupFactory = WikibaseRepo::getFallbackLabelDescriptionLookupFactory( $services );
		$entityIdParser = WikibaseRepo::getEntityIdParser( $services );
		$language = RequestContext::getMain()->getLanguage();

		return new CombinedEntitySearchHelper( [
			new LexemeSearchEntity(
				$entityIdParser,
				RequestContext::getMain()->getRequest(),
				$language,
				$fallbackTermLookupFactory
			),
			new EntityIdSearchHelper(
				WikibaseRepo::getEntityLookup( $services ),
				$entityIdParser,
				$fallbackTermLookupFactory->newLabelDescriptionLookup( $language ),
				WikibaseRepo::getEnabledEntityTypes( $services )
			),
		] );
	},
];
