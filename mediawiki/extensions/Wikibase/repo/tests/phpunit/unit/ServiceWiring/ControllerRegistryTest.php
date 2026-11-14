<?php

declare( strict_types = 1 );

namespace Wikibase\Repo\Tests\Unit\ServiceWiring;

use Wikibase\Repo\ControllerRegistry;
use Wikibase\Repo\Tests\Unit\ServiceWiringTestCase;

/**
 * @coversNothing
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class ControllerRegistryTest extends ServiceWiringTestCase {

	public function testConstruction(): void {
		$this->mockService( 'WikibaseRepo.ControllersArray', [] );

		$this->assertInstanceOf(
			ControllerRegistry::class,
			$this->getService( 'WikibaseRepo.ControllerRegistry' ),
		);
	}

}
