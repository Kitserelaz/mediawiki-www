<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\Domains\Reuse\Infrastructure\GraphQL\Resolvers;

use GraphQL\Executor\Promise\Adapter\SyncPromiseQueue;
use MediaWikiIntegrationTestCase;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetItems\BatchGetItems;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetItems\BatchGetItemsResponse;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemByExternalId\LookUpItemByExternalId;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemByExternalId\LookUpItemByExternalIdRequest;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemByExternalId\LookUpItemByExternalIdResponse;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\UseCaseError;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\UseCaseErrorType;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Aliases;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Descriptions;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Item;
use Wikibase\Repo\Domains\Reuse\Domain\Model\ItemsBatch;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Labels;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Sitelinks;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Statements;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Errors\GraphQLError;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Errors\GraphQLErrorType;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\QueryContext;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\ItemByExternalIdResolver;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\ItemResolver;
use Wikibase\Repo\Tests\Domains\Reuse\Infrastructure\GraphQL\SearchEnabledTestTrait;

/**
 * @covers \Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\ItemByExternalIdResolver
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class ItemByExternalIdResolverTest extends MediaWikiIntegrationTestCase {

	use SearchEnabledTestTrait;

	public function testGivenOneMatchingItem_returnsItem(): void {
		$this->simulateSearchEnabled();

		$propertyId = 'P31';
		$externalId = 'some-external-id';
		$itemId = 'Q42';
		$context = new QueryContext();

		$item = new Item(
			new ItemId( $itemId ),
			new Labels(),
			new Descriptions(),
			new Aliases(),
			new Sitelinks(),
			new Statements()
		);

		$useCase = $this->createMock( LookUpItemByExternalId::class );
		$useCase->expects( $this->once() )
			->method( 'execute' )
			->with( new LookUpItemByExternalIdRequest( new NumericPropertyId( $propertyId ), $externalId ) )
			->willReturn( new LookUpItemByExternalIdResponse( [ new ItemId( $itemId ) ] ) );

		$batchGetItems = $this->createMock( BatchGetItems::class );
		$batchGetItems->method( 'execute' )
			->willReturn( new BatchGetItemsResponse( new ItemsBatch( [ $itemId => $item ] ) ) );

		$resolver = new ItemByExternalIdResolver( $useCase, new ItemResolver( $batchGetItems ) );
		$promise = $resolver->resolve( $propertyId, $externalId, $context );

		SyncPromiseQueue::run();

		$this->assertEquals( $item, $promise->result );
	}

	public function testGivenNoMatchingItem_returnsNull(): void {
		$this->simulateSearchEnabled();

		$useCase = $this->createMock( LookUpItemByExternalId::class );
		$useCase->expects( $this->once() )
			->method( 'execute' )
			->willReturn( new LookUpItemByExternalIdResponse( [] ) );

		$itemResolver = $this->createMock( ItemResolver::class );
		$itemResolver->expects( $this->never() )->method( 'resolveItem' );

		$result = ( new ItemByExternalIdResolver( $useCase, $itemResolver ) )
			->resolve( 'P31', 'unknown-id', new QueryContext() );

		$this->assertNull( $result );
	}

	public function testGivenInvalidProperty_throwsGraphQLError(): void {
		$this->simulateSearchEnabled();

		$useCase = $this->createMock( LookUpItemByExternalId::class );
		$useCase->method( 'execute' )
			->willThrowException( new UseCaseError(
				UseCaseErrorType::INVALID_EXTERNAL_ID_PROPERTY,
				"Property 'P31' is not of type 'external-id'."
			) );

		try {
			( new ItemByExternalIdResolver( $useCase, $this->createStub( ItemResolver::class ) ) )
				->resolve( 'P31', 'some-value', new QueryContext() );
			$this->fail( 'Expected GraphQLError was not thrown' );
		} catch ( GraphQLError $e ) {
			$this->assertSame( GraphQLErrorType::INVALID_EXTERNAL_ID_PROPERTY, $e->type );
		}
	}

	public function testGivenMultipleMatchingItems_returnsExternalIdNonUnique(): void {
		$this->simulateSearchEnabled();

		$itemIds = [ new ItemId( 'Q1' ), new ItemId( 'Q2' ) ];

		$useCase = $this->createMock( LookUpItemByExternalId::class );
		$useCase->expects( $this->once() )
			->method( 'execute' )
			->willReturn( new LookUpItemByExternalIdResponse( $itemIds ) );

		$itemResolver = $this->createMock( ItemResolver::class );
		$itemResolver->expects( $this->never() )->method( 'resolveItem' );

		$result = ( new ItemByExternalIdResolver( $useCase, $itemResolver ) )
			->resolve( 'P31', 'shared-id', new QueryContext() );

		$this->assertEquals( $itemIds, $result );
	}

	public function testGivenSearchNotAvailable_throwsGraphQLError(): void {
		$this->simulateSearchEnabled( false );

		$lookUpItemByExternalId = $this->createStub( LookUpItemByExternalId::class );
		$lookUpItemByExternalId->expects( $this->never() )
			->method( 'execute' )->willReturn( $this->createStub( LookUpItemByExternalIdResponse::class ) );

		$this->expectException( GraphQLError::class );
		$this->expectExceptionMessage( 'Search is not available due to insufficient server configuration' );

		( new ItemByExternalIdResolver(
			$lookUpItemByExternalId,
			$this->createStub( ItemResolver::class ),
		) )->resolve( 'P31', 'some-id', new QueryContext() );
	}

}
