<?php

namespace MediaWiki\Extension\ReportIncident;

use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;

/**
 * Plain value object containing incident report data.
 */
class IncidentReport {
	private UserIdentity $reportingUser;
	private UserIdentity $reportedUser;
	private RevisionRecord $revisionRecord;
	private array $behaviors;
	private ?string $somethingElseDetails;
	private ?string $details;
	private ?string $threadId;

	/**
	 * @param UserIdentity $reportingUser
	 * @param UserIdentity $reportedUser
	 * @param RevisionRecord $revisionRecord
	 * @param array $behaviors
	 * @param string|null $somethingElseDetails
	 * @param string|null $details
	 * @param string|null $threadId
	 */
	public function __construct(
		UserIdentity $reportingUser,
		UserIdentity $reportedUser,
		RevisionRecord $revisionRecord,
		array $behaviors,
		?string $somethingElseDetails = null,
		?string $details = null,
		?string $threadId = null
	) {
		$this->reportingUser = $reportingUser;
		$this->reportedUser = $reportedUser;
		$this->revisionRecord = $revisionRecord;
		$this->behaviors = $behaviors;
		$this->somethingElseDetails = $somethingElseDetails;
		$this->details = $details;
		$this->threadId = $threadId;
	}

	public static function newFromRestPayload(
		UserIdentity $reportingUser,
		array $data
	): IncidentReport {
		return new self(
			$reportingUser,
			$data['reportedUser'],
			$data['revision'],
			$data['behaviors'],
			$data['somethingElseDetails'] ?? null,
			$data['details'] ?? null,
			$data['threadId'] ?? null
		);
	}

	public function getReportingUser(): UserIdentity {
		return $this->reportingUser;
	}

	public function getBehaviors(): array {
		return $this->behaviors;
	}

	public function getDetails(): ?string {
		return $this->details;
	}

	public function getRevisionRecord(): RevisionRecord {
		return $this->revisionRecord;
	}

	public function getReportedUser(): UserIdentity {
		return $this->reportedUser;
	}

	public function getSomethingElseDetails(): ?string {
		return $this->somethingElseDetails;
	}

	public function getThreadId(): ?string {
		return $this->threadId;
	}
}
