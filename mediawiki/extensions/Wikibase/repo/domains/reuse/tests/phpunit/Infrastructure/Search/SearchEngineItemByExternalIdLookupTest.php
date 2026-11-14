<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\Domains\Reuse\Infrastructure\Search;

use MediaWiki\Search\ISearchResultSet;
use MediaWiki\Search\SearchEngine;
use MediaWiki\Search\SearchEngineFactory;
use MediaWiki\Search\SearchResult;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\Lib\Store\EntityNamespaceLookup;
use Wikibase\Repo\Domains\Reuse\Infrastructure\Search\SearchEngineItemByExternalIdLookup;

/**
 * @covers \Wikibase\Repo\Domains\Reuse\Infrastructure\Search\SearchEngineItemByExternalIdLookup
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class SearchEngineItemByExternalIdLookupTest extends MediaWikiIntegrationTestCase {

	private const ITEM_NAMESPACE = 120;

	public function testGivenOneMatchingItem_returnsItemIdArray(): void {
		$propertyId = new NumericPropertyId( 'P31' );
		$externalId = 'some-external-id';
		$expectedItemId = 'Q42';

		$title = $this->createStub( Title::class );
		$title->method( 'getText' )->willReturn( $expectedItemId );

		$searchResult = $this->createStub( SearchResult::class );
		$searchResult->method( 'getTitle' )->willReturn( $title );

		$resultSet = $this->createStub( ISearchResultSet::class );
		$resultSet->method( 'extractResults' )->willReturn( [ $searchResult ] );

		$searchEngine = $this->createMock( SearchEngine::class );
		$searchEngine->expects( $this->once() )->method( 'setNamespaces' )->with( [ self::ITEM_NAMESPACE ] );
		$searchEngine->expects( $this->once() )->method( 'setLimitOffset' )->with( 50, 0 );
		$searchEngine->expects( $this->once() )
			->method( 'searchText' )
			->with( "haswbstatement:\"{$propertyId}={$externalId}\"" )
			->willReturn( Status::newGood( $resultSet ) );

		$result = $this->newLookup( $searchEngine )->lookupByExternalId( $propertyId, $externalId );

		$this->assertEquals( [ new ItemId( $expectedItemId ) ], $result );
	}

	public function testGivenMultipleMatchingItems_returnsAllItemIds(): void {
		$title1 = $this->createStub( Title::class );
		$title1->method( 'getText' )->willReturn( 'Q1' );
		$title2 = $this->createStub( Title::class );
		$title2->method( 'getText' )->willReturn( 'Q2' );

		$searchResult1 = $this->createStub( SearchResult::class );
		$searchResult1->method( 'getTitle' )->willReturn( $title1 );
		$searchResult2 = $this->createStub( SearchResult::class );
		$searchResult2->method( 'getTitle' )->willReturn( $title2 );

		$resultSet = $this->createStub( ISearchResultSet::class );
		$resultSet->method( 'extractResults' )->willReturn( [ $searchResult1, $searchResult2 ] );

		$searchEngine = $this->createStub( SearchEngine::class );
		$searchEngine->method( 'searchText' )->willReturn( Status::newGood( $resultSet ) );

		$result = $this->newLookup( $searchEngine )
			->lookupByExternalId( new NumericPropertyId( 'P31' ), 'shared-id' );

		$this->assertEquals( [ new ItemId( 'Q1' ), new ItemId( 'Q2' ) ], $result );
	}

	public function testGivenNoSearchResults_returnsEmptyArray(): void {
		$resultSet = $this->createStub( ISearchResultSet::class );
		$resultSet->method( 'extractResults' )->willReturn( [] );

		$searchEngine = $this->createStub( SearchEngine::class );
		$searchEngine->method( 'searchText' )->willReturn( Status::newGood( $resultSet ) );

		$result = $this->newLookup( $searchEngine )
			->lookupByExternalId( new NumericPropertyId( 'P31' ), 'no-match' );

		$this->assertSame( [], $result );
	}

	public function testGivenSearchEngineReturnsNull_returnsEmptyArray(): void {
		$searchEngine = $this->createStub( SearchEngine::class );
		$searchEngine->method( 'searchText' )->willReturn( null );

		$result = $this->newLookup( $searchEngine )
			->lookupByExternalId( new NumericPropertyId( 'P31' ), 'any-value' );

		$this->assertSame( [], $result );
	}

	public function testGivenSearchEngineReturnsNonResultSet_returnsEmptyArray(): void {
		$searchEngine = $this->createStub( SearchEngine::class );
		$searchEngine->method( 'searchText' )->willReturn( Status::newGood( 'not-a-result-set' ) );

		$result = $this->newLookup( $searchEngine )
			->lookupByExternalId( new NumericPropertyId( 'P31' ), 'any-value' );

		$this->assertSame( [], $result );
	}

	private function newLookup( SearchEngine $searchEngine ): SearchEngineItemByExternalIdLookup {
		$factory = $this->createStub( SearchEngineFactory::class );
		$factory->method( 'create' )->willReturn( $searchEngine );

		$namespaceLookup = $this->createStub( EntityNamespaceLookup::class );
		$namespaceLookup->method( 'getEntityNamespace' )
			->with( Item::ENTITY_TYPE )
			->willReturn( self::ITEM_NAMESPACE );

		return new SearchEngineItemByExternalIdLookup( $factory, $namespaceLookup );
	}
}
