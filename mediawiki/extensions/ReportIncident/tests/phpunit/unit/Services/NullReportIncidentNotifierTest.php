<?php
namespace MediaWiki\Extension\ReportIncident\Tests\Unit;

use MediaWiki\Extension\ReportIncident\IncidentReport;
use MediaWiki\Extension\ReportIncident\Services\NullReportIncidentNotifier;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\ReportIncident\Services\NullReportIncidentNotifier
 */
class NullReportIncidentNotifierTest extends MediaWikiUnitTestCase {
	public function testShouldSucceed(): void {
		$notifier = new NullReportIncidentNotifier();

		$status = $notifier->notify( $this->createMock( IncidentReport::class ) );

		$this->assertStatusGood( $status );
	}
}
