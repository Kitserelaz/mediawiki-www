<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\Domains\Reuse\Application\UseCases\LookUpItemBySitelink;

use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemBySitelink\LookUpItemBySitelink;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemBySitelink\LookUpItemBySitelinkRequest;
use Wikibase\Repo\Domains\Reuse\Domain\Services\ItemBySitelinkLookup;

/**
 * @covers \Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemBySitelink\LookUpItemBySitelink
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class LookUpItemBySitelinkTest extends TestCase {
	public function testLookupItemBySitelink(): void {
		$title = 'my page';
		$siteId = 'mywiki';
		$itemIdResult = new ItemId( 'Q123' );

		$itemLookup = $this->createMock( ItemBySitelinkLookup::class );
		$itemLookup->expects( $this->once() )
			->method( 'lookUp' )
			->with( $title, $siteId )
			->willReturn( $itemIdResult );

		$this->assertSame(
			$itemIdResult,
			( new LookUpItemBySitelink( $itemLookup ) )
			->execute( new LookUpItemBySitelinkRequest( $siteId, $title ) )->itemId
		);
	}
}
