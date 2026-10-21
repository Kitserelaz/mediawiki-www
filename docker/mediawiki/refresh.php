<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';
class RefreshFarmCache extends MediaWiki\Maintenance\Maintenance {
    public function execute() {
        $services = $this->getServiceContainer();
        $db = $services->getConnectionProvider()->getPrimaryDatabase('virtual-createwiki');
        MirahezeFunctions::rebuildExtensionListCache();
        $services->get('CreateWikiDataStore')->resetDatabaseLists(true);
        $factory = $services->get('ManageWikiDataStoreFactory');
        foreach ($db->newSelectQueryBuilder()->select('wiki_dbname')->from('cw_wikis')->caller(__METHOD__)->fetchFieldValues() as $wiki) {
            $factory->newInstance($wiki)->resetWikiData(true);
        }
        $this->output("Farm metadata rebuilt.\n");
    }
}
return RefreshFarmCache::class;
