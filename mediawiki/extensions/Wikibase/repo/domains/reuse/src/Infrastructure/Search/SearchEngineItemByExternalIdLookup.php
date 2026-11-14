<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\Search;

use MediaWiki\Search\ISearchResultSet;
use MediaWiki\Search\SearchEngineFactory;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\Lib\Store\EntityNamespaceLookup;
use Wikibase\Repo\Domains\Reuse\Domain\Services\ItemByExternalIdLookup;

/**
 * @license GPL-2.0-or-later
 */
class SearchEngineItemByExternalIdLookup implements ItemByExternalIdLookup {

	private const LIMIT = 50;

	public function __construct(
		private readonly SearchEngineFactory $searchEngineFactory,
		private readonly EntityNamespaceLookup $entityNamespaceLookup,
	) {
	}

	/** @return ItemId[] */
	public function lookupByExternalId( PropertyId $propertyId, string $externalId ): array {
		$searchEngine = $this->searchEngineFactory->create();

		$searchEngine->setNamespaces(
			[ $this->entityNamespaceLookup->getEntityNamespace( Item::ENTITY_TYPE ) ]
		);

		$searchEngine->setLimitOffset( self::LIMIT, 0 );

		$resultSet = $searchEngine->searchText( "haswbstatement:\"{$propertyId}={$externalId}\"" );
		if ( !$resultSet || !( $resultSet->getValue() instanceof ISearchResultSet ) ) {
			return [];
		}

		$results = $resultSet->getValue()->extractResults();

		return array_map( fn( $result ) => new ItemId( $result->getTitle()->getText() ), $results );
	}
}
