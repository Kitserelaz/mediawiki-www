<?php

namespace MediaWiki\Extension\ReportIncident\Services;

use MediaWiki\Extension\ReportIncident\IncidentReport;
use StatusValue;

/**
 * Base interface for notification mechanisms used by the Incident Reporting System.
 */
interface IReportIncidentNotifier {
	/**
	 * Send an incident report to an external notification mechanism, such as a support system or email inbox.
	 * @param IncidentReport $incidentReport
	 * @return StatusValue Status holding the result of the notification creation process.
	 */
	public function notify( IncidentReport $incidentReport ): StatusValue;
}
