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
	private HookContainer $hookContainer;

	public function __construct( HookContainer $hookContainer ) {
		$this->hookContainer = $hookContainer;
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
