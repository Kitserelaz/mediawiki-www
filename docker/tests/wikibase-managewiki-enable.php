<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';

class EnableWikibaseMigrationFixture extends MediaWiki\Maintenance\Maintenance {
    public function execute() {
        if (getenv('MW_ENV') !== 'development' || getenv('MW_WIKIBASE_TEST_ALLOW_WRITES') !== '1' ||
            $this->getConfig()->get('DBname') !== 'testwiki') {
            throw new RuntimeException('Wikibase fixture requires an explicitly authorized disposable development testwiki');
        }
        $factory = $this->getServiceContainer()->get('ManageWikiModuleFactory');
        $extensions = $factory->extensions('testwiki');
        $required = ['wikibaserepository', 'universallanguageselector', 'wikibaseclient', 'wikibaselexeme'];
        $extensions->add($required);
        $extensions->commit();
        if ($extensions->getErrors()) {
            throw new RuntimeException('ManageWiki enablement failed: ' . json_encode($extensions->getErrors(), JSON_THROW_ON_ERROR));
        }
        $persisted = $factory->extensions('testwiki')->list();
        if (array_diff($required, $persisted)) {
            throw new RuntimeException('ManageWiki did not persist the enabled extensions');
        }
        $this->output("ManageWiki enabled Wikibase Repository, Client and Lexeme\n");
    }
}
return EnableWikibaseMigrationFixture::class;
