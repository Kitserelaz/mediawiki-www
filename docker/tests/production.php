<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';
class CheckFreshProduction extends MediaWiki\Maintenance\Maintenance {
    public function execute() {
        if (getenv('MW_ENV') !== 'production') { throw new RuntimeException('This check requires production configuration'); }
        $config = $this->getConfig();
        $domain = getenv('MW_DOMAIN');
        $wiki = $config->get('DBname');
        $expected = 'https://' . preg_replace('/wiki$/', '', $wiki) . '.' . $domain;
        if ($config->get('Server') !== $expected || $config->get('GlobalUserPageAPIUrl') !== 'https://meta.' . $domain . '/api.php') {
            throw new RuntimeException('Production canonical URLs do not use the configured domain');
        }
        if ($config->get('EnableEmail')) { throw new RuntimeException('Fresh production email must remain disabled until SMTP is configured'); }
        $name = getenv('ADMIN_USER') ?: 'Admin';
        $central = MediaWiki\Extension\CentralAuth\User\CentralAuthUser::getPrimaryInstanceByName($name);
        if (!$central->exists() || count(array_intersect(array_keys($central->queryAttachedBasic()), ['metawiki','loginwiki','testwiki'])) !== 3) {
            throw new RuntimeException('Production administrator is not attached to all seed wikis');
        }
        $user = $this->getServiceContainer()->getUserFactory()->newFromName($name);
        if (!$user || !$this->getServiceContainer()->getPermissionManager()->userHasRight($user, 'edit')) {
            throw new RuntimeException('Production administrator lacks persisted editing rights');
        }
        if ($wiki === 'metawiki') {
            if (!in_array('steward', $this->getServiceContainer()->getUserGroupManager()->getUserGroups($user), true)) {
                throw new RuntimeException('Fresh administrator lacks the farm management role');
            }
            foreach (['createwiki', 'managewiki-core', 'managewiki-permissions'] as $right) {
                if (empty($config->get('GroupPermissions')['steward'][$right])) {
                    throw new RuntimeException('Farm management role lacks configured permission: ' . $right);
                }
            }
            if (!in_array('steward', $config->get('OATHRequiredForGroups'), true)) {
                throw new RuntimeException('Production farm management must retain its existing two-factor requirement');
            }
        }
        if (!is_file('/var/www/mediawiki/cw_cache/.fresh-production-complete')) { throw new RuntimeException('Production initialization has no completion marker'); }
        $this->output("Fresh production domains, disabled email, attached administrator and editing permissions verified.\n");
    }
}
return CheckFreshProduction::class;
