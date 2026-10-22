<?php
// Match web/worker ownership when called through the root management service.
if (posix_geteuid() === 0) { posix_setgid(33); posix_setuid(33); }
require '/var/www/mediawiki/maintenance/Maintenance.php';
class DockerFixtures extends MediaWiki\Maintenance\Maintenance {
    public function execute() {
        if (getenv('MW_ENV') === 'production') { throw new RuntimeException('Development test fixtures only'); }
        $prefix = getenv('DOCKER_FIXTURE_PREFIX') ?: 'docker';
        if (!preg_match('/^[a-z0-9]{1,31}$/D', $prefix)) { throw new RuntimeException('Invalid test prefix'); }
        foreach ([$prefix . 'smokewiki' => false, $prefix . 'privatewiki' => true] as $wiki => $private) {
            $manager = $this->getServiceContainer()->get('WikiManagerFactory')->newInstance($wiki);
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
