<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use Miraheze\CreateWiki\Hooks\CreateWikiTablesHook;

final class CustomDomainSchema implements LoadExtensionSchemaUpdatesHook, CreateWikiTablesHook {
	public function onLoadExtensionSchemaUpdates( $updater ) {
		foreach ( [ 'cw_custom_domain_state', 'cw_custom_domain_claims' ] as $table ) {
			$updater->addExtensionUpdateOnVirtualDomain( [
				'virtual-createwiki', 'addTable', $table, __DIR__ . "/../../sql/$table.sql", true,
			] );
		}
	}

	public function onCreateWikiTables( array &$tables ): void {
		$tables['cw_custom_domain_state'] = 'cd_wiki';
		$tables['cw_custom_domain_claims'] = 'cdc_wiki';
	}
}
