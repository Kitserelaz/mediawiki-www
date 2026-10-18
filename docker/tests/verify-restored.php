<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';
class VerifyRestoredFarm extends MediaWiki\Maintenance\Maintenance {
    public function execute() {
        $services = $this->getServiceContainer();
        $user = $services->getUserFactory()->newFromName(getenv('ADMIN_USER') ?: 'Admin');
        $rights = $services->getPermissionManager()->getUserPermissions($user);
        foreach (['read', 'edit', 'writeapi', 'upload'] as $right) {
            if (!in_array($right, $rights, true)) { throw new RuntimeException("Restored account lacks $right"); }
        }
        $title = MediaWiki\Title\Title::newFromText('Module:DockerSmoke');
        $page = $services->getWikiPageFactory()->newFromTitle($title);
        $content = $page->getContent();
        if (!$content || !str_contains($content->serialize(), 'docker-lua-ok')) { throw new RuntimeException('Restored module content is missing'); }
        if (!glob(rtrim(getenv('MW_UPLOAD_ROOT') ?: '/var/www/mediawiki/images', '/') . '/testwiki/*/*/DockerSmoke*.png')) { throw new RuntimeException('Restored upload is missing'); }
        $this->output("Restored permissions, module content and upload data verified.\n");
    }
}
return VerifyRestoredFarm::class;
