<?php

namespace MWStake\MediaWiki\Component\Events\Tests\Unit;

use DateTime;
use MediaWiki\Language\RawMessage;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MWStake\MediaWiki\Component\Events\Delivery\IChannel;
use MWStake\MediaWiki\Component\Events\INotificationEvent;

class DummyEvent implements INotificationEvent {

	/** @var int */
	private $id = 0;

	/** @var User */
	protected $user;

	/**
	 * @param User $user
	 */
	public function __construct( User $user ) {
		$this->user = $user;
	}

	/**
	 * @inheritDoc
	 */
	public function getKey(): string {
		return 'dummy-event';
	}

	/**
	 * @inheritDoc
	 */
	public function getAgent(): UserIdentity {
		return $this->user;
	}

	/**
	 * @inheritDoc
	 */
	public function getTime(): DateTime {
		return new DateTime();
	}

	/**
	 * @inheritDoc
	 */
	public function getPresetSubscribers(): array {
		return [];
	}

	/**
	 * @inheritDoc
	 */
	public function getId() {
		return $this->id;
	}

	/**
	 * @inheritDoc
	 */
	public function setId( $id ) {
		$this->id = $id;
	}

	/**
	 * @inheritDoc
	 */
	public function getMessage( IChannel $forChannel ): Message {
		return new RawMessage( 'dummy' );
	}

	/**
	 * @inheritDoc
	 */
	public function getIcon(): string {
		return '';
	}

	public function getLinksIntroMessage( IChannel $forChannel ): ?Message {
		return null;
	}

	public function setTime( DateTime $time ): void {
	}

	/**
	 * @inheritDoc
	 */
	public function getLinks( IChannel $forChannel ): array {
		return [];
	}

	/**
	 * @inheritDoc
	 */
	public function getKeyMessage(): Message {
		return new RawMessage( 'Dummy event' );
	}

	/**
	 * @inheritDoc
	 */
	public function getGroupMessage( int $count, IChannel $forChannel ): Message {
		return $this->getMessage( $forChannel );
	}

	/**
	 * @inheritDoc
	 */
	public function hasPriorityOver(): array {
		return [
			'dummy-sub-event'
		];
	}

	public static function getArgsForTesting(
		UserIdentity $agent, MediaWikiServices $services, array $extra = []
	): array {
		return [];
	}
}
