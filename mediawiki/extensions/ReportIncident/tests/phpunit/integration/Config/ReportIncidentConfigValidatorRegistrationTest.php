<?php
namespace MediaWiki\Extension\ReportIncident\Tests\Integration\Config;

use MediaWiki\Extension\ReportIncident\Config\ReportIncidentConfigValidator;
use MediaWikiIntegrationTestCase;

/**
 * @coversNothing
 */
class ReportIncidentConfigValidatorRegistrationTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'CommunityConfiguration' );
	}

	public function testShouldRegisterValidator(): void {
		$provider = $this->getServiceContainer()
			->getService( 'CommunityConfiguration.ProviderFactory' )
			->newProvider( 'ReportIncident' );
		$validator = $provider->getValidator();

		$this->assertInstanceOf( ReportIncidentConfigValidator::class, $validator );
	}
}
