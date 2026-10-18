<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';
class DockerSearchCheck extends MediaWiki\Maintenance\Maintenance {
    public function execute() {
        $engine = $this->getServiceContainer()->getSearchEngineFactory()->create();
        if (!($engine instanceof CirrusSearch\CirrusSearch)) { throw new RuntimeException('CirrusSearch is not active'); }
        $results = $engine->searchText('MediaWiki');
        if ($results instanceof MediaWiki\Status\Status) {
            if (!$results->isOK()) { throw new RuntimeException($results->getWikiText()); }
            $results = $results->getValue();
        }
        if (!$results || $results->getTotalHits() < 1) { throw new RuntimeException('The seeded main page was not found in Elasticsearch'); }
        $this->output('CirrusSearch queried Elasticsearch and found the seeded main page.' . "\n");
    }
}
return DockerSearchCheck::class;
