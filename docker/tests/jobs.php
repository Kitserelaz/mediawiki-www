<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';
class DockerJobCheck extends MediaWiki\Maintenance\Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addOption('enqueue', 'Enqueue a disposable null job');
    }
    public function execute() {
        if (getenv('MW_ENV') === 'production') { throw new RuntimeException('Development test only'); }
        $queue = $this->getServiceContainer()->getJobQueueGroup();
        if ($this->hasOption('enqueue')) {
            $queue->push(new MediaWiki\JobQueue\Jobs\NullJob(['dockerTest' => bin2hex(random_bytes(8))]));
        }
        $this->output((string)$queue->get('null')->getSize() . "\n");
    }
}
return DockerJobCheck::class;
