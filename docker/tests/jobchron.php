<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';

class DockerJobChronCheck extends MediaWiki\Maintenance\Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addOption('action', 'stats, enqueue, claim, or claim-and-wait', true, true);
        $this->addOption('delay', 'Release delay in seconds', false, true);
    }

    public function execute() {
        if (getenv('MW_ENV') !== 'development' || getenv('MW_JOBCHRON_TEST_ISOLATED') !== '1') {
            throw new RuntimeException('Jobchron tests require a disposable development farm');
        }
        $group = $this->getServiceContainer()->getJobQueueGroup();
        $queue = $group->get('null');
        $action = $this->getOption('action');
        if ($action === 'enqueue') {
            $params = ['jobchronTest' => bin2hex(random_bytes(8))];
            $delay = (int)$this->getOption('delay', 0);
            if ($delay > 0) { $params['jobReleaseTimestamp'] = time() + $delay; }
            $group->push(new MediaWiki\JobQueue\Jobs\NullJob($params));
        } elseif (in_array($action, ['claim', 'claim-and-wait'], true)) {
            // Simulate a worker interrupted between pop and acknowledgement.
            $job = $queue->pop();
            if (!$job) { throw new RuntimeException('No test job to claim'); }
            $this->output("Claimed disposable NullJob\n");
            if ($action === 'claim-and-wait') { sleep(120); }
        } elseif ($action !== 'stats') {
            throw new RuntimeException('Unknown test action');
        }
        $this->output(json_encode([
            'ready' => $queue->getSize(),
            'delayed' => $queue->getDelayedCount(),
            'acquired' => $queue->getAcquiredCount(),
            'abandoned' => $queue->getAbandonedCount(),
        ]) . "\n");
    }
}
return DockerJobChronCheck::class;
