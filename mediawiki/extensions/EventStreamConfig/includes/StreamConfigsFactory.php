<?php

namespace MediaWiki\Extension\EventStreamConfig;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\EventStreamConfig\Hooks\HookRunner;
use MediaWiki\HookContainer\HookContainer;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
class StreamConfigsFactory {

	/**
	 * Name of the main config key(s) for stream configuration.
	 *
	 * @var array
	 */
	public const CONSTRUCTOR_OPTIONS = [
		'EventStreams',
		'EventStreamsDefaultSettings',
	];

	private HookRunner $hookRunner;

	public function __construct(
		private readonly ServiceOptions $options,
		HookContainer $hookContainer,
		private readonly LoggerInterface $logger,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->hookRunner = new HookRunner( $hookContainer );
	}

	/**
	 * Create a new {@link StreamConfigs} instance.
	 *
	 * Stream configs are fetched from the following sources:
	 *
	 * 1. <code>$wgEventStreams</code>
	 * 2. {@link GetStreamConfigsHook}
	 *
	 * If there are duplicate keys in <code>$wgEventStreams</code> and <code>$streamConfigs</code>,
	 * then those in <code>$wgEventStreams</code> are used and those in <code>$streamConfigs</code>
	 * are discarded.
	 */
	public function getInstance(): StreamConfigs {
		$streamConfigs = [];
		$this->hookRunner->onGetStreamConfigs( $streamConfigs );

		$streamConfigs = array_merge(
			$streamConfigs,
			$this->options->get( 'EventStreams' )
		);

		return new StreamConfigs(
			$streamConfigs,
			$this->options->get( 'EventStreamsDefaultSettings' ),
			$this->logger
		);
	}
}
