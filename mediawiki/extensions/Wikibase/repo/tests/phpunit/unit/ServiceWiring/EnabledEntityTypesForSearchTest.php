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
class EnabledEntityTypesForSearchTest extends ServiceWiringTestCase {

	public function testConstruction(): void {
		$this->mockService( 'WikibaseRepo.EntitySearchHelperCallbacks', [
			'type1' => fn () => null,
			'type2' => fn () => null,
		] );
		$this->mockService( 'WikibaseRepo.ControllerRegistry', new ControllerRegistry( [
			'type3' => [ ControllerRegistry::WB_SEARCH_ENTITIES_CONTROLLER => fn () => null ],
		] ) );

		$this->assertSame( [ 'type1', 'type2', 'type3' ],
			$this->getService( 'WikibaseRepo.EnabledEntityTypesForSearch' ) );
	}

}
