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
