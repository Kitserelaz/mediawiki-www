<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\Domains\Search\Infrastructure\Controllers;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Wikibase\Repo\Domains\Search\Infrastructure\Controllers\DispatchingWbSearchEntitiesController;
use Wikibase\Repo\Domains\Search\Infrastructure\Controllers\WbSearchEntitiesController;

/**
 * @covers \Wikibase\Repo\Domains\Search\Infrastructure\Controllers\DispatchingWbSearchEntitiesController
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class DispatchingWbSearchEntitiesControllerTest extends TestCase {

	public function testReturnsRegisteredControllerForKnownType(): void {
		$expectedController = $this->createStub( WbSearchEntitiesController::class );
		$dispatcher = new DispatchingWbSearchEntitiesController( [ 'item' => static fn() => $expectedController ] );

		$this->assertSame( $expectedController, $dispatcher->getControllerForEntityType( 'item' ) );
	}

	public function testThrowsForUnknownEntityType(): void {
		$dispatcher = new DispatchingWbSearchEntitiesController( [] );

		$this->expectException( InvalidArgumentException::class );
		$dispatcher->getControllerForEntityType( 'property' );
	}

}
