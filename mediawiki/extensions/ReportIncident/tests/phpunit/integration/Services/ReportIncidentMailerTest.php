<?php

namespace MediaWiki\Extension\ReportIncident\Tests\Integration\Services;

use MailAddress;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\ReportIncident\IncidentReport;
use MediaWiki\Extension\ReportIncident\Services\ReportIncidentMailer;
use MediaWiki\Mail\IEmailer;
use MediaWiki\Message\TextFormatter;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\Utils\UrlUtils;
use MediaWikiIntegrationTestCase;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\Message\MessageValue;
use Wikimedia\Message\ParamType;
use Wikimedia\Message\ScalarParam;
use Wikimedia\TestingAccessWrapper;

/**
 * @group ReportIncident
 *
 * @covers \MediaWiki\Extension\ReportIncident\Services\ReportIncidentMailer
 */
class ReportIncidentMailerTest extends MediaWikiIntegrationTestCase {
	private function getReportIncidentMailer(
		array $options,
		?UrlUtils $urlUtils = null,
		?IEmailer $emailer = null,
		?TitleFactory $titleFactory = null,
		?TextFormatter $textFormatter = null,
		?LoggerInterface $logger = null
	) {
		return new ReportIncidentMailer(
			new ServiceOptions( ReportIncidentMailer::CONSTRUCTOR_OPTIONS, $options ),
			$urlUtils ?? $this->createMock( UrlUtils::class ),
			$titleFactory ?? $this->createMock( TitleFactory::class ),
			$textFormatter ?? $this->createMock( TextFormatter::class ),
			$emailer ?? $this->createMock( IEmailer::class ),
			$logger ?? $this->createMock( LoggerInterface::class )
		);
	}

	/** @dataProvider provideSuccessfulEmail */
	public function testSuccessfulEmail( $hasTheadId ) {
		// Make a mock RevisionRecord with ID 1.
		$mockRevisionRecord = $this->createMock( RevisionRecord::class );
		$mockRevisionRecord->method( 'getId' )
			->willReturn( 1 );
		$incidentReport = new IncidentReport(
			UserIdentityValue::newRegistered( 1, 'Test' ),
			UserIdentityValue::newRegistered( 2, 'Violator' ),
			$mockRevisionRecord,
			[ 'something-else', 'foo' ],
			'something-else-details',
			'details',
			$hasTheadId ? 'c-violator-20230706050403' : null
		);
		// Mock the Title for the reporting user and reported user.
		$mockReportingUserPageTitle = $this->createMock( Title::class );
		$mockReportingUserPageTitle->method( 'getPrefixedDBkey' )
			->willReturn( 'User_talk:' . strtr( $incidentReport->getReportingUser()->getName(), ' ', '_' ) );
		$mockReportingUserPageTitle->method( 'getDBkey' )
			->willReturn( strtr( $incidentReport->getReportingUser()->getName(), ' ', '_' ) );
		$mockReportedUserPageTitle = $this->createMock( Title::class );
		$mockReportedUserPageTitle->method( 'getPrefixedDBkey' )
			->willReturn( 'User_talk:' . strtr( $incidentReport->getReportedUser()->getName(), ' ', '_' ) );
		$mockReportedUserPageTitle->method( 'getDBkey' )
			->willReturn( strtr( $incidentReport->getReportedUser()->getName(), ' ', '_' ) );
		// Define the expected message key parameters
		$messageKeyToExpectedParameters = [
			'emailsender' => [],
			'reportincident-email-subject' => [
				new ScalarParam( ParamType::TEXT, $mockReportingUserPageTitle->getPrefixedDBkey() )
			],
			'reportincident-email-something-else' => [
				new ScalarParam( ParamType::TEXT, $incidentReport->getSomethingElseDetails() )
			],
			'reportincident-email-body' => [
				new ScalarParam( ParamType::TEXT, $mockReportingUserPageTitle->getDBkey() ),
				new ScalarParam( ParamType::TEXT, $mockReportedUserPageTitle->getDBkey() ),
				new ScalarParam( ParamType::TEXT, $hasTheadId ?
					new MessageValue( 'reportincident-email-link-to-comment-prefix' ) :
					new MessageValue( 'reportincident-email-link-to-page-prefix' ) ),
				new ScalarParam( ParamType::TEXT, $hasTheadId ?
					'page-link?oldid=1#c-violator-20230706050403' :
					'page-link?oldid=1' ),
				new ScalarParam( ParamType::TEXT, implode( ', ', [ 'reportincident-email-something-else', 'foo' ] ) ),
				new ScalarParam( ParamType::TEXT, $incidentReport->getDetails() ),
				new ScalarParam( ParamType::TEXT, 'special-email-link' ),
			],
		];
		// Mock the TextFormatter to define ::format
		$textFormatter = $this->createMock( TextFormatter::class );
		$textFormatter->method( 'format' )
			->willReturnCallback( function ( MessageValue $messageValue ) use ( $messageKeyToExpectedParameters ) {
				// Validate the parameters
				$this->assertArrayEquals(
					$messageKeyToExpectedParameters[$messageValue->getKey()],
					$messageValue->getParams(),
					true,
					true,
					"Parameters for message {$messageValue->getKey()} not as expected."
				);
				// Mock formatted text value by returning the key
				// as this will identify the string by it's key which
				// should be unique to all other strings in the response.
				return $messageValue->getKey();
			} );
		$titleFactory = $this->createMock( TitleFactory::class );
		// Mock the Special:Email Title object
		$mockSpecialEmailTitle = $this->createMock( Title::class );
		$mockSpecialEmailTitle
			->expects( $this->once() )
			->method( 'getSubpage' )
			->willReturnSelf();
		$mockSpecialEmailTitle
			->expects( $this->once() )
			->method( 'getFullURL' )
			->willReturn( 'special-email-link' );
		$titleFactory->method( 'newFromText' )
			->willReturnMap( [
				[ $incidentReport->getReportingUser()->getName(), NS_USER, $mockReportingUserPageTitle ],
				[ $incidentReport->getReportedUser()->getName(), NS_USER, $mockReportedUserPageTitle ],
				[ 'Special:EmailUser', NS_MAIN, $mockSpecialEmailTitle ],
			] );
		// Mock the UrlUtils::expand method to return a pre-defined string for ease of testing.
		$urlUtils = $this->createMock( UrlUtils::class );
		$urlUtils->method( 'expand' )
			->with( wfScript() )
			->willReturn( 'page-link' );
		$emailer = $this->createMock( IEmailer::class );
		$emailer->method( 'send' )
			->with(
				// The values passed here will be the message keys
				// as the mock implementation of TextFormatter::format
				// returns the message key.
				[ new MailAddress( 'a@b.com' ) ],
				new MailAddress( 'test@test.com', 'emailsender' ),
				'reportincident-email-subject',
				'reportincident-email-body'
			)->willReturn( StatusValue::newGood( 'test-value' ) );
		$reportIncidentMailer = $this->getReportIncidentMailer(
			[
				'ReportIncidentRecipientEmails' => [ 'a@b.com' ],
				'ReportIncidentEmailFromAddress' => 'test@test.com',
			],
			$urlUtils,
			$emailer,
			$titleFactory,
			$textFormatter
		);
		// ::sendEmail returns a ReportIncidentEmailStatus which should
		// have been merged with the status returned by IEmailer::send.
		$sendEmailStatus = $reportIncidentMailer->sendEmail( $incidentReport );
		$this->assertStatusGood( $sendEmailStatus, '::sendEmail should return a good status' );
		$this->assertStatusValue(
			'test-value', $sendEmailStatus,
			'::sendEmail should return a status that has the value in the status returned from IEmailer::send.'
		);
		$this->assertArrayEquals(
			[
				'to' => [ new MailAddress( 'a@b.com' ) ],
				'from' => new MailAddress( 'test@test.com', 'emailsender' ),
				'subject' => 'reportincident-email-subject',
				'body' => 'reportincident-email-body'
			],
			$sendEmailStatus->getEmailContents(),
			true,
			true,
			'The status returned by ::sendEmail was not as expected.'
		);
	}

	public static function provideSuccessfulEmail() {
		return [
			'Has no thread ID in the report' => [ false ],
			'Has a thread ID in the report' => [ true ],
		];
	}

	/** @dataProvider provideGetLinkToReportedContent */
	public function testGetLinkToReportedContent( $threadId, $expectedReturnArray ) {
		$mockRevisionRecord = $this->createMock( RevisionRecord::class );
		$mockRevisionRecord->method( 'getId' )
			->willReturn( 1 );
		$incidentReport = new IncidentReport(
			UserIdentityValue::newRegistered( 1, 'Test' ),
			UserIdentityValue::newRegistered( 2, 'Violator' ),
			$mockRevisionRecord,
			[ 'something-else', 'foo' ],
			'something-else-details',
			'details',
			$threadId
		);
		// Mock the UrlUtils::expand method to return a pre-defined string for ease of testing.
		$urlUtils = $this->createMock( UrlUtils::class );
		$urlUtils->method( 'expand' )
			->with( wfScript() )
			->willReturn( 'page-link' );
		$reportIncidentMailer = $this->getReportIncidentMailer(
			[
				'ReportIncidentRecipientEmails' => [ 'a@b.com' ],
				'ReportIncidentEmailFromAddress' => 'test@test.com',
			],
			$urlUtils
		);
		$reportIncidentMailer = TestingAccessWrapper::newFromObject( $reportIncidentMailer );
		$this->assertArrayEquals(
			$expectedReturnArray,
			$reportIncidentMailer->getLinkToReportedContent( $incidentReport ),
			true,
			true,
			'::getLinkToReportedContent did not return the expected array'
		);
	}

	public static function provideGetLinkToReportedContent() {
		return [
			'No thread ID' => [
				null,
				[
					new MessageValue( 'reportincident-email-link-to-page-prefix' ),
					// UrlUtils::expand is mocked to return this value
					'page-link?oldid=1'
				]
			],
			'Thread ID for comment' => [
				'c-testuser-20230504030201',
				[
					new MessageValue( 'reportincident-email-link-to-comment-prefix' ),
					// UrlUtils::expand is mocked to return this value
					'page-link?oldid=1#c-testuser-20230504030201'
				]
			],
			'Thread ID for topic' => [
				'h-testuser-20230504030201',
				[
					new MessageValue( 'reportincident-email-link-to-topic-prefix' ),
					// UrlUtils::expand is mocked to return this value
					'page-link?oldid=1#h-testuser-20230504030201'
				]
			],
		];
	}
}
