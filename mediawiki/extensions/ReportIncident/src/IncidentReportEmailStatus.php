<?php

namespace MediaWiki\Extension\ReportIncident;

use MediaWiki\Status\Status;

class IncidentReportEmailStatus extends Status {
	public array $emailContents = [];

	/**
	 * Gets the contents of the email that was sent using
	 * IEmailer::send. Will be the empty array if no
	 * attempt at sending an email was made (in the case
	 * of the configuration values used for sending an
	 * email being invalid).
	 *
	 * @return array
	 */
	public function getEmailContents(): array {
		return $this->emailContents;
	}
}
