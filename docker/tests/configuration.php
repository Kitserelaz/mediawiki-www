<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';
class CheckDockerConfiguration extends MediaWiki\Maintenance\Maintenance {
    public function execute() {
        $config = $this->getConfig();
        $db = $config->get('DBname');
        $uploads = rtrim(getenv('MW_UPLOAD_ROOT') ?: '/var/www/mediawiki/images', '/') . '/' . $db;
        $dumps = getenv('MW_DUMP_DIRECTORY') ?: '/var/www/dumps';
        if ($config->get('UploadDirectory') !== $uploads || $config->get('DataDumpDirectory') !== $dumps) {
            throw new RuntimeException('Configured persistent storage paths were not applied');
        }
        $zip = $config->get('DataDump')['zip']['generate']['options'];
        if ($zip[2] !== $uploads || $zip[1] !== rtrim($dumps, '/') . '/${filename}') {
            throw new RuntimeException('ZIP dump paths differ from configured storage');
        }
        if (!is_executable($config->get('PhpCli'))) { throw new RuntimeException('Maintenance PHP binary is unavailable'); }
        $registry = MediaWiki\Registration\ExtensionRegistry::getInstance();
        foreach (['MW_ANALYTICS' => 'MatomoAnalytics', 'MW_NOTIFICATIONS' => 'DiscordNotifications', 'MW_CACHE_PURGE' => 'CFCachePurge'] as $flag => $extension) {
            if ($registry->isLoaded($extension) !== (getenv($flag) === '1')) {
                throw new RuntimeException('Integration extension opt-in differs from runtime configuration: ' . $extension);
            }
        }
        if (getenv('MW_ENV') !== 'production') {
            if ($config->get('PasswordAttemptThrottle') !== []) {
                throw new RuntimeException('Development login attempts must not be throttled');
            }
            $remote = getenv('MW_REMOTE_REPOSITORIES') === '1';
            if ((bool)$config->get('UseQuickInstantCommons') !== $remote) {
                throw new RuntimeException('Remote repository opt-in is not respected');
            }
            if (!$remote && ($config->get('UseInstantCommons') || $config->get('ForeignFileRepos'))) {
                throw new RuntimeException('A remote file repository is enabled by default');
            }
            if ($config->get('GlobalUserPageAPIUrl') !== 'https://meta.' . (getenv('MW_DOMAIN') ?: 'localhost') . '/api.php') {
                throw new RuntimeException('Global user pages still point outside the local farm');
            }
        }
        $this->output("Storage, maintenance binary and local integration configuration verified.\n");
    }
}
return CheckDockerConfiguration::class;
