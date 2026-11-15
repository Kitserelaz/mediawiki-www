<?php
namespace MediaWiki\Extension\ReportIncident\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\ReportIncident\IncidentReport;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;
use MediaWiki\Utils\UrlUtils;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

/**
 * Client class to create Zendesk requests from IRS incident reports.
 */
class ZendeskClient implements IReportIncidentNotifier {
	public const CONSTRUCTOR_OPTIONS = [
		'ReportIncidentZendeskHTTPProxy',
		'ReportIncidentZendeskUrl',
		'ReportIncidentZendeskSubjectLine',
		'Script'
	];

	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly ITextFormatter $textFormatter,
		private readonly UrlUtils $urlUtils,
		private readonly UserFactory $userFactory,
		private readonly TitleFactory $titleFactory,
		private readonly LoggerInterface $logger,
		private readonly ServiceOptions $serviceOptions,
	) {
		$serviceOptions->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * Create a Zendesk request for the given incident.
	 * @param IncidentReport $incidentReport
	 * @return StatusValue
	 */
	public function notify( IncidentReport $incidentReport ): StatusValue {
		$reportingUser = $incidentReport->getReportingUser();
		$reportingUserName = $reportingUser->getName();

		[ $linkPrefixText, $linkToPageAtRevision ] = $this->getLinkToReportedContent( $incidentReport );

		$physicalHarmType = $incidentReport->getPhysicalHarmType() ?? '';
		$reportedUser = $incidentReport->getReportedUser();

		$body = $this->textFormatter->format(
			new MessageValue(
				'reportincident-notification-message-body',
				[
					$reportingUserName,
					$reportedUser ? $reportedUser->getName() : '',
					$linkPrefixText,
					$linkToPageAtRevision,
					// Possible message keys used here:
					// * reportincident-threats-physical-harm
					// * reportincident-threats-self-harm
					// * reportincident-threats-public-harm
					new MessageValue( "reportincident-$physicalHarmType" ),
					$incidentReport->getDetails() ?? '',
				]
			)
		);

		// Refer to https://developer.zendesk.com/api-reference/ticketing/tickets/ticket-requests/#create-request
		// for the exact payload taken by the ticket creation endpoint.
		$payload = [
			'request' => [
				'requester' => [
					'name' => $reportingUserName,
					'email' => $this->userFactory->newFromUserIdentity( $reportingUser )->getEmail(),
				],
				// NOTE: This subject line is used to route the created ticket within Zendesk.
				// Do not change it without consulting with the Trust and Safety team.
				'subject' => $this->serviceOptions->get( 'ReportIncidentZendeskSubjectLine' ),
				'comment' => [
					'body' => $body,
				],
			],
		];

		$url = $this->serviceOptions->get( 'ReportIncidentZendeskUrl' ) . '/api/v2/requests.json';
		$requestOptions = [
			'method' => 'POST',
			'postData' => json_encode( $payload ),
			'proxy' => $this->serviceOptions->get( 'ReportIncidentZendeskHTTPProxy' ),
		];

		$request = $this->httpRequestFactory->create( $url, $requestOptions, __METHOD__ );
		$request->setHeader( 'Content-Type', 'application/json' );

		$response = $request->execute();

		if ( $response->isOK() ) {
			$this->logger->info( 'Zendesk request created' );
			return StatusValue::newGood();
		}

		// Attempt to parse Zendesk API errors if we get a JSON error response back with a 4xx status.
		// https://developer.zendesk.com/api-reference/introduction/requests/#400-range
		if (
			$request->getResponseHeader( 'Content-Type' ) === 'application/json' &&
			$request->getStatus() >= 400 &&
			$request->getStatus() < 500
		) {
			$errorJson = json_decode( $request->getContent() ?? '', true );
			if ( $errorJson !== null ) {
				$error = $errorJson['error'] ?? '';
				$description = $errorJson['description'] ?? '';
				$this->logger->error(
					"Zendesk error while creating request: \"$error\" ($description)",
					[ 'status' => $request->getStatus() ]
				);
				return StatusValue::newFatal( 'reportincident-unable-to-send' );
			}
		}

		$this->logger->error(
			'Unknown Zendesk error while creating request',
			[ 'status' => $request->getStatus() ]
		);

		return StatusValue::newFatal( 'reportincident-unable-to-send' );
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
		$linkPrefixText = new MessageValue( 'reportincident-notification-link-to-page-prefix' );
		$revision = $incidentReport->getRevisionRecord();

		if ( $revision === null ) {
			$title = $this->titleFactory->newFromPageReference( $incidentReport->getPage() );
			return [ $linkPrefixText, $title->getFullURL() ];
		}

		// In theory UrlUtils::expand() could return null, this seems pretty unlikely in practice;
		// cast to string to make Phan happy.
		$entrypointUrl = (string)$this->urlUtils->expand( $this->serviceOptions->get( 'Script' ) );
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
				$linkPrefixText = new MessageValue( 'reportincident-notification-link-to-topic-prefix' );
			} else {
				$linkPrefixText = new MessageValue( 'reportincident-notification-link-to-comment-prefix' );
			}
		}

		return [ $linkPrefixText, $linkToPageAtRevision ];
	}
}
