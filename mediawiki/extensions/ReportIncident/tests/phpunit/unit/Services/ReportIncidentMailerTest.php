<?php

namespace MediaWiki\Extension\ReportIncident\Tests\Unit\Services;

use MailAddress;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\ReportIncident\IncidentReport;
use MediaWiki\Extension\ReportIncident\IncidentReportEmailStatus;
use MediaWiki\Extension\ReportIncident\Services\ReportIncidentMailer;
use MediaWiki\Mail\IEmailer;
use MediaWiki\Message\Message;
use MediaWiki\Message\TextFormatter;
use MediaWiki\Status\Status;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Utils\UrlUtils;
use MediaWikiUnitTestCase;
use Psr\Log\LoggerInterface;
use Wikimedia\TestingAccessWrapper;

/**
 * @group ReportIncident
 *
 * @covers MediaWiki\Extension\ReportIncident\Services\ReportIncidentMailer
 */
class ReportIncidentMailerTest extends MediaWikiUnitTestCase {

	public function testNoRecipientEmails() {
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'error' )
			->with( 'ReportIncidentRecipientEmails configuration is empty or not an array, not sending an email.' );
		$reportIncidentMailer = $this->getReportIncidentMailer(
			[
				'ReportIncidentRecipientEmails' => null,
				'ReportIncidentEmailFromAddress' => null,
			],
			null, null, null, null, $mockLogger
		);
		$result = $reportIncidentMailer->sendEmail( $this->createMock( IncidentReport::class ) );
		$this->assertStatusError(
			'rawmessage',
			$result,
		);
		$this->assertSame(
			'ReportIncidentRecipientEmails configuration is empty or not an array, not sending an email.',
			$result->getErrors()[0]['params'][0]
		);
	}

	public function testInvalidRecipientEmailFormat() {
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'error' )
			->with( 'ReportIncidentRecipientEmails configuration is empty or not an array, not sending an email.' );
		$reportIncidentMailer = $this->getReportIncidentMailer(
			[
				'ReportIncidentRecipientEmails' => 'a@b.com',
				'ReportIncidentEmailFromAddress' => null,
			],
			null, null, null, null, $mockLogger
		);
		$result = $reportIncidentMailer->sendEmail( $this->createMock( IncidentReport::class ) );
		$this->assertStatusError(
			'rawmessage',
			$result,
		);
		$this->assertSame(
			'ReportIncidentRecipientEmails configuration is empty or not an array, not sending an email.',
			$result->getErrors()[0]['params'][0]
		);
	}

	public function testNoFromAddress() {
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'error' )
			->with( 'ReportIncidentEmailFromAddress configuration is empty, not sending an email.' );
		$reportIncidentMailer = $this->getReportIncidentMailer(
			[
				'ReportIncidentRecipientEmails' => [ 'a@b.com' ],
				'ReportIncidentEmailFromAddress' => null,
			],
			null, null, null, null, $mockLogger
		);
		$result = $reportIncidentMailer->sendEmail( $this->createMock( IncidentReport::class ) );
		$this->assertStatusError(
			'rawmessage',
			$result,
		);
		$this->assertSame(
			'ReportIncidentEmailFromAddress configuration is empty, not sending an email.',
			$result->getErrors()[0]['params'][0]
		);
	}

	public function testActuallySendEmailWithFatalIEmailerStatus() {
		// A good IEmailer::send status is tested in an integration test.
		//
		// Expect that the logger is called to log an error.
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockMessage = $this->createMock( Message::class );
		$mockLogger->expects( $this->once() )
			->method( 'error' )
			->with(
				'Unable to send report incident email. IEmailer::send returned the following: {emailer-message}',
				[
					'emailer-message' => $mockMessage,
				]
			);
		// Mock IEmailer::send to simulate a php mail error.
		$mockEmailer = $this->createMock( IEmailer::class );
		$mockEmailer->method( 'send' )
			->with(
				[ new MailAddress( 'a@b.com' ) ],
				new MailAddress( 'test@test.com', 'emailsender' ),
				'reportincident-email-subject',
				'reportincident-email-body',
			)->willReturn( Status::newFatal( 'php-mail-error-unknown' ) );
		// Mock ReportIncidentEmailStatus::getMessage to return a mock result to
		// avoid interacting with methods that cannot be used in unit tests.
		$mockReportIncidentEmailStatus = $this->getMockBuilder( IncidentReportEmailStatus::class )
			->onlyMethods( [ 'getMessage' ] )
			->getMock();
		$mockReportIncidentEmailStatus->method( 'getMessage' )
			->with( false, false, 'en' )
			->willReturn( $mockMessage );
		$reportIncidentMailer = $this->getReportIncidentMailer(
			[
				'ReportIncidentRecipientEmails' => [ 'a@b.com' ],
				'ReportIncidentEmailFromAddress' => null,
			],
			null, $mockEmailer, null, null, $mockLogger
		);
		$reportIncidentMailer = TestingAccessWrapper::newFromObject( $reportIncidentMailer );
		$returnedStatus = $reportIncidentMailer->actuallySendEmail(
			[ new MailAddress( 'a@b.com' ) ],
			new MailAddress( 'test@test.com', 'emailsender' ),
			'reportincident-email-subject',
			'reportincident-email-body',
			$mockReportIncidentEmailStatus
		);
		$this->assertStatusNotGood( $returnedStatus );
	}

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

}
