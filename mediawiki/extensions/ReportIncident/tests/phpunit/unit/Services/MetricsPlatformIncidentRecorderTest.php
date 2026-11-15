<?php
namespace MediaWiki\Extension\ReportIncident\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\EventLogging\MetricsPlatform\MetricsClientFactory;
use MediaWiki\Extension\ReportIncident\IncidentReport;
use MediaWiki\Extension\ReportIncident\Services\MetricsPlatformIncidentRecorder;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use Wikimedia\MetricsPlatform\MetricsClient;

/**
 * @covers \MediaWiki\Extension\ReportIncident\Services\MetricsPlatformIncidentRecorder
 */
class MetricsPlatformIncidentRecorderTest extends MediaWikiUnitTestCase {
	private MetricsClientFactory $metricsClientFactory;
	private UserFactory $userFactory;
	private TitleFactory $titleFactory;

	private MetricsPlatformIncidentRecorder $recorder;

	protected function setUp(): void {
		parent::setUp();

		$this->metricsClientFactory = $this->createMock( MetricsClientFactory::class );
		$this->userFactory = $this->createMock( UserFactory::class );
		$this->titleFactory = $this->createMock( TitleFactory::class );

		$this->recorder = $this->getMockBuilder( MetricsPlatformIncidentRecorder::class )
			->setConstructorArgs( [
				$this->metricsClientFactory,
				$this->titleFactory,
				$this->userFactory
			] )
			->onlyMethods( [ 'getContext' ] )
			->getMock();

		$this->recorder->method( 'getContext' )
			->willReturn( $this->createMock( IContextSource::class ) );
	}

	public function testShouldDoNothingForEmergencyReports(): void {
		$page = new PageIdentityValue( 1, NS_TALK, 'TestPage', PageReferenceValue::LOCAL );

		$revRecord = $this->createMock( RevisionRecord::class );
		$revRecord->method( 'getPage' )
			->willReturn( $page );

		$incidentReport = new IncidentReport(
			new UserIdentityValue( 1, 'Reporter' ),
			new UserIdentityValue( 2, 'Reported' ),
			$revRecord,
			$page,
			IncidentReport::THREAT_TYPE_IMMEDIATE,
			null,
			'threats-physical-harm',
			null,
			'Details'
		);

		$this->metricsClientFactory->expects( $this->never() )
			->method( 'newMetricsClient' );

		$status = $this->recorder->record( $incidentReport );

		$this->assertStatusGood( $status );
	}

	/**
	 * @dataProvider provideBehaviorTypes
	 */
	public function testShouldRecordNonEmergencyReport(
		?UserIdentity $reportedUser,
		string $behaviorType,
		?string $somethingElseDetails
	): void {
		$page = new PageIdentityValue( 1, NS_TALK, 'TestPage', PageReferenceValue::LOCAL );

		$revRecord = $this->createMock( RevisionRecord::class );
		$revRecord->method( 'getPage' )
			->willReturn( $page );

		$incidentReport = new IncidentReport(
			new UserIdentityValue( 2, 'Reporter' ),
			$reportedUser,
			$revRecord,
			$page,
			IncidentReport::THREAT_TYPE_UNACCEPTABLE_BEHAVIOR,
			$behaviorType,
			null,
			$somethingElseDetails,
			'Details'
		);

		$title = $this->createMock( Title::class );
		$this->titleFactory->method( 'newFromPageReference' )
			->with( $page )
			->willReturn( $title );

		$performer = $this->createMock( User::class );
		$this->userFactory->method( 'newFromUserIdentity' )
			->with( $incidentReport->getReportingUser() )
			->willReturn( $performer );

		$metricsClient = $this->createMock( MetricsClient::class );
		$actionContext = [
			'type' => $behaviorType,
			'reportedUserId' => $reportedUser ? $reportedUser->getId() : null,
		];
		if ( $somethingElseDetails ) {
			$actionContext['somethingElseDetails'] = substr( $somethingElseDetails, 0, 200 );
		}
		$metricsClient->expects( $this->once() )
			->method( 'submitInteraction' )
			->with(
				'mediawiki.product_metrics.incident_reporting_system_interaction',
				'/analytics/product_metrics/web/base/1.3.0',
				'submit',
				[
					'action_source' => 'api',
					'action_context' => json_encode( $actionContext ),
				]
			)
			->willReturnCallback( function ( string $streamName, string $schema, string $action, array $event ) {
				$this->assertLessThanOrEqual(
					320,
					mb_strlen( $event['action_context'] ),
					'Metrics Platform instruments only allow max. 320 characters in action_context'
				);
			} );

		$this->metricsClientFactory->method( 'newMetricsClient' )
			->willReturnCallback(
				function ( IContextSource $context ) use ( $title, $performer, $metricsClient ): MetricsClient {
					$this->assertSame( $performer, $context->getUser() );
					$this->assertSame( $title, $context->getTitle() );
					return $metricsClient;
				}
			);

		$status = $this->recorder->record( $incidentReport );

		$this->assertStatusGood( $status );
	}

	public static function provideBehaviorTypes(): iterable {
		// Test each behavior type to ensure it fits into action_context
		// alongside a large user ID for the reported user.
		$reportedUser = new UserIdentityValue( 17_000_000_000, 'Reported' );
		foreach ( IncidentReport::behaviorTypes() as $behaviorType ) {
			yield "behavior type \"$behaviorType\"" => [ $reportedUser, $behaviorType, null ];
		}

		// test that the action context is passed through, even truncated
		yield 'something else details' => [ $reportedUser, 'something-else', 'foo' ];
		yield 'something else details, truncated' => [
			$reportedUser,
			'something-else',
			bin2hex( random_bytes( 512 ) )
		];

		yield 'null reported user' => [ null, 'spam', null ];
	}
}
