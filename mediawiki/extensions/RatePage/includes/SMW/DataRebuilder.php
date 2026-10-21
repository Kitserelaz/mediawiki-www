<?php

namespace RatePage\SMW;

use ExtensionRegistry;
use MediaWiki\MediaWikiServices;
use SMW\ApplicationFactory;
use SMW\Options;
use SMW\Store;
use SMW\StoreFactory;
use Title;

class DataRebuilder {
	/**
	 * Rebuild the Semantic MediaWiki data for the given page.
	 *
	 * @param Title $title
	 */
	public function rebuildSemanticData( Title $title ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'SemanticMediaWiki' ) ||
			!MediaWikiServices::getInstance()->getMainConfig()->get( 'RPImmediateSMWUpdate' )
		) {
			return;
		}

		$store = StoreFactory::getStore();
		$store->setOption( Store::OPT_CREATE_UPDATE_JOB, false );

		$rebuilder = new \SMW\Maintenance\DataRebuilder(
			$store,
			ApplicationFactory::getInstance()->newTitleFactory()
		);

		$rebuilder->setOptions(
			// Tell SMW to only rebuild the current page
			new Options( [ 'page' => $title ] )
		);

		$rebuilder->rebuild();
	}
}