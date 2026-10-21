<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';

class VerifyLegacyLinkTargets extends MediaWiki\Maintenance\Maintenance {
    public function execute() {
        if (getenv('MW_ENV') !== 'development' || $this->getConfig()->get('DBname') !== 'testwiki') {
            throw new RuntimeException('Legacy query fixtures require the explicit development test wiki');
        }
        $services = $this->getServiceContainer();
        $user = $services->getUserFactory()->newFromName('Admin');
        if (!$user || !$user->getId()) { throw new RuntimeException('Missing isolated administrator fixture'); }
        $fixture = [
            'Category:Upgrade_schema_146_parent' => 'Upgrade schema fixture.',
            'Category:Upgrade_schema_146_child' => 'Upgrade schema fixture. [[Category:Upgrade_schema_146_parent]]',
            'Upgrade_schema_146_article' => 'Upgrade schema fixture. [[Category:Upgrade_schema_146_child]]',
            'Upgrade_schema_146_redirect' => '#REDIRECT [[Upgrade_schema_146_article]]',
            'Upgrade_schema_146_seed' => 'Upgrade schema fixture. [[Category:Seed data]]',
        ];
        $titles = [];
        foreach ($fixture as $name => $text) {
            $title = MediaWiki\Title\Title::newFromText($name);
            $page = $services->getWikiPageFactory()->newFromTitle($title);
            if ($title->exists()) {
                if ($page->getRevisionRecord()->getContent('main')->getText() !== $text) {
                    throw new RuntimeException('Refusing to overwrite a non-fixture page: ' . $name);
                }
            } else {
                $updater = $page->newPageUpdater($user);
                $updater->setContent('main', new MediaWiki\Content\WikitextContent($text));
                $updater->saveRevision(MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment('Upgrade linktarget fixture'), EDIT_NEW);
                if (!$updater->wasSuccessful()) { throw new RuntimeException('Fixture save failed: ' . $name); }
            }
            $titles[$name] = $title;
        }
        MediaWiki\Deferred\DeferredUpdates::doUpdates();
        $services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
        $db = $services->getConnectionProvider()->getReplicaDatabase();
        if ($db->fieldExists('categorylinks', 'cl_to', __METHOD__)) {
            throw new RuntimeException('This test must exercise the new categorylinks schema');
        }
        $categories = DTViewXML::getCategoriesList();
        foreach (['Upgrade_schema_146_parent', 'Upgrade_schema_146_child'] as $category) {
            if (!in_array($category, $categories, true)) { throw new RuntimeException('Category enumeration lost ' . $category); }
        }
        $pages = DTViewXML::getPagesForCategory('Upgrade_schema_146_parent', 2);
        if (array_map(static fn($title) => $title->getDBkey(), $pages) !== ['Upgrade_schema_146_article'] ||
            DTViewXML::getPagesForCategory('Upgrade_schema_146_parent', 1) !== []) {
            throw new RuntimeException('Recursive category export selection or depth changed');
        }
        $GLOBALS['wgHighlightLinksInCategory'] = ['Upgrade_schema_146_child' => 'upgrade-category-class'];
        foreach ([false, true] as $follow) {
            $GLOBALS['wgHighlightLinksInCategoryFollowRedirects'] = $follow;
            $name = $follow ? 'Upgrade_schema_146_redirect' : 'Upgrade_schema_146_article';
            $colours = ['fixture' => ''];
            HighlightLinksInCategory::onGetLinkColours([$titles[$name]->getArticleID() => 'fixture'], $colours);
            if (trim($colours['fixture']) !== 'upgrade-category-class') {
                throw new RuntimeException('Category highlighting or redirect preference changed');
            }
        }
        // Include from a method: the standard maintenance guard will not execute
        // the demo seeder. Exercise only its non-mutating ownership query.
        require_once '/var/www/mediawiki/extensions/SemanticMediaWiki/src/Maintenance/DemoContentBuilder.php';
        require_once '/var/www/mediawiki/extensions/SemanticMediaWiki/maintenance/seedDemoData.php';
        $seed = new SMW\Maintenance\seedDemoData();
        $method = new ReflectionMethod($seed, 'isSeedPage');
        if (!$method->invoke($seed, $titles['Upgrade_schema_146_seed']) ||
            $method->invoke($seed, $titles['Upgrade_schema_146_article'])) {
            throw new RuntimeException('SMW seed-page ownership query changed');
        }
        $this->output("DataTransfer category export, category highlighting/redirects and SMW seed ownership queries passed on linktarget schema.\n");
    }
}
return VerifyLegacyLinkTargets::class;
