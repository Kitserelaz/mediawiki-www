<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';
class PrepareProvisioningMetadata extends MediaWiki\Maintenance\Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addOption('target', 'New wiki database', true, true);
    }
    public function execute() {
        $wiki = $this->getOption('target');
        require_once __DIR__ . '/dispatch.php';
        if (!FarmDispatch::validWiki($wiki)) { throw new RuntimeException('Invalid provisioning target'); }
        $services = $this->getServiceContainer();
        $services->get('CreateWikiDataStore')->resetDatabaseLists(true);
        $services->get('ManageWikiDataStoreFactory')->newInstance($wiki)->resetWikiData(true);
        if (!farmWikiMetadataIsComplete($wiki)) {
            throw new RuntimeException('Incomplete provisioning metadata for ' . $wiki);
        }
        @unlink('/var/www/mediawiki/cw_cache/config-' . $wiki . '.php');
    }
}
return PrepareProvisioningMetadata::class;
