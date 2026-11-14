<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\Domains\Reuse\Infrastructure\GraphQL;

use Generator;
use MediaWiki\Site\HashSiteStore;
use MediaWiki\Site\MediaWikiSite;
use MediaWikiIntegrationTestCase;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Lookup\InMemoryEntityLookup;
use Wikibase\DataModel\Tests\NewItem;
use Wikibase\Lib\Store\EntityRevisionLookup;
use Wikibase\Lib\Store\LatestRevisionIdResult;
use Wikibase\Lib\Store\SiteLinkStore;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\GraphQLService;
use Wikibase\Repo\Domains\Reuse\WbReuse;
use Wikibase\Repo\SiteLinkGlobalIdentifiersProvider;
use Wikibase\Repo\Store\Store;

/**
 * @covers \Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\GraphQLService
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class LookUpItemBySitelinkTest extends MediaWikiIntegrationTestCase {
	private const SITE_ID = 'default';

	/** @var Item[] */
	private static array $items = [];
	private static MediaWikiSite $sitelinkSite;

	/**
	 * @dataProvider lookupProvider
	 */
	public function testQuery( string $query, array $expectedResult ): void {
		$this->assertEquals(
			$expectedResult,
			$this->newGraphQLService()->query( $query )
		);
	}

	public static function lookupProvider(): Generator {
		$sitelinkTitle = 'Potato';
		$item = self::createItem(
			NewItem::withLabel( 'en', 'potato' )
				->andSiteLink( self::SITE_ID, $sitelinkTitle )
		);

		yield 'simple itemBySitelink query' => [
			'{ itemBySitelink( title: "' . $sitelinkTitle . '", siteId: "' . self::SITE_ID . '" ) { id } }',
			[ 'data' => [ 'itemBySitelink' => [ 'id' => $item->getId() ] ] ],
		];

		yield 'no item found - returns null' => [
			'{ itemBySitelink( title: "Does not exist", siteId: "' . self::SITE_ID . '" ) { id } }',
			[ 'data' => [ 'itemBySitelink' => null ] ],
		];
	}

	/**
	 * @dataProvider errorsProvider
	 */
	public function testErrors( string $query, string $expectedErrorMessage ): void {
		$result = $this->newGraphQLService()->query( $query );
		$this->assertSame( $expectedErrorMessage, $result['errors'][0]['message'] );
	}

	public static function errorsProvider(): Generator {

		$siteId = 'not-a-valid-site-id';
		yield 'validates site ID' => [
			"{ itemBySitelink(title: \"Potato\", siteId: \"$siteId\") { id } }",
			"Not a valid site ID: \"$siteId\"",
		];
	}

	private static function createItem( NewItem $newItem ): Item {
		// assign the ID here so that we don't have to worry about collisions
		$nextId = empty( self::$items ) ? 'Q1' : 'Q' . self::getNextNumericId( self::$items );
		$item = $newItem->andId( $nextId )->build();
		self::$items[] = $item;

		return $item;
	}

	private static function getNextNumericId( array $entities ): int {
		$latestEntity = $entities[array_key_last( $entities )];
		return (int)substr( $latestEntity->getId()->getSerialization(), 1 ) + 1;
	}

	private function newGraphQLService(): GraphQLService {
		$entityLookup = new InMemoryEntityLookup();
		foreach ( self::$items as $item ) {
			$entityLookup->addEntity( $item );
		}
		$this->setService( 'WikibaseRepo.EntityLookup', $entityLookup );

		$siteIdProvider = $this->createStub( SiteLinkGlobalIdentifiersProvider::class );
		$siteIdProvider->method( 'getSiteIds' )->willReturn( [ self::SITE_ID ] );
		$this->setService( 'WikibaseRepo.SiteLinkGlobalIdentifiersProvider', $siteIdProvider );

		self::$sitelinkSite = new MediaWikiSite();
		self::$sitelinkSite->setLinkPath( 'https://wiki.example/wiki/$1' );
		self::$sitelinkSite->setGlobalId( self::SITE_ID );
		$this->setService( 'SiteLookup', $siteLookup ?? new HashSiteStore( [ self::$sitelinkSite ] ) );

		$revisionLookup = $this->createStub( EntityRevisionLookup::class );
		$revisionLookup->method( 'getLatestRevisionId' )->willReturnCallback(
			fn( ItemId $id ) => LatestRevisionIdResult::concreteRevision( 1, '20260101001122' )
		);
		$this->setService( 'WikibaseRepo.EntityRevisionLookup', $revisionLookup );

		$store = $this->createStub( Store::class );
		$sitelinkStore = $this->createStub( SiteLinkStore::class );
		$sitelinkStore->method( 'getItemIdForLink' )->willReturnCallback(
			function( string $siteId, string $title ): ?ItemId {
				foreach ( self::$items as $item ) {
					if (
						$item->hasLinkToSite( $siteId ) &&
						$item->getSiteLink( $siteId )->getPageName() === $title
					) {
						return $item->getId();
					}
				}

				return null;
			}
		);
		$store->method( 'newSiteLinkStore' )->willReturn( $sitelinkStore );
		$this->setService( 'WikibaseRepo.Store', $store );

		return WbReuse::getGraphQLService();
	}

}
