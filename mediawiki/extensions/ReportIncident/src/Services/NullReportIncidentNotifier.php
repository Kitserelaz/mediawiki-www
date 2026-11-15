<?php
namespace MediaWiki\Extension\ReportIncident\Services;

use MediaWiki\Extension\ReportIncident\IncidentReport;
use StatusValue;

/**
 * A no-op incident notifier implementation.
 */
class NullReportIncidentNotifier implements IReportIncidentNotifier {

	public function notify( IncidentReport $incidentReport ): StatusValue {
		return StatusValue::newGood();
	}
}
