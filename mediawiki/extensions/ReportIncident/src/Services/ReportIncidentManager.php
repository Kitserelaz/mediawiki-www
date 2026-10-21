<?php

namespace MediaWiki\Extension\ReportIncident\Services;

use MediaWiki\Extension\ReportIncident\IncidentReport;
use MediaWiki\Extension\ReportIncident\IncidentReportEmailStatus;
use StatusValue;

/**
 * Manage IncidentReport objects and do things:
 * - Email recipients with the report contents
 * - ...
 */
class ReportIncidentManager {
	private ReportIncidentMailer $incidentMailer;

	/**
	 * @param ReportIncidentMailer $incidentMailer
	 */
	public function __construct( ReportIncidentMailer $incidentMailer ) {
		$this->incidentMailer = $incidentMailer;
	}

	/**
	 * @param IncidentReport $incidentReport
	 * @return StatusValue
	 */
	public function record( IncidentReport $incidentReport ): StatusValue {
		// For now, this is a no-op.
		// Eventually we would store the reports in DB (T345246)
		return StatusValue::newGood();
	}

	/**
	 * @param IncidentReport $incidentReport
	 * @return IncidentReportEmailStatus
	 */
	public function notify( IncidentReport $incidentReport ): StatusValue {
		return $this->incidentMailer->sendEmail( $incidentReport );
	}

}
