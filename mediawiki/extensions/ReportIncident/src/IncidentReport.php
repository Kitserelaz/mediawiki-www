<?php

namespace MediaWiki\Extension\ReportIncident;

use MediaWiki\Page\PageReference;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;
use Wikimedia\Assert\Assert;

/**
 * Plain value object containing incident report data.
 */
class IncidentReport {
	public const THREAT_TYPE_IMMEDIATE = 'immediate-threat-physical-harm';
	public const THREAT_TYPE_UNACCEPTABLE_BEHAVIOR = 'unacceptable-user-behavior';

	public function __construct(
		private readonly UserIdentity $reportingUser,
		private readonly ?UserIdentity $reportedUser,
		private readonly ?RevisionRecord $revisionRecord,
		private readonly PageReference $page,
		private readonly string $incidentType,
		private readonly ?string $behaviorType,
		private readonly ?string $physicalHarmType,
		private readonly ?string $somethingElseDetails = null,
		private readonly ?string $details = null,
		private readonly ?string $threadId = null,
	) {
		$page->assertWiki( PageReference::LOCAL );

		Assert::parameter(
			$revisionRecord === null || $revisionRecord->getPage()->isSamePageAs( $page ),
			'$revision',
			'The given revision must match the given page',
		);
	}

	/**
	 * Known values for the 'behaviorType' field.
	 * This should match the list of allowed values for "unacceptable behavior" reports on the frontend.
	 *
	 * @return string[]
	 */
	public static function behaviorTypes(): array {
		return [
			'doxing',
			'hate-or-discrimination',
			'intimidation',
			'sexual-harassment',
			'spam',
			'trolling',
			'something-else',
			'sockpuppetry',
			'vandalism',
			'userdispute',
			'disruptiveediting',
			'other'
		];
	}

	public static function newFromRestPayload(
		UserIdentity $reportingUser,
		array $data
	): IncidentReport {
		return new self(
			$reportingUser,
			$data['reportedUser'],
			$data['revision'] ?? null,
			$data['page'],
			$data['incidentType'],
			$data['behaviorType'] ?? null,
			$data['physicalHarmType'] ?? null,
			$data['somethingElseDetails'] ?? null,
			$data['details'] ?? null,
			$data['threadId'] ?? null
		);
	}

	public function getReportingUser(): UserIdentity {
		return $this->reportingUser;
	}

	public function getIncidentType(): string {
		return $this->incidentType;
	}

	public function getBehaviorType(): ?string {
		return $this->behaviorType;
	}

	public function getPhysicalHarmType(): ?string {
		return $this->physicalHarmType;
	}

	public function getDetails(): ?string {
		return $this->details;
	}

	public function getRevisionRecord(): ?RevisionRecord {
		return $this->revisionRecord;
	}

	public function getPage(): PageReference {
		return $this->page;
	}

	public function getReportedUser(): ?UserIdentity {
		return $this->reportedUser;
	}

	public function getSomethingElseDetails(): ?string {
		return $this->somethingElseDetails;
	}

	public function getThreadId(): ?string {
		return $this->threadId;
	}
}
