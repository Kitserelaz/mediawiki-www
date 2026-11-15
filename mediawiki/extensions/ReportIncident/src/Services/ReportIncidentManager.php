<?php

namespace MediaWiki\Extension\ReportIncident\Services;

use MediaWiki\Extension\ReportIncident\IncidentReport;
use StatusValue;

/**
 * Manage IncidentReport objects and do things:
 * - Create notifications for emergency reports
 */
class ReportIncidentManager {
	public function __construct(
		private readonly IReportIncidentNotifier $notifier,
		private readonly IReportIncidentRecorder $recorder,
	) {
	}

	/**
	 * @param IncidentReport $incidentReport
	 * @return StatusValue
	 */
	public function record( IncidentReport $incidentReport ): StatusValue {
		return $this->recorder->record( $incidentReport );
	}

	public function notify( IncidentReport $incidentReport ): StatusValue {
		return $this->notifier->notify( $incidentReport );
	}

}
