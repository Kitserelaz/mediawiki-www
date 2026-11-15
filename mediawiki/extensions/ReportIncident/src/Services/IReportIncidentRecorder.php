<?php

namespace MediaWiki\Extension\ReportIncident\Services;

use MediaWiki\Extension\ReportIncident\IncidentReport;
use StatusValue;

/**
 * Base interface for storage mechanisms used by the Incident Reporting System.
 */
interface IReportIncidentRecorder {
	/**
	 * Store an incident report to an external notification mechanism, such as a database.
	 * @param IncidentReport $incidentReport
	 * @return StatusValue Status holding the result of the storage operation.
	 */
	public function record( IncidentReport $incidentReport ): StatusValue;
}
