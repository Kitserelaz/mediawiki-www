<?php

use MediaWiki\Maintenance\Maintenance;
use WikiOasis\WikiOasisMagic\CustomDomainConfig;

require '/var/www/mediawiki/maintenance/Maintenance.php';

final class ProcessCustomDomains extends Maintenance {
	public function execute() {
		if ( CustomDomainConfig::routingEnabled() ) {
			$this->getServiceContainer()->get( 'SkyWikiCustomDomains' )->run();
		}
	}
}

return ProcessCustomDomains::class;
