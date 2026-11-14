<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\Domains\Reuse\Infrastructure\GraphQL\Resolvers;

use GraphQL\Deferred;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemBySitelink\LookUpItemBySitelink;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemBySitelink\LookUpItemBySitelinkRequest;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemBySitelink\LookUpItemBySitelinkResponse;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\QueryContext;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\ItemBySitelinkResolver;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\ItemResolver;

/**
 * @covers \Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\ItemBySitelinkResolver
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class LookupItemBySitelinkResolverTest extends TestCase {
	public function testResolveLookupItem(): void {
		$context = new QueryContext();
		$siteId = 'mywiki';
		$title = 'site name';
		$itemId = new ItemId( 'Q123' );

		$lookupUseCase = $this->createMock( LookUpItemBySitelink::class );
		$lookupUseCase->expects( $this->once() )
			->method( 'execute' )
			->with( new LookUpItemBySitelinkRequest( $siteId, $title ) )
			->willReturn( new LookUpItemBySitelinkResponse( $itemId ) );

		$itemResolver = $this->createMock( ItemResolver::class );
		$expectedDeferred = $this->createStub( Deferred::class );
		$itemResolver->expects( $this->once() )
			->method( 'resolveItem' )
			->with( $itemId->getSerialization(), $context )
			->willReturn( $expectedDeferred );

		$actualResolver = new ItemBySitelinkResolver( $lookupUseCase, $itemResolver );
		$actualResult = $actualResolver->resolve( $siteId, $title, $context );

		$this->assertSame( $expectedDeferred, $actualResult );
	}
}
