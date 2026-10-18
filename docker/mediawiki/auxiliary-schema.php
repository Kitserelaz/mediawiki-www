<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';
class AuxiliarySchema extends MediaWiki\Maintenance\Maintenance {
    public function execute() {
        // AntiSpoof and GlobalPreferences store auxiliary tables beside global accounts.
        // Run extension updates only; do not impose core wiki tables on this shared database.
        $updater = MediaWiki\Installer\DatabaseUpdater::newForDB($this->getDB(DB_PRIMARY), true, $this);
        $updater->doUpdates(['extensions']);
    }
}
return AuxiliarySchema::class;
