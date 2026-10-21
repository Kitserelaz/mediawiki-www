<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';

class VerifyFarmVirtualDomains extends MediaWiki\Maintenance\Maintenance {
    public function execute() {
        $provider = $this->getServiceContainer()->getConnectionProvider();
        foreach ([
            'virtual-createwiki' => 'wikidb',
            'virtual-createwiki-central' => 'metawiki',
            'virtual-managewiki' => 'wikidb',
            'virtual-centralauth' => 'centralauth',
            'virtual-botpasswords' => 'centralauth',
            'virtual-globalblocking' => 'centralauth',
            'virtual-oathauth' => 'centralauth',
            'virtual-importdump' => 'metawiki',
        ] as $domain => $expected) {
            $db = $provider->getReplicaDatabase($domain);
            if ($db->getDBname() !== $expected) {
                throw new RuntimeException("Upgrade preflight: $domain must map to $expected");
            }
            // Establish that the configured connection is reachable without
            // reading fields introduced by the pending schema update.
            $db->newSelectQueryBuilder()->select('1')->caller(__METHOD__)->fetchField();
        }
        $this->output("Shared metadata and central request virtual-domain mappings verified.\n");
    }
}
return VerifyFarmVirtualDomains::class;
