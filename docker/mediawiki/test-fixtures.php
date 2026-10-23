<?php
// Match web/worker ownership when called through the root management service.
if (posix_geteuid() === 0) { posix_setgid(33); posix_setuid(33); }
require '/var/www/mediawiki/maintenance/Maintenance.php';
class DockerFixtures extends MediaWiki\Maintenance\Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addOption('verify-permissions', 'Check browser fixture permissions in a fresh process');
    }

    public function execute() {
        if (getenv('MW_ENV') === 'production') { throw new RuntimeException('Development test fixtures only'); }
        $services = $this->getServiceContainer();
        $wiki = $this->getConfig()->get('DBname');
        if ($wiki !== $this->getConfig()->get('CreateWikiGlobalWiki')) {
            throw new RuntimeException('Browser fixtures must run on the central wiki');
        }
        $admin = $services->getUserFactory()->newFromName(getenv('ADMIN_USER') ?: 'Admin');
        if (!$admin || !$admin->getId()) { throw new RuntimeException('Seed administrator is missing'); }
        $group = 'docker-browser-fixture';
        if ($this->hasOption('verify-permissions')) {
            if (($this->getConfig()->get('GroupPermissions')[$group] ?? []) !== ['createwiki' => true]
                || !in_array($group, $services->getUserGroupManager()->getUserGroups($admin), true)
                || !$services->getPermissionManager()->userHasRight($admin, 'createwiki')) {
                throw new RuntimeException('Browser fixture administrator lacks its isolated CreateWiki grant');
            }
            $this->output("Isolated browser fixture CreateWiki permission verified.\n");
            return;
        }
        $prefix = getenv('DOCKER_FIXTURE_PREFIX') ?: 'docker';
        if (!preg_match('/^[a-z0-9]{1,31}$/D', $prefix)) { throw new RuntimeException('Invalid test prefix'); }
        // Persist only in this development farm; never seed or restore steward.
        $permissions = $services->get('ManageWikiModuleFactory')->permissions($wiki);
        $permissions->modify($group, ['permissions' => ['add' => ['createwiki']]]);
        $permissions->commit();
        if ($permissions->getErrors()) { throw new RuntimeException('Could not configure browser fixture permission'); }
        $services->getUserGroupManager()->addUserToGroup($admin, $group);
        foreach ([$prefix . 'smokewiki' => false, $prefix . 'privatewiki' => true] as $wiki => $private) {
            $manager = $services->get('WikiManagerFactory')->newInstance($wiki);
            if ($manager->exists()) { continue; }
            $error = $manager->create($wiki, 'en', $private, 'uncategorised', getenv('ADMIN_USER') ?: 'Admin', getenv('ADMIN_USER') ?: 'Admin', 'Docker integration fixture', []);
            if ($error !== null) { throw new RuntimeException($error); }
        }
        $uploadRoot = rtrim(getenv('MW_UPLOAD_ROOT') ?: '/var/www/mediawiki/images', '/');
        @mkdir($uploadRoot . '/' . $prefix . 'privatewiki', 0775, true);
        file_put_contents($uploadRoot . '/' . $prefix . 'privatewiki/docker-access-test.txt', 'Private development fixture');
        $this->output("Public and private wiki fixtures created through CreateWiki.\n");
    }
}
return DockerFixtures::class;
