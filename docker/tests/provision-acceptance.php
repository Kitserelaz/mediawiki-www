<?php
// Stop only this test creator, inside the real post-commit preparation callback.
if (posix_geteuid() === 0) {
    posix_setgid(33);
    posix_setuid(33);
}
require '/var/www/mediawiki/maintenance/Maintenance.php';
class ProvisioningProductionAcceptance extends MediaWiki\Maintenance\Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addOption('target', 'Disposable wiki', true, true);
        $this->addOption('private', 'Create a private wiki');
        $this->addOption('pause', 'Stop during post-commit metadata preparation');
        $this->addOption('inspect-creation', 'Inspect page, requester account and permissions');
        $this->addOption('edit-main-page', 'Replace fixture content before a provisioning replay');
    }

    public function execute() {
        if (getenv('PROVISION_ACCEPTANCE_ISOLATED') !== '1' || getenv('MW_ENV') !== 'production' ||
            !str_ends_with(getenv('MW_DOMAIN') ?: '', '.example.test')) {
            throw new RuntimeException('Isolated reserved-domain production rehearsal required');
        }
        $wiki = $this->getOption('target');
        if (!preg_match('/^9provision[a-z0-9]+wiki$/D', $wiki)) {
            throw new RuntimeException('Invalid acceptance fixture');
        }
        $services = $this->getServiceContainer();
        if ($this->hasOption('inspect-creation')) {
            $user = $services->getUserFactory()->newFromName('Admin');
            $title = MediaWiki\Title\Title::newMainPage();
            $page = $services->getWikiPageFactory()->newFromTitle($title);
            if ($this->hasOption('edit-main-page')) {
                $editor = $page->newPageUpdater($user);
                $editor->setContent(MediaWiki\Revision\SlotRecord::MAIN,
                    new MediaWiki\Content\WikitextContent('Recovery must preserve edits for ' . $wiki));
                $editor->saveRevision(MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment('Recovery replay fixture'));
                if (!$editor->wasSuccessful()) { throw new RuntimeException('Could not edit replay fixture'); }
            }
            $revision = $page->getRevisionRecord();
            $central = MediaWiki\Extension\CentralAuth\User\CentralAuthUser::getPrimaryInstanceByName('Admin');
            $permissions = $services->getPermissionManager();
            echo json_encode([
                'main_page' => $title->getPrefixedDBkey(),
                'main_page_exists' => $page->exists(),
                'main_page_revision' => $revision ? $revision->getId() : null,
                'main_page_sha1' => $revision ? $revision->getSha1() : null,
                'requester_id' => $user->getId(),
                'requester_attached' => array_key_exists($wiki, $central->queryAttachedBasic()),
                'requester_groups' => $services->getUserGroupManager()->getUserGroups($user),
                'rights_log_entries' => (int)$services->getConnectionProvider()->getPrimaryDatabase()
                    ->newSelectQueryBuilder()->select('COUNT(*)')->from('logging')->where(['log_type'=>'rights'])
                    ->caller(__METHOD__)->fetchField(),
                'creation_checkpoint' => (bool)$services->getConnectionProvider()->getPrimaryDatabase()
                    ->newSelectQueryBuilder()->select('1')->from('updatelog')
                    ->where(['ul_key'=>'farm-finalize-creation-v1'])->caller(__METHOD__)->fetchField(),
                'requester_rights' => array_combine(['read','edit','userrights','editsitecss'],
                    array_map(static fn($right) => $permissions->userHasRight($user, $right),
                        ['read','edit','userrights','editsitecss'])),
            ]), "\n";
            return;
        }
        if ($this->hasOption('pause')) {
            $paused = false;
            $services->getHookContainer()->register('CreateWikiGenerateDatabaseLists',
                static function (&$lists) use ($wiki, &$paused): void {
                    if ($paused) {
                        return;
                    }
                    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                        if (($frame['function'] ?? '') === 'farmPrepareWikiMetadata') {
                            $paused = true;
                            file_put_contents('/var/www/mediawiki/cw_cache/.accept-' . $wiki, (string)getmypid());
                            posix_kill(getmypid(), SIGSTOP);
                            return;
                        }
                    }
                });
        }
        $private = $this->hasOption('private');
        $error = $services->get('WikiManagerFactory')->newInstance($wiki)->create(
            'Production acceptance fixture', $private ? 'de' : 'en', $private, 'uncategorised',
            'Admin', 'Admin', 'Isolated provisioning concurrency and recovery checks', []
        );
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        $this->output("Created $wiki\n");
    }
}
return ProvisioningProductionAcceptance::class;
