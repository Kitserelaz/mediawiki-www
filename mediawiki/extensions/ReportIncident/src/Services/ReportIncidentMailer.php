<?php

namespace MediaWiki\Extension\ReportIncident\Services;

use MailAddress;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\ReportIncident\IncidentReport;
use MediaWiki\Extension\ReportIncident\IncidentReportEmailStatus;
use MediaWiki\Mail\IEmailer;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Utils\UrlUtils;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

/**
 * Handles emailing incident reports.
 */
class ReportIncidentMailer {

	private ServiceOptions $options;
	private TitleFactory $titleFactory;
	private ITextFormatter $textFormatter;
	private IEmailer $emailer;
	private LoggerInterface $logger;
	private UrlUtils $urlUtils;
	public const CONSTRUCTOR_OPTIONS = [
		'ReportIncidentRecipientEmails',
		'ReportIncidentEmailFromAddress',
	];

	/**
	 * @param ServiceOptions $options
	 * @param UrlUtils $urlUtils
	 * @param TitleFactory $titleFactory
	 * @param ITextFormatter $textFormatter
	 * @param IEmailer $emailer
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		ServiceOptions $options,
		UrlUtils $urlUtils,
		TitleFactory $titleFactory,
		ITextFormatter $textFormatter,
		IEmailer $emailer,
		LoggerInterface $logger
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->urlUtils = $urlUtils;
		$this->titleFactory = $titleFactory;
		$this->textFormatter = $textFormatter;
		$this->emailer = $emailer;
		$this->logger = $logger;
	}

	/**
	 * Sends an email to the administrators using the
	 * provided IncidentReport object as the data to
	 * send.
	 *
	 * @param IncidentReport $incidentReport The IncidentReport object containing the data to send in the email.
	 * @return IncidentReportEmailStatus The result of attempting to email the administrators. A good status indicates
	 *   an email was sent.
	 */
	public function sendEmail( IncidentReport $incidentReport ): StatusValue {
		$reportIncidentEmailStatus = $this->validateConfig();
		if ( !$reportIncidentEmailStatus->isGood() ) {
			// Return early if the config was not valid.
			return $reportIncidentEmailStatus;
		}
		// Get MailAddress objects for the to emails and from email.
		$to = array_map( static function ( $address ) {
			return new MailAddress( $address );
		}, $this->options->get( 'ReportIncidentRecipientEmails' ) );
		$from = new MailAddress(
			$this->options->get( 'ReportIncidentEmailFromAddress' ),
			$this->textFormatter->format(
				new MessageValue( 'emailsender' )
			)
		);
		$reportingUserPage = $this->titleFactory->newFromText(
			$incidentReport->getReportingUser()->getName(), NS_USER
		);
		$subject = $this->textFormatter->format(
			new MessageValue(
				'reportincident-email-subject',
				[ $reportingUserPage->getPrefixedDBkey() ]
			)
		);

		[ $linkPrefixText, $linkToPageAtRevision ] = $this->getLinkToReportedContent( $incidentReport );

		$reportedUserPage = $this->titleFactory->newFromText(
			$incidentReport->getReportedUser()->getName(), NS_USER
		);
		// Get the behaviors and substitute the 'something-else' behavior
		// with the text submitted in the Something else textbox.
		$behaviors = $incidentReport->getBehaviors();
		if ( $incidentReport->getSomethingElseDetails() ) {
			$somethingElseIndex = array_search( 'something-else', $behaviors );
			if ( $somethingElseIndex !== false ) {
				$behaviors[$somethingElseIndex] = $this->textFormatter->format(
					new MessageValue(
						'reportincident-email-something-else',
						[ $incidentReport->getSomethingElseDetails() ]
					)
				);
			}
		}
		$emailUrl = $this->titleFactory->newFromText( 'Special:EmailUser' )
			->getSubpage( $reportingUserPage->getDBkey() )
			->getFullURL();

		$body = $this->textFormatter->format(
			new MessageValue(
				'reportincident-email-body',
				[
					$reportingUserPage->getDBkey(),
					$reportedUserPage->getDBkey(),
					$linkPrefixText,
					$linkToPageAtRevision,
					implode( ', ', $behaviors ),
					$incidentReport->getDetails(),
					$emailUrl
				]
			)
		);
		return $this->actuallySendEmail( $to, $from, $subject, $body, $reportIncidentEmailStatus );
	}

	/**
	 * Validates the configuration settings wgReportIncidentRecipientEmails
	 * and wgReportIncidentEmailFromAddress. If invalid this method returns
	 * a fatal status. Otherwise a good status is returned.
	 *
	 *
	 * @return IncidentReportEmailStatus
	 */
	private function validateConfig(): IncidentReportEmailStatus {
		$recipientEmails = $this->options->get( 'ReportIncidentRecipientEmails' );
		if ( !$recipientEmails || !is_array( $recipientEmails ) ) {
			$this->logger->error(
				'ReportIncidentRecipientEmails configuration is empty or not an array, not sending an email.'
			);
			return IncidentReportEmailStatus::newFatal(
				'rawmessage',
				'ReportIncidentRecipientEmails configuration is empty or not an array, not sending an email.'
			);
		}
		if ( !$this->options->get( 'ReportIncidentEmailFromAddress' ) ) {
			$this->logger->error( 'ReportIncidentEmailFromAddress configuration is empty, not sending an email.' );
			return IncidentReportEmailStatus::newFatal(
				'rawmessage',
				'ReportIncidentEmailFromAddress configuration is empty, not sending an email.'
			);
		}
		return IncidentReportEmailStatus::newGood();
	}

	/**
	 * Gets a link to the reported content. This returns a link to
	 * the permalink of the page, and if the report entry point was
	 * via DiscussionTools the link includes an anchor to the topic
	 * or comment that was reported.
	 *
	 * @param IncidentReport $incidentReport The IncidentReport object provided to ::sendEmail
	 * @return array First item being the prefix text for the link to be used in the email
	 *   and the second item being the URL to the reported content.
	 */
	private function getLinkToReportedContent( IncidentReport $incidentReport ): array {
		$revision = $incidentReport->getRevisionRecord();
		// In theory UrlUtils::expand() could return null, this seems pretty unlikely in practice;
		// cast to string to make Phan happy.
		$entrypointUrl = (string)$this->urlUtils->expand( wfScript() );
		$linkToPageAtRevision = wfAppendQuery(
			$entrypointUrl,
			[ 'oldid' => $revision->getId() ]
		);
		$threadId = $incidentReport->getThreadId();
		if ( $threadId ) {
			// If a thread ID is defined, then add it to the link to the page at revision
			// as an anchor in the URL. Currently this is only provided by DiscussionTools.
			$linkToPageAtRevision .= '#' . urlencode( $threadId );
			// If the threadId starts with 'h-', then the threadId refers to a topic/section header
			// and as such the link prefix text should indicate this instead of saying it is a comment.
			if ( substr( $threadId, 0, 2 ) === 'h-' ) {
				$linkPrefixText = new MessageValue( 'reportincident-email-link-to-topic-prefix' );
			} else {
				$linkPrefixText = new MessageValue( 'reportincident-email-link-to-comment-prefix' );
			}
		} else {
			$linkPrefixText = new MessageValue( 'reportincident-email-link-to-page-prefix' );
		}
		return [ $linkPrefixText, $linkToPageAtRevision ];
	}

	/**
	 * Actually sends the email when provided with the
	 * 'to' email address, 'from' email addresses, the subject,
	 * and the body of the email.
	 *
	 * @param MailAddress[] $to
	 * @param MailAddress $from
	 * @param string $subject
	 * @param string $body
	 * @param IncidentReportEmailStatus $incidentReportEmailStatus Should be a status with no errors.
	 * @return IncidentReportEmailStatus
	 */
	private function actuallySendEmail(
		array $to,
		MailAddress $from,
		string $subject,
		string $body,
		IncidentReportEmailStatus $incidentReportEmailStatus
	) {
		// Call IEmailer::send and merge the status returned with the
		// existing $incidentReportEmailStatus status.
		$incidentReportEmailStatus->merge(
			$this->emailer->send(
				$to,
				$from,
				$subject,
				$body
			),
			true
		);
		// Add the email contents to $incidentReportEmailStatus
		$incidentReportEmailStatus->emailContents = [
			'to' => $to,
			'from' => $from,
			'subject' => $subject,
			'body' => $body,
		];
		if ( !$incidentReportEmailStatus->isGood() ) {
			// Log an error if the IEmailer::send method returns a fatal status.
			$this->logger->error(
				'Unable to send report incident email. IEmailer::send returned the following: {emailer-message}',
				[
					'emailer-message' => $incidentReportEmailStatus->getMessage( false, false, 'en' ),
				]
			);
		}
		return $incidentReportEmailStatus;
	}
}
