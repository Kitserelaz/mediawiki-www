<?php

declare( strict_types = 1 );

namespace Wikibase\Repo\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\Property;
use Wikibase\Repo\ControllerRegistry;

/**
 * @covers \Wikibase\Repo\ControllerRegistry
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class ControllerRegistryTest extends TestCase {

	public function testGetReturnsCallbacksForRegisteredController(): void {
		$expectedCallback = static fn() => null;
		$controllerName = 'some-controller';

		$registry = new ControllerRegistry( [
			Item::ENTITY_TYPE => [ $controllerName => $expectedCallback ],
		] );

		$this->assertSame(
			[ Item::ENTITY_TYPE => $expectedCallback ],
			$registry->get( $controllerName ),
		);
	}

	public function testReturnsOnlyTypesWithRegisteredController(): void {
		$expectedCallback = static fn() => null;
		$controllerName = 'some-other-controller';

		$registry = new ControllerRegistry( [
			Item::ENTITY_TYPE => [ $controllerName => $expectedCallback ],
			Property::ENTITY_TYPE => [],
		] );

		$this->assertSame(
			[ Item::ENTITY_TYPE => $expectedCallback ],
			$registry->get( $controllerName )
		);
	}

	public function testGetReturnsEmptyArrayForUndefinedController(): void {
		$this->assertSame(
			[],
			( new ControllerRegistry( [] ) )->get( 'undefined-controller' )
		);
	}

	public static function provideInvalidDefinitions(): iterable {
		yield 'non-array definition' => [ [ Item::ENTITY_TYPE => 'not-an-array' ] ];
		yield 'non-callable callback' => [ [ Item::ENTITY_TYPE => [ 'some-controller' => 'not-a-callable' ] ] ];
	}

	/** @dataProvider provideInvalidDefinitions */
	public function testConstructorRejectsInvalidDefinitions( array $definitions ): void {
		$this->expectException( InvalidArgumentException::class );
		new ControllerRegistry( $definitions );
	}

}
