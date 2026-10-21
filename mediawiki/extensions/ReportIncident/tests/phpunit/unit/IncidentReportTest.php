<?php

namespace MediaWiki\Extension\ReportIncident\Tests\Unit;

use MediaWiki\Extension\ReportIncident\IncidentReport;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;

/**
 * @group ReportIncident
 *
 * @covers \MediaWiki\Extension\ReportIncident\IncidentReport
 */
class IncidentReportTest extends MediaWikiUnitTestCase {

	public function testConstruction() {
		$incidentReport = new IncidentReport(
			new UserIdentityValue( 1, 'Reporter' ),
			new UserIdentityValue( 2, 'Reported' ),
			$this->createMock( RevisionRecord::class ),
			[ 'foo' ],
			'SomethingElse',
			'Details',
			'thread-id'
		);
		$this->assertInstanceOf( IncidentReport::class, $incidentReport );
	}

	public function testNewFromRestPayload() {
		$this->assertInstanceOf( IncidentReport::class, IncidentReport::newFromRestPayload(
			new UserIdentityValue( 1, 'Reporter' ),
			[
				'reportedUser' => new UserIdentityValue( 2, 'Reported' ),
				'revision' => $this->createMock( RevisionRecord::class ),
				'behaviors' => [ 'foo' ],
				'somethingElseDetails' => 'blah',
				'details' => 'Details',
				'threadId' => 'test'
			]
		) );
	}

	public function testGetters() {
		$reportingUser = new UserIdentityValue( 1, 'Reporter' );
		$reportedUser = new UserIdentityValue( 2, 'Reported' );
		$revisionRecord = $this->createMock( RevisionRecord::class );
		$behaviors = [ 'foo' ];
		$somethingElseDetails = 'Something else';
		$details = 'Details';
		$threadId = 'test-thread-id';
		$incidentReport = new IncidentReport(
			$reportingUser,
			$reportedUser,
			$revisionRecord,
			$behaviors,
			$somethingElseDetails,
			$details,
			$threadId
		);
		$this->assertSame( $reportingUser, $incidentReport->getReportingUser() );
		$this->assertSame( $reportedUser, $incidentReport->getReportedUser() );
		$this->assertSame( $revisionRecord, $incidentReport->getRevisionRecord() );
		$this->assertSame( $behaviors, $incidentReport->getBehaviors() );
		$this->assertSame( $somethingElseDetails, $incidentReport->getSomethingElseDetails() );
		$this->assertSame( $details, $incidentReport->getDetails() );
		$this->assertSame( $threadId, $incidentReport->getThreadId() );
	}
}
