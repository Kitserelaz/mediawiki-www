<?php

namespace MediaWiki\Extension\ReportIncident\Tests\Integration;

use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;

/**
 * @coversNothing
 * @group ReportIncident
 * @group Database
 */
class ReportIncidentServiceWiringTest extends MediaWikiIntegrationTestCase {
	/**
	 * @dataProvider provideService
	 */
	public function testService( string $name ) {
		MediaWikiServices::getInstance()->get( $name );
		$this->addToAssertionCount( 1 );
	}

	public static function provideService() {
		$wiring = require __DIR__ . '../../../../src/ServiceWiring.php';
		foreach ( $wiring as $name => $_ ) {
			yield $name => [ $name ];
		}
	}
}
