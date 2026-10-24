<?php
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Maintenance\LoggedUpdateMaintenance;
use MediaWiki\Maintenance\LoggedUpdateOutcome;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Miraheze\CreateWiki\Maintenance\PopulateMainPage;
use Miraheze\CreateWiki\Maintenance\SetContainersAccess;

// Runs inside the target updater: completion includes the creator's former
// deferred work, without a second MediaWiki bootstrap or more PHP processes.
class FarmFinalizeCreation extends LoggedUpdateMaintenance {
    protected function getUpdateKey() {
        return 'farm-finalize-creation-v1';
    }

    protected function doDBUpdates(): LoggedUpdateOutcome {
        $wiki = $this->getConfig()->get('DBname');
        $raw = FarmDispatch::connect()->hGet(FarmDispatch::CREATION_CONTEXT, $wiki);
        $creation = json_decode($raw ?: '', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($creation) || !is_string($creation['requester'] ?? null) ||
            !is_array($creation['extra'] ?? null)) {
            throw new RuntimeException('Missing or invalid creation context for ' . $wiki);
        }
        $services = $this->getServiceContainer();
        $this->createChild(SetContainersAccess::class)->execute();

        // A retry must never replace an existing main page, including user edits.
        $title = Title::newMainPage();
        $db = $this->getPrimaryDB();
        $pageExists = $db->newSelectQueryBuilder()->select('page_latest')->from('page')
            ->where(['page_namespace'=>$title->getNamespace(), 'page_title'=>$title->getDBkey()])
            ->caller(__METHOD__)->fetchField();
        if (!$pageExists) {
            $this->createChild(PopulateMainPage::class)->execute();
            if (!$db->newSelectQueryBuilder()->select('page_latest')->from('page')
                ->where(['page_namespace'=>$title->getNamespace(), 'page_title'=>$title->getDBkey()])
                ->caller(__METHOD__)->fetchField()) {
                throw new RuntimeException('Main page creation failed for ' . $wiki);
            }
        }

        if (ExtensionRegistry::getInstance()->isLoaded('CentralAuth')) {
            $user = $services->getUserFactory()->newFromName($creation['requester']);
            if (!$user) {
                throw new RuntimeException('Invalid creation requester');
            }
            if (!$user->getId()) {
                $performer = new UltimateAuthority(User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal'=>true]));
                $status = CentralAuthServices::getForcedLocalCreationService()
                    ->attemptAutoCreateLocalUserFromName($creation['requester'], $performer);
                if (!$status->isGood()) {
                    throw new RuntimeException('Could not attach the creation requester on ' . $wiki);
                }
            }
            $user = $services->getUserFactory()->newFromName($creation['requester']);
            if (!$user->getId() || !CentralAuthUser::getPrimaryInstance($user)->isAttached()) {
                throw new RuntimeException('Creation requester is not attached on ' . $wiki);
            }
            $promotion = $this->createChild(CreateAndPromote::class, '/var/www/mediawiki/maintenance/createAndPromote.php');
            $promotion->loadParamsAndArgs(__FILE__,
                ['force'=>true, 'bureaucrat'=>true, 'sysop'=>true, 'interface-admin'=>true],
                [$creation['requester']]);
            $promotion->execute();
            $groups = $services->getUserGroupManager()->getUserGroups($user);
            if (array_diff(['bureaucrat','sysop','interface-admin'], $groups)) {
                throw new RuntimeException('Creation requester groups are incomplete on ' . $wiki);
            }
        }
        if ($creation['extra']) {
            $services->getHookContainer()->run('CreateWikiAfterCreationWithExtraData',
                [$creation['extra'], $wiki], ['abortable'=>false]);
        }
        DeferredUpdates::doUpdates();
        $services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
        $this->output("Creation page, requester and permissions finalized: $wiki\n");
        return LoggedUpdateOutcome::COMPLETE;
    }
}
