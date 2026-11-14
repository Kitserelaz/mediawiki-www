<?php

namespace MWStake\MediaWiki\Component\Events\Tests\Unit;

use MWStake\MediaWiki\Component\Events\INotificationEvent;
use MWStake\MediaWiki\Component\Events\INotificationEventConsumer;

class DummyConsumer implements INotificationEventConsumer {
	/**
	 * @param INotificationEvent $event
	 *
	 * @return void
	 */
	public function consume( INotificationEvent $event ): void {
		// STUB
	}

	/**
	 * @param INotificationEvent $event
	 *
	 * @return bool
	 */
	public function isInterested( INotificationEvent $event ): bool {
		return true;
	}
}
