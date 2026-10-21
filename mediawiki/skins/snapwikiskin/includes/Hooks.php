<?php
namespace Snapwikiskin;

use Config;
use MediaWiki\ResourceLoader\Context;

class Hooks {
	/**
	 * @param ResourceLoaderContext $context
	 * @param Config $config
	 * @return $wgSnapwikiskinWvuiSearchOptions
	 */
	public static function getSnapwikiskinWvuiSearchResourceLoaderConfig(
		Context $context,
		Config $config
	) {
		return $config->get( 'SnapwikiskinWvuiSearchOptions' );
	}
}
