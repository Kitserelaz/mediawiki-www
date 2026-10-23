<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';
class AttachSeedAdmin extends MediaWiki\Maintenance\Maintenance {
    public function execute() {
        if (getenv('MW_ENV') === 'production' && getenv('MW_FRESH_PRODUCTION') !== '1') { throw new RuntimeException('Production seeding requires explicit fresh-production initialization'); }
        $name = getenv('ADMIN_USER') ?: 'Admin';
        $password = trim(file_get_contents('/run/secrets/admin-password'));
        $services = $this->getServiceContainer();
        $central = MediaWiki\Extension\CentralAuth\User\CentralAuthUser::getPrimaryInstanceByName($name);
        if (!$central->exists() || !$central->getPasswordObject()->verify($password)) { throw new RuntimeException('Seed global account does not match seed credentials'); }
        $attached = $central->queryAttachedBasic();
        foreach (['metawiki', 'loginwiki', 'testwiki'] as $wiki) {
            if (isset($attached[$wiki])) { continue; }
            $db = $services->getConnectionProvider()->getPrimaryDatabase($wiki);
            $hash = $db->newSelectQueryBuilder()->select('user_password')->from('user')->where(['user_name' => $name])->caller(__METHOD__)->fetchField();
            if (!$hash || !$services->getPasswordFactory()->newFromCiphertext($hash)->verify($password)) { throw new RuntimeException("Seed account mismatch on $wiki"); }
            $central->attach($wiki, 'admin', false);
        }
        // Core initialization already grants sysop/bureaucrat; do not recreate retired roles.
        // Invalidate the live shared cache after bootstrap operations used CACHE_NONE.
        $central->invalidateCache();
        $this->output("Seed administrator attached across the seeded wikis.\n");
    }
}
return AttachSeedAdmin::class;
