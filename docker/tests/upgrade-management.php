<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';

class UpgradeManagementAcceptance extends MediaWiki\Maintenance\Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addOption('phase', 'Acceptance phase', true, true);
    }

    private function check(bool $condition, string $message): void {
        if (!$condition) { throw new RuntimeException($message); }
    }

    public function execute() {
        $this->check(getenv('MW_ENV') === 'development', 'Disposable development fixtures only');
        $phase = $this->getOption('phase');
        $this->check($this->getConfig()->get('DBname') === ($phase === 'verify-local' ? 'upgradesmokewiki' : 'metawiki'),
            'Run remote management from metawiki or local verification on the reserved fixture');
        $services = $this->getServiceContainer();
        $factory = $services->get('ManageWikiModuleFactory');
        $wiki = 'upgradesmokewiki';
        $stateFile = '/var/www/mediawiki/cache/upgrade-management-before.json';
        $core = $factory->core($wiki);
        $this->check($core instanceof Miraheze\CreateWiki\Helpers\ManageWikiCoreModule,
            'ManageWiki did not select the CreateWiki core provider');
        switch ($phase) {
            case 'custom-domain-page':
                $beforeUrl = $core->getServerName();
                try {
                    foreach ([['UpgradeNoEmail', false], [getenv('ADMIN_USER') ?: 'Admin', true]] as [$name, $allowed]) {
                        $context = new MediaWiki\Context\DerivativeContext(MediaWiki\Context\RequestContext::getMain());
                        $context->setUser($services->getUserFactory()->newFromName($name));
                        $context->setTitle(MediaWiki\SpecialPage\SpecialPage::getTitleFor('ChangeDomain', $wiki));
                        $page = $services->getSpecialPageFactory()->getPage('ChangeDomain');
                        $this->check($page instanceof WikiOasis\WikiOasisMagic\SpecialChangeDomain &&
                            $page->getRestriction() === 'managewiki-restricted', 'Custom domain page registration changed');
                        $page->setContext($context);
                        $result = $page->submitForm(['wiki' => $wiki, 'subdomain' => 'https://upgradedomain.localhost']);
                        $actual = $services->get('RemoteWikiFactory')->newInstance($wiki)->getServerName();
                        $this->check($allowed ? ($result === true && $actual === 'https://upgradedomain.localhost') :
                            ($result !== true && $actual === $beforeUrl), 'Custom domain submission authorization or persistence changed');
                    }
                } finally {
                    $remote = $services->get('RemoteWikiFactory')->newInstance($wiki);
                    $remote->setServerName($beforeUrl);
                    $remote->commit();
                }
                break;
            case 'policy':
                $user = $services->getUserFactory()->newFromName('UpgradeNoEmail');
                if (!$user->getId()) { $user->addToDatabase(); }
                $this->check($user->isRegistered() && !$user->isEmailConfirmed(), 'Email-policy fixture is not an unconfirmed registered user');
                $this->check($services->getPermissionManager()->userHasRight($user, 'createnewwiki'),
                    'Registered-user creation permission was lost');
                $context = new MediaWiki\Context\DerivativeContext(MediaWiki\Context\RequestContext::getMain());
                $context->setUser($user);
                $context->setRequest(new MediaWiki\Request\FauxRequest());
                $context->setTitle(MediaWiki\SpecialPage\SpecialPage::getTitleFor('CreateNewWiki'));
                $page = $services->getSpecialPageFactory()->getPage('CreateNewWiki');
                $this->check($page instanceof WikiOasis\WikiOasisMagic\SpecialCreateNewWiki &&
                    $page->getRestriction() === 'createnewwiki', 'Registered creation page changed');
                $page->setContext($context);
                $page->execute(null);
                $this->check(str_contains($context->getOutput()->getHTML(), 'wpdbname'),
                    'Unconfirmed registered user did not receive the creation form');
                $ordinaryCreate = $services->getSpecialPageFactory()->getPage('CreateWiki');
                $this->check(!$ordinaryCreate instanceof WikiOasis\WikiOasisMagic\SpecialCreateWikiCustom,
                    'Unused email-gated override became active');
                $builder = $services->get('ManageWikiFormFactoryBuilder');
                foreach ([
                    [[], true],
                    [['managewiki-restricted'], true],
                    [['managewiki-privacy'], true],
                    [['managewiki-restricted', 'managewiki-privacy'], false],
                ] as [$rights, $disabled]) {
                    $context->setAuthority(new MediaWiki\Permissions\SimpleAuthority($user, $rights));
                    $descriptor = $builder->buildDescriptor($factory, $context, $wiki, 'core', 'ManageWiki', true);
                    $this->check($descriptor['private']['disabled'] === $disabled,
                        'Remote privacy control did not enforce both required rights');
                }
                $this->check(!array_key_exists('steward', $services->getMainConfig()->get('GroupPermissions')),
                    'Retired steward role was recreated');
                break;
            case 'configure':
                if (!file_exists($stateFile)) {
                    $this->check(!$factory->permissions($wiki)->exists('upgrade-auditors'), 'Reserved fixture group already exists');
                    $this->check(!$factory->namespaces($wiki)->exists(3000), 'Reserved fixture namespace already exists');
                    file_put_contents($stateFile, json_encode([
                        'wiki' => $wiki, 'sitename' => $core->getSitename(), 'url' => $core->getServerName(),
                        'namespaces' => $factory->namespaces($wiki)->listAll(),
                        'settings' => $factory->settings($wiki)->listAll(),
                        'extensions' => $factory->extensions($wiki)->list(),
                    ], JSON_THROW_ON_ERROR));
                }
                $core->setSitename('Upgrade Remote Alpha');
                $core->setServerName('https://upgradealias.localhost');
                $core->markExempt();
                $core->setInactiveExemptReason('Upgrade acceptance');
                $core->setInactiveExemptExpiry('2030-01-02T03:04:05Z');
                $core->commit();
                $namespaces = $factory->namespaces($wiki);
                foreach ([4 => 'Upgrade_Alpha', 5 => 'Upgrade_Alpha_talk',
                    3000 => 'Upgrade_Content', 3001 => 'Upgrade_Content_talk'] as $id => $name) {
                    $namespaces->modify($id, ['name' => $name, 'aliases' => []], maintainPrefix: false);
                }
                $namespaces->commit();
                $this->check(!$namespaces->getErrors(), 'Remote namespace update failed');
                $settings = $factory->settings($wiki);
                $settings->modify(['wgEnableUploads' => false], default: true);
                $settings->commit();
                $permissions = $factory->permissions($wiki);
                $permissions->modify('upgrade-auditors', ['permissions' => ['add' => ['read', 'edit']]]);
                $permissions->commit();
                $this->check(!$permissions->getErrors(), 'Remote permission update failed');
                $extensions = $factory->extensions($wiki);
                if (!in_array('poem', $extensions->list(), true)) { $extensions->add(['poem']); }
                $extensions->commit();
                $this->check(!$extensions->getErrors(), 'Remote extension update failed');
                $core->markPrivate();
                $core->markClosed();
                $core->setClosedReason('Upgrade acceptance closure');
                $core->lock();
                $core->commit();
                break;
            case 'verify':
                $this->check($core->getSitename() === 'Upgrade Remote Alpha', 'Remote sitename did not persist');
                $this->check($core->getServerName() === 'https://upgradealias.localhost', 'Custom domain did not persist');
                $this->check($core->isPrivate() && $core->isClosed() && $core->isLocked(), 'Wiki lifecycle flags did not persist');
                $this->check($core->getClosedReason() === 'Upgrade acceptance closure' && $core->getClosedTimestamp() !== null,
                    'Closure reason/timestamp did not persist');
                $this->check($core->isInactiveExempt() && $core->getInactiveExemptExpiry() === '20300102030405',
                    'Inactive exemption/expiry did not persist');
                $conf = $this->getConfig()->get('ManageWikiSiteConfiguration');
                $this->output(json_encode(['configuration_class' => get_class($conf),
                    'target_project_row' => $factory->namespaces($wiki)->list(NS_PROJECT),
                    'target_project_config' => $conf->get('wgMetaNamespace', $wiki)], JSON_THROW_ON_ERROR) . "\n");
                $this->check($conf->get('wgMetaNamespace', $wiki) === 'Upgrade_Alpha', 'Remote namespace used the caller cache');
                $other = $factory->namespaces('upgradeprivatewiki')->list(NS_PROJECT)['name'];
                $this->check($other !== 'Upgrade_Alpha' && $conf->get('wgMetaNamespace', 'upgradeprivatewiki') === $other,
                    'Remote namespace leaked between target wikis');
                $this->check($factory->settings($wiki)->list('wgEnableUploads') === false, 'Remote setting did not persist');
                $this->check(in_array('edit', $factory->permissions($wiki)->list('upgrade-auditors')['permissions'], true),
                    'Remote group permissions did not persist');
                $this->check(in_array('Poem', $factory->extensions($wiki)->listNames(), true), 'Extension selection did not persist');
                $cache = include '/var/www/mediawiki/cw_cache/' . $wiki . '.php';
                $this->check(($cache['states']['private'] ?? false) && ($cache['states']['closed'] ?? false) &&
                    ($cache['states']['locked'] ?? false), 'Persisted wiki cache lost lifecycle fields');
                break;
            case 'verify-local':
                $config = $this->getConfig();
                $this->check($config->get('Sitename') === 'Upgrade Remote Alpha', 'Remote core change did not reach local runtime');
                $this->check($config->get('MetaNamespace') === 'Upgrade_Alpha', 'Namespace change did not reach local runtime');
                $this->check($config->get('EnableUploads') === false, 'Remote setting did not reach local runtime');
                $this->check(($config->get('GroupPermissions')['upgrade-auditors']['edit'] ?? false),
                    'Remote permissions did not reach local runtime');
                $this->check(MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('Poem'),
                    'Selected extension did not load locally');
                $this->output(json_encode(['server' => $config->get('Server'), 'article_path' => $config->get('ArticlePath')], JSON_THROW_ON_ERROR) . "\n");
                $this->check($config->get('Server') === 'https://upgradealias.localhost' &&
                    $config->get('ArticlePath') === '/wiki/$1', 'Custom-domain article routing changed');
                $this->check($config->get('ReadOnly') !== false, 'Closed/locked wiki is writable');
                break;
            case 'delete':
                $core->delete();
                $core->commit();
                break;
            case 'verify-deleted':
                $this->check($core->isDeleted() && $core->getDeletedTimestamp() !== null, 'Logical deletion did not persist');
                $deleted = MirahezeFunctions::readDbListFile('deleted', false);
                $this->check(isset($deleted[$wiki]) &&
                    !in_array($wiki, $services->get('CreateWikiDataStore')->getAllDatabases(), true),
                    'Deleted wiki was not moved from the active list into recoverable metadata');
                break;
            case 'restore':
                $before = json_decode(file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
                $this->check($before['wiki'] === $wiki, 'Fixture recovery state belongs to another wiki');
                $core->undelete();
                $core->unlock();
                $core->markActive();
                $core->markPublic();
                $core->unExempt();
                $core->setSitename($before['sitename']);
                $core->setServerName($before['url']);
                $core->commit();
                $settings = $factory->settings($wiki);
                $settings->overwriteAll($before['settings'], remove: true);
                $settings->commit();
                $extensions = $factory->extensions($wiki);
                $extensions->overwriteAll($before['extensions']);
                $extensions->commit();
                $permissions = $factory->permissions($wiki);
                if ($permissions->exists('upgrade-auditors')) { $permissions->remove('upgrade-auditors'); }
                $permissions->commit();
                $namespaces = $factory->namespaces($wiki);
                foreach ([3000, 3001] as $id) {
                    if ($namespaces->exists($id)) { $namespaces->remove($id, 0, maintainPrefix: false); }
                }
                foreach ([4, 5] as $id) { $namespaces->modify($id, $before['namespaces'][$id], maintainPrefix: false); }
                $namespaces->commit();
                $this->check(!$namespaces->getErrors() && !$extensions->getErrors() && !$permissions->getErrors(),
                    'Fixture restoration produced module errors');
                rename($stateFile, $stateFile . '.restored');
                break;
            case 'verify-restored':
                $restoredFile = $stateFile . '.restored';
                $before = json_decode(file_get_contents($restoredFile), true, 512, JSON_THROW_ON_ERROR);
                $this->check(!$core->isDeleted() && !$core->isLocked() && !$core->isClosed() && !$core->isPrivate(),
                    'Undelete/reopen/public recovery did not persist');
                $this->check(!$core->isInactiveExempt() && $core->getInactiveExemptExpiry() === null &&
                    $core->getClosedReason() === null && $core->getDeletedTimestamp() === null,
                    'Recovery retained stale lifecycle metadata');
                $this->check($core->getSitename() === $before['sitename'] && $core->getServerName() === $before['url'],
                    'Recovery did not restore the original domain/sitename');
                $this->check($factory->settings($wiki)->listAll() == $before['settings'] &&
                    $factory->extensions($wiki)->list() == $before['extensions'] &&
                    $factory->namespaces($wiki)->listAll() == $before['namespaces'] &&
                    !$factory->permissions($wiki)->exists('upgrade-auditors'), 'Module recovery differs from saved fixture state');
                unlink($restoredFile);
                break;
            case 'remote-config':
                $conf = $this->getConfig()->get('ManageWikiSiteConfiguration');
                $expected = $factory->namespaces($wiki)->list(NS_PROJECT)['name'] ??
                    str_replace([' ', ':'], '_', $core->getSitename());
                $actual = $conf->get('wgMetaNamespace', $wiki);
                $this->output(json_encode(['remote_wiki' => $wiki, 'expected_project_namespace' => $expected,
                    'actual_project_namespace' => $actual], JSON_THROW_ON_ERROR) . "\n");
                $this->check($actual === $expected, 'Remote project namespace was taken from the calling wiki');
                $rights = $conf->get('wgManageWikiPermissionsAdditionalRights', $wiki);
                $this->check(($rights['*']['read'] ?? null) === true, 'Remote additional permissions were lost');
                break;
            default:
                throw new RuntimeException('Unknown acceptance phase');
        }
        $this->output('Management acceptance phase passed: ' . $this->getOption('phase') . "\n");
    }
}
return UpgradeManagementAcceptance::class;
