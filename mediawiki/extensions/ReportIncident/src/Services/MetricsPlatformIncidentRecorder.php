<?php

namespace MediaWiki\Extension\ReportIncident\Services;

use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\EventLogging\MetricsPlatform\MetricsClientFactory;
use MediaWiki\Extension\ReportIncident\IncidentReport;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;
use StatusValue;

/**
 * Records non-emergency reports to the Metrics Platform schema used for IRS interaction data.
 */
class MetricsPlatformIncidentRecorder implements IReportIncidentRecorder {

	public function __construct(
		private readonly MetricsClientFactory $metricsClientFactory,
		private readonly TitleFactory $titleFactory,
		private readonly UserFactory $userFactory,
	) {
	}

	public function record( IncidentReport $incidentReport ): StatusValue {
		// Don't record emergency reports in this implementation.
		// Eventually, this will be replaced by database storage,
		// which will equally record both kinds of reports (T345246).
		if ( $incidentReport->getIncidentType() === IncidentReport::THREAT_TYPE_IMMEDIATE ) {
			return StatusValue::newGood();
		}

		$reportedUser = $incidentReport->getReportedUser();

		$page = $incidentReport->getPage();

		$context = new DerivativeContext( $this->getContext() );
		$context->setTitle( $this->titleFactory->newFromPageReference( $page ) );
		$context->setUser( $this->userFactory->newFromUserIdentity( $incidentReport->getReportingUser() ) );

		$client = $this->metricsClientFactory->newMetricsClient( $context );

		$actionContext = [
			'type' => $incidentReport->getBehaviorType(),
			'reportedUserId' => $reportedUser ? $reportedUser->getId() : null,
		];
		$details = $incidentReport->getSomethingElseDetails();
		if ( $details ) {
			$actionContext['somethingElseDetails'] = substr( $details, 0, 200 );
		}

		$client->submitInteraction(
			'mediawiki.product_metrics.incident_reporting_system_interaction',
			'/analytics/product_metrics/web/base/1.3.0',
			'submit',
			[
				'action_source' => 'api',
				'action_context' => json_encode( $actionContext ),
			]
		);

		return StatusValue::newGood();
	}

	/**
	 * Get the current global context. Useful for testing.
	 * @return IContextSource
	 */
	protected function getContext(): IContextSource {
		return RequestContext::getMain();
	}
}
