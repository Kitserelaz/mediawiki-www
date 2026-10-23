<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';

/** Explicit operator migration; never invoked automatically during initialization. */
class RetireStewardRole extends MediaWiki\Maintenance\Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addDescription('Audit or remove the retired steward group without modifying other groups.');
        $this->addOption('apply', 'Remove steward memberships and persisted role definitions');
    }

    public function execute() {
        $services = $this->getServiceContainer();
        $apply = $this->hasOption('apply');
        $wikiDb = $services->getConnectionProvider()->getPrimaryDatabase('virtual-createwiki');
        $wikis = $wikiDb->newSelectQueryBuilder()->select('wiki_dbname')->from('cw_wikis')
            ->caller(__METHOD__)->fetchFieldValues();
        $factory = $services->get('ManageWikiModuleFactory');
        $result = ['applied' => $apply, 'local_memberships' => [], 'saved_groups' => []];
        foreach ($wikis as $wiki) {
            $db = $services->getConnectionProvider()->getPrimaryDatabase($wiki);
            $ids = $db->newSelectQueryBuilder()->select('ug_user')->from('user_groups')
                ->where(['ug_group' => 'steward'])->caller(__METHOD__)->fetchFieldValues();
            $result['local_memberships'][$wiki] = count($ids);
            if ($apply) {
                $manager = $services->getUserGroupManagerFactory()->getUserGroupManager($wiki);
                $lookup = $services->getActorStoreFactory()->getUserIdentityLookup($wiki);
                foreach ($ids as $id) {
                    $user = $lookup->getUserIdentityByUserId((int)$id);
                    if (!$user || !$manager->removeUserFromGroup($user, 'steward')) {
                        throw new RuntimeException('Failed to remove a steward membership on ' . $wiki);
                    }
                }
            }
            $permissions = $factory->permissions($wiki);
            if ($permissions->exists('steward')) {
                $result['saved_groups'][] = $wiki;
                if ($apply) {
                    $permissions->remove('steward');
                    $permissions->commit();
                    if ($permissions->getErrors()) { throw new RuntimeException('ManageWiki rejected group removal'); }
                }
            }
        }
        $defaults = $factory->permissionsDefault();
        if ($defaults->exists('steward')) {
            $result['saved_groups'][] = 'default';
            if ($apply) {
                $defaults->remove('steward');
                $defaults->commit();
                if ($defaults->getErrors()) { throw new RuntimeException('ManageWiki rejected default group removal'); }
            }
        }
        $central = $services->getConnectionProvider()->getPrimaryDatabase('centralauth');
        $names = $central->newSelectQueryBuilder()->select('gu_name')->from('globaluser')
            ->join('global_user_groups', null, 'gu_id = gug_user')->where(['gug_group' => 'steward'])
            ->caller(__METHOD__)->fetchFieldValues();
        $result['global_memberships'] = count($names);
        if ($apply) {
            foreach ($names as $name) {
                MediaWiki\Extension\CentralAuth\User\CentralAuthUser::getPrimaryInstanceByName($name)
                    ->removeFromGlobalGroups('steward');
            }
            $status = MediaWiki\Extension\CentralAuth\CentralAuthServices::getGlobalGroupManager()->removeGroup('steward');
            if (!$status->isGood()) { throw new RuntimeException('CentralAuth rejected global group removal'); }
            $cache = $services->get('ManageWikiDataStoreFactory');
            foreach ($wikis as $wiki) { $cache->newInstance($wiki)->resetWikiData(true); }
        }
        $this->output(json_encode($result, JSON_THROW_ON_ERROR) . "\n");
    }
}
return RetireStewardRole::class;
