<?php

namespace MediaWiki\Extension\ReportIncident\Tests\Unit;

use MediaWiki\Extension\ReportIncident\IncidentReportEmailStatus;
use MediaWikiUnitTestCase;

/**
 * @group ReportIncident
 *
 * @covers \MediaWiki\Extension\ReportIncident\IncidentReportEmailStatus
 */
class IncidentReportEmailStatusTest extends MediaWikiUnitTestCase {
	public function testGetEmailContents() {
		$incidentReportEmailStatus = IncidentReportEmailStatus::newGood();
		$this->assertArrayEquals(
			[],
			$incidentReportEmailStatus->getEmailContents(),
			true,
			true,
			'::getEmailContents should return an empty array unless $emailContents is set.'
		);
		$incidentReportEmailStatus->emailContents = [
			'to' => 'test@test.com',
			'from' => [ 'test@testing.com' ],
			'subject' => 'testing',
			'body' => "testing.\ntest"
		];
		$this->assertArrayEquals(
			[
				'to' => 'test@test.com',
				'from' => [ 'test@testing.com' ],
				'subject' => 'testing',
				'body' => "testing.\ntest"
			],
			$incidentReportEmailStatus->getEmailContents(),
			true,
			true,
			'::getEmailContents should return the value of $emailContents.'
		);
	}
}
