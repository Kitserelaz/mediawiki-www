<?php

namespace MediaWiki\Extension\ReportIncident\Tests\Unit;

use MediaWiki\Extension\ReportIncident\IncidentReport;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use Wikimedia\Assert\ParameterAssertionException;
use Wikimedia\Assert\PreconditionException;

/**
 * @group ReportIncident
 *
 * @covers \MediaWiki\Extension\ReportIncident\IncidentReport
 */
class IncidentReportTest extends MediaWikiUnitTestCase {

	public function testConstruction() {
		$page = new PageIdentityValue( 1, NS_TALK, 'TestPage', PageIdentityValue::LOCAL );

		$revRecord = $this->createMock( RevisionRecord::class );
		$revRecord->method( 'getPage' )
			->willReturn( $page );

		$incidentReport = new IncidentReport(
			new UserIdentityValue( 1, 'Reporter' ),
			new UserIdentityValue( 2, 'Reported' ),
			$revRecord,
			$page,
			'foo',
			'bar',
			'SomethingElse',
			'Details',
			'thread-id'
		);
		$this->assertInstanceOf( IncidentReport::class, $incidentReport );
	}

	public function testNewFromRestPayload() {
		$page = new PageIdentityValue( 1, NS_TALK, 'TestPage', PageIdentityValue::LOCAL );

		$revRecord = $this->createMock( RevisionRecord::class );
		$revRecord->method( 'getPage' )
			->willReturn( $page );

		$this->assertInstanceOf( IncidentReport::class, IncidentReport::newFromRestPayload(
			new UserIdentityValue( 1, 'Reporter' ),
			[
				'reportedUser' => new UserIdentityValue( 2, 'Reported' ),
				'revision' => $revRecord,
				'page' => $page,
				'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
				'somethingElseDetails' => 'blah',
				'physicalHarmType' => 'foo',
				'details' => 'Details',
				'threadId' => 'test'
			]
		) );
	}

	public function testNonLocalPageFails(): void {
		$this->expectException( PreconditionException::class );
		$this->expectExceptionMessage(
			"Expected MediaWiki\Page\PageReferenceValue to belong to the local wiki, but it belongs to 'foo'"
		);

		$page = new PageIdentityValue( 1, NS_TALK, 'TestPage', 'foo' );

		$revRecord = $this->createMock( RevisionRecord::class );
		$revRecord->method( 'getPage' )
			->willReturn( $page );

		new IncidentReport(
			new UserIdentityValue( 1, 'Reporter' ),
			new UserIdentityValue( 2, 'Reported' ),
			$revRecord,
			$page,
			'foo',
			'bar',
			'SomethingElse',
			'Details',
			'thread-id'
		);
	}

	public function testMismatchedPageFails(): void {
		$this->expectException( ParameterAssertionException::class );
		$this->expectExceptionMessage(
			'Bad value for parameter $revision: The given revision must match the given page'
		);

		$page = new PageIdentityValue( 1, NS_TALK, 'TestPage', PageIdentityValue::LOCAL );

		$revRecord = $this->createMock( RevisionRecord::class );
		$revRecord->method( 'getPage' )
			->willReturn( new PageIdentityValue( 2, NS_TALK, 'OtherPage', PageIdentityValue::LOCAL ) );

		new IncidentReport(
			new UserIdentityValue( 1, 'Reporter' ),
			new UserIdentityValue( 2, 'Reported' ),
			$revRecord,
			$page,
			'foo',
			'bar',
			'SomethingElse',
			'Details',
			'thread-id'
		);
	}

	public function testGetters() {
		$reportingUser = new UserIdentityValue( 1, 'Reporter' );
		$reportedUser = new UserIdentityValue( 2, 'Reported' );
		$page = new PageIdentityValue( 1, NS_TALK, 'TestPage', PageIdentityValue::LOCAL );

		$revRecord = $this->createMock( RevisionRecord::class );
		$revRecord->method( 'getPage' )
			->willReturn( $page );

		$behaviorType = 'foo';
		$physicalHarmType = 'bar';
		$somethingElseDetails = 'Something else';
		$details = 'Details';
		$threadId = 'test-thread-id';
		$incidentReport = new IncidentReport(
			$reportingUser,
			$reportedUser,
			$revRecord,
			$page,
			IncidentReport::THREAT_TYPE_IMMEDIATE,
			$behaviorType,
			$physicalHarmType,
			$somethingElseDetails,
			$details,
			$threadId
		);
		$this->assertSame( $reportingUser, $incidentReport->getReportingUser() );
		$this->assertSame( $reportedUser, $incidentReport->getReportedUser() );
		$this->assertSame( $revRecord, $incidentReport->getRevisionRecord() );
		$this->assertSame( $page, $incidentReport->getPage() );
		$this->assertSame( $behaviorType, $incidentReport->getBehaviorType() );
		$this->assertSame( $physicalHarmType, $incidentReport->getPhysicalHarmType() );
		$this->assertSame( $somethingElseDetails, $incidentReport->getSomethingElseDetails() );
		$this->assertSame( $details, $incidentReport->getDetails() );
		$this->assertSame( $threadId, $incidentReport->getThreadId() );
	}
}
