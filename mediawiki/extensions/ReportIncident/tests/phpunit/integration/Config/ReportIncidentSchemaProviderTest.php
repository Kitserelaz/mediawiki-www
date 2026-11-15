<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ReportIncident\Tests\Integration\Config;

use MediaWiki\Extension\CommunityConfiguration\Tests\SchemaProviderTestCase;

/**
 * @coversNothing
 */
class ReportIncidentSchemaProviderTest extends SchemaProviderTestCase {
	protected function getExtensionName(): string {
		return 'ReportIncident';
	}

	protected function getProviderId(): string {
		return 'ReportIncident';
	}

}
