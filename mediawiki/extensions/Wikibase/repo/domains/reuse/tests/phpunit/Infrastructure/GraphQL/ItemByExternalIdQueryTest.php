<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\Domains\Reuse\Infrastructure\GraphQL;

use Generator;
use MediaWikiIntegrationTestCase;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Services\Lookup\InMemoryDataTypeLookup;
use Wikibase\DataModel\Services\Lookup\InMemoryEntityLookup;
use Wikibase\DataModel\Tests\NewItem;
use Wikibase\Lib\Store\EntityRevisionLookup;
use Wikibase\Lib\Store\LatestRevisionIdResult;
use Wikibase\Repo\Domains\Reuse\Domain\Services\ItemByExternalIdLookup;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\GraphQLService;
use Wikibase\Repo\Domains\Reuse\WbReuse;

/**
 * @covers \Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\ItemByExternalIdResolver
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class ItemByExternalIdQueryTest extends MediaWikiIntegrationTestCase {

	use SearchEnabledTestTrait;

	/** @dataProvider queryProvider */
	public function testQuery( array $lookupReturn, string $query, array $expectedResult, ?Item $item ): void {
		$lookup = $this->createStub( ItemByExternalIdLookup::class );
		$lookup->method( 'lookupByExternalId' )->willReturn( $lookupReturn );

		$result = $this->newGraphQLService( $lookup, $item )->query( $query );

		$this->assertSame( $expectedResult, $result );
	}

	public static function queryProvider(): Generator {
		$item = NewItem::withId( 'Q42' )
				->andLabel( 'en', 'potato' )
				->build();

		yield 'one matching item' => [
			[ $item->getId() ],
			'{ itemByExternalId(property: "P31", externalId: "some external id") {
				... on Item { id label(languageCode: "en") }
			 } }',
			[ 'data' => [ 'itemByExternalId' => [
				'id' => $item->getId()->getSerialization(),
				'label' => 'potato',
			] ] ],
			$item,
		];

		yield 'no matching item' => [
			[],
			'{ itemByExternalId(property: "P31", externalId: "no-match") { ... on Item { id } } }',
			[ 'data' => [ 'itemByExternalId' => null ] ],
			null,
		];

		yield 'multiple matching items' => [
			[ new ItemId( 'Q1' ), new ItemId( 'Q2' ) ],
			'{ itemByExternalId(property: "P31", externalId: "shared-id") { ... on ExternalIdNonUnique { items } } }',
			[ 'data' => [ 'itemByExternalId' => [ 'items' => [ 'Q1', 'Q2' ] ] ] ],
			null,
		];
	}

	/** @dataProvider errorsProvider */
	public function testErrors( ?string $propertyDataType, string $expectedMessage ): void {
		$lookup = $this->createStub( ItemByExternalIdLookup::class );

		$query = '{ itemByExternalId(property: "P31", externalId: "some-value") { ... on Item { id } } }';
		$result = $this->newGraphQLService( $lookup, propertyDataType: $propertyDataType )->query( $query );

		$this->assertStringContainsString( $expectedMessage, $result['errors'][0]['message'] );
	}

	public static function errorsProvider(): Generator {
		yield 'non-existent property' => [ null, "Property 'P31' does not exist." ];
		yield 'wrong data type' => [ 'string', "Property 'P31' is not of type 'external-id'." ];
	}

	private function newGraphQLService(
		ItemByExternalIdLookup $lookup,
		?Item $item = null,
		?string $propertyDataType = 'external-id',
	): GraphQLService {
		$this->simulateSearchEnabled();

		$entityLookup = new InMemoryEntityLookup();
		if ( $item ) {
			$entityLookup->addEntity( $item );
		}

		$dataTypeLookup = new InMemoryDataTypeLookup();
		if ( $propertyDataType !== null ) {
			$dataTypeLookup->setDataTypeForProperty( new NumericPropertyId( 'P31' ), $propertyDataType );
		}

		$revisionLookup = $this->createStub( EntityRevisionLookup::class );
		$revisionLookup->method( 'getLatestRevisionId' )->willReturnCallback(
			fn( ItemId $id ) => LatestRevisionIdResult::concreteRevision( 1, '20260101001122' )
		);

		$this->setService( 'WbReuse.ItemByExternalIdLookup', $lookup );
		$this->setService( 'WikibaseRepo.EntityLookup', $entityLookup );
		$this->setService( 'WikibaseRepo.EntityRevisionLookup', $revisionLookup );
		$this->setService( 'WikibaseRepo.PropertyDataTypeLookup', $dataTypeLookup );

		return WbReuse::getGraphQLService();
	}

}
