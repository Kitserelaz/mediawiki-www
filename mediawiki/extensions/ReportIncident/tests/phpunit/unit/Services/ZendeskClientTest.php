<?php
namespace MediaWiki\Extension\ReportIncident\Tests\Unit;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\ReportIncident\IncidentReport;
use MediaWiki\Extension\ReportIncident\Services\ZendeskClient;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Http\MWHttpRequest;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\Utils\UrlUtils;
use MediaWikiUnitTestCase;
use MockHttpTrait;
use Psr\Log\LoggerInterface;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageSpecifier;

/**
 * @covers \MediaWiki\Extension\ReportIncident\Services\ZendeskClient
 */
class ZendeskClientTest extends MediaWikiUnitTestCase {
	use MockHttpTrait;

	private HttpRequestFactory $httpRequestFactory;
	private ITextFormatter $textFormatter;
	private UrlUtils $urlUtils;
	private UserFactory $userFactory;
	private TitleFactory $titleFactory;
	private LoggerInterface $logger;

	private ZendeskClient $zendeskClient;

	protected function setUp(): void {
		$this->httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$this->textFormatter = $this->createMock( ITextFormatter::class );
		$this->urlUtils = $this->createMock( UrlUtils::class );
		$this->userFactory = $this->createMock( UserFactory::class );
		$this->titleFactory = $this->createMock( TitleFactory::class );
		$this->logger = $this->createMock( LoggerInterface::class );

		$formatter = new class implements ITextFormatter {
			public function getLangCode(): string {
				return 'qqx';
			}

			public function format( MessageSpecifier $message ): string {
				return $message->dump();
			}
		};

		$this->zendeskClient = new ZendeskClient(
			$this->httpRequestFactory,
			$formatter,
			$this->urlUtils,
			$this->userFactory,
			$this->titleFactory,
			$this->logger,
			new ServiceOptions( ZendeskClient::CONSTRUCTOR_OPTIONS, [
				'ReportIncidentZendeskHTTPProxy' => 'foo',
				'ReportIncidentZendeskUrl' => 'https://zendesk.example.com',
				'ReportIncidentZendeskSubjectLine' => 'Test subject line',
				'Script' => '/index.php'
			] )
		);
	}

	/**
	 * @dataProvider provideReportData
	 */
	public function testShouldCreateRequest(
		?UserIdentity $reportedUser,
		?string $threadId,
		bool $hasRevision,
		string $expectedPageLink
	): void {
		$page = new PageIdentityValue( 1, NS_TALK, 'TestPage', PageIdentityValue::LOCAL );

		if ( $hasRevision ) {
			$revRecord = $this->createMock( RevisionRecord::class );
			$revRecord->method( 'getId' )
				->willReturn( 1 );
			$revRecord->method( 'getPage' )
				->willReturn( $page );
		} else {
			$revRecord = null;
		}

		$incidentReport = new IncidentReport(
			new UserIdentityValue( 1, 'Reporter' ),
			$reportedUser,
			$revRecord,
			$page,
			IncidentReport::THREAT_TYPE_IMMEDIATE,
			null,
			'threats-physical-harm',
			null,
			'Details',
			$threadId
		);

		$fullReportingUser = $this->createMock( User::class );
		$fullReportingUser->method( 'getEmail' )
			->willReturn( 'reportinguser@example.com' );

		$this->userFactory->method( 'newFromUserIdentity' )
			->with( $incidentReport->getReportingUser() )
			->willReturn( $fullReportingUser );

		$this->urlUtils->method( 'expand' )
			->with( '/index.php' )
			->willReturn( 'page-link' );

		if ( !$hasRevision ) {
			$title = $this->createMock( Title::class );
			$title->method( 'getFullURL' )
				->willReturn( 'page-link-without-rev' );

			$this->titleFactory->method( 'newFromPageReference' )
				->with( $page )
				->willReturn( $title );
		}

		$reportedUserName = $reportedUser ? $reportedUser->getName() : '';

		$expectedPayload = json_encode( [
			'request' => [
				'requester' => [
					'name' => 'Reporter',
					'email' => 'reportinguser@example.com',
				],

				'subject' => 'Test subject line',
				'comment' => [
					'body' => '<message key="reportincident-notification-message-body"><text>Reporter</text>' .
						"<text>$reportedUserName</text>" . $expectedPageLink .
						'<text><message key="reportincident-threats-physical-harm"></message></text>' .
						'<text>Details</text></message>',
				],
			],
		] );

		$this->httpRequestFactory->method( 'create' )
			->willReturnCallback( function ( string $url, array $options ) use ( $expectedPayload ): MWHttpRequest {
				$this->assertSame( 'https://zendesk.example.com/api/v2/requests.json', $url );
				$this->assertSame( 'POST', $options['method'] );
				$this->assertSame( 'foo', $options['proxy'] );
				$this->assertJsonStringEqualsJsonString( $expectedPayload, $options['postData'] );

				return $this->makeFakeHttpRequest();
			} );

		$this->logger->expects( $this->once() )
			->method( 'info' )
			->with( 'Zendesk request created' );

		$status = $this->zendeskClient->notify( $incidentReport );

		$this->assertStatusGood( $status );
	}

	public static function provideReportData(): iterable {
		$reportedUser = new UserIdentityValue( 2, 'Reported' );

		yield 'no thread ID' => [
			$reportedUser,
			null,
			true,
			'<text><message key="reportincident-notification-link-to-page-prefix"></message></text>' .
			'<text>page-link?oldid=1</text>'
		];

		yield 'thread ID for comment' => [
			$reportedUser,
			'c-testuser-20230504030201',
			true,
			'<text><message key="reportincident-notification-link-to-comment-prefix"></message></text>' .
			'<text>page-link?oldid=1#c-testuser-20230504030201</text>'
		];

		yield 'thread ID for topic' => [
			$reportedUser,
			'h-testuser-20230504030201',
			true,
			'<text><message key="reportincident-notification-link-to-topic-prefix"></message></text>' .
			'<text>page-link?oldid=1#h-testuser-20230504030201</text>'
		];

		yield 'no reported user' => [
			null,
			null,
			true,
			'<text><message key="reportincident-notification-link-to-page-prefix"></message></text>' .
			'<text>page-link?oldid=1</text>'
		];

		yield 'no revision' => [
			$reportedUser,
			null,
			false,
			'<text><message key="reportincident-notification-link-to-page-prefix"></message></text>' .
			'<text>page-link-without-rev</text>'
		];
	}

	public function testShouldLogUnknownZendeskError(): void {
		$page = new PageIdentityValue( 1, NS_TALK, 'TestPage', PageIdentityValue::LOCAL );

		$mockRevisionRecord = $this->createMock( RevisionRecord::class );
		$mockRevisionRecord->method( 'getId' )
			->willReturn( 1 );
		$mockRevisionRecord->method( 'getPage' )
			->willReturn( $page );

		$incidentReport = new IncidentReport(
			new UserIdentityValue( 1, 'Reporter' ),
			new UserIdentityValue( 2, 'Reported' ),
			$mockRevisionRecord,
			$page,
			IncidentReport::THREAT_TYPE_IMMEDIATE,
			null,
			'threats-physical-harm',
			null,
			'Details'
		);

		$fullReportingUser = $this->createMock( User::class );
		$fullReportingUser->method( 'getEmail' )
			->willReturn( 'reportinguser@example.com' );

		$this->userFactory->method( 'newFromUserIdentity' )
			->with( $incidentReport->getReportingUser() )
			->willReturn( $fullReportingUser );

		$this->urlUtils->method( 'expand' )
			->with( '/index.php' )
			->willReturn( 'page-link' );

		$this->httpRequestFactory->method( 'create' )
			->willReturn( $this->makeFakeHttpRequest( 'some error', 500 ) );

		$this->logger->expects( $this->once() )
			->method( 'error' )
			->with( 'Unknown Zendesk error while creating request', [ 'status' => 500 ] );

		$status = $this->zendeskClient->notify( $incidentReport );

		$this->assertStatusNotGood( $status );
	}

	public function testShouldLogFormattedZendeskError(): void {
		$page = new PageIdentityValue( 1, NS_TALK, 'TestPage', PageIdentityValue::LOCAL );

		$mockRevisionRecord = $this->createMock( RevisionRecord::class );
		$mockRevisionRecord->method( 'getId' )
			->willReturn( 1 );
		$mockRevisionRecord->method( 'getPage' )
			->willReturn( $page );

		$incidentReport = new IncidentReport(
			new UserIdentityValue( 1, 'Reporter' ),
			new UserIdentityValue( 2, 'Reported' ),
			$mockRevisionRecord,
			$page,
			IncidentReport::THREAT_TYPE_IMMEDIATE,
			null,
			'threats-physical-harm',
			null,
			'Details'
		);

		$fullReportingUser = $this->createMock( User::class );
		$fullReportingUser->method( 'getEmail' )
			->willReturn( 'reportinguser@example.com' );

		$this->userFactory->method( 'newFromUserIdentity' )
			->with( $incidentReport->getReportingUser() )
			->willReturn( $fullReportingUser );

		$this->urlUtils->method( 'expand' )
			->with( '/index.php' )
			->willReturn( 'page-link' );

		$zdError = json_encode( [
			'details' => [],
			'description' => 'RecordValidation errors',
			'error' => 'RecordInvalid'
		] );

		$this->httpRequestFactory->method( 'create' )
			->willReturn(
				$this->makeFakeHttpRequest( $zdError, 400, [
					'Content-Type' => 'application/json'
				] )
			);

		$this->logger->expects( $this->once() )
			->method( 'error' )
			->with(
				'Zendesk error while creating request: "RecordInvalid" (RecordValidation errors)',
				[ 'status' => 400 ]
			);

		$status = $this->zendeskClient->notify( $incidentReport );

		$this->assertStatusNotGood( $status );
	}
}
