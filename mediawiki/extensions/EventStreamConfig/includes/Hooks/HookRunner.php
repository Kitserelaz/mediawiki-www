<?php

namespace MediaWiki\Extension\EventStreamConfig\Hooks;

use MediaWiki\HookContainer\HookContainer;

/**
 * This is a hook runner class, see docs/Hooks.md in core.
 * @internal
 */
class HookRunner
	implements GetStreamConfigsHook
{
	public function __construct( private readonly HookContainer $hookContainer ) {
	}

	/**
	 * @inheritDoc
	 */
	public function onGetStreamConfigs( array &$streamConfigs ): void {
		$this->hookContainer->run(
			'GetStreamConfigs',
			[ &$streamConfigs ],
			[
				'abortable' => false,
			]
		);
	}
}
