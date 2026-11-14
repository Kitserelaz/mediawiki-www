<?php

namespace MWStake\MediaWiki\Component\Events\Tests\Unit;

use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MWStake\MediaWiki\Component\Events\INotificationEvent;

class DummySubEvent extends DummyEvent implements INotificationEvent {

	/**
	 * @return User
	 */
	public function getAgent(): UserIdentity {
		return $this->user;
	}

	/**
	 * @inheritDoc
	 */
	public function getKey(): string {
		return 'dummy-sub-event';
	}

	/**
	 * @inheritDoc
	 */
	public function hasPriorityOver(): array {
		return [];
	}
}
