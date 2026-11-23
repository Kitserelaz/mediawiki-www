<?php

use MediaWiki\Maintenance\Maintenance;
use WikiOasis\WikiOasisMagic\CustomDomainConfig;

require (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/maintenance/Maintenance.php');

final class ProcessCustomDomains extends Maintenance {
	public function execute() {
		if ( CustomDomainConfig::routingEnabled() ) {
			$this->getServiceContainer()->get( 'SkyWikiCustomDomains' )->run();
		}
	}
}

return ProcessCustomDomains::class;
