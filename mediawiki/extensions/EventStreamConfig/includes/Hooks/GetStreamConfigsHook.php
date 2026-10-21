<?php

namespace MediaWiki\Extension\EventStreamConfig\Hooks;

/**
 * @stable for implementation
 * @ingroup Hooks
 */
interface GetStreamConfigsHook {

	/**
	 * This hook is called by {@link StreamConfigsFactory}. It is called before the final
	 * collection of stream configs is constructed.
	 *
	 * Note well that initial value of the <code>$streamConfigs</code> is the empty map, not
	 * <code>$wgEventStreams</code>. <code>$streamConfigs</code> will be merged into the final
	 * collection of stream configs. If there are duplicate keys in <code>$wgEventStreams</code>
	 * and <code>$streamConfigs</code>, then those in <code>$wgEventStreams</code> are used.
	 *
	 * @since 1.42
	 *
	 * @param array<string,array> &$streamConfigs
	 * @return void This hook must not abort and it must return no value
	 */
	public function onGetStreamConfigs( array &$streamConfigs ): void;
}
