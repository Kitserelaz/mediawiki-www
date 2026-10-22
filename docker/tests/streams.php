<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';
class StreamQueueProbe extends MediaWiki\Maintenance\Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addOption('count', 'Number of jobs', false, true);
        $this->addOption('delay', 'Delay seconds', false, true);
        $this->addOption('usleep', 'Job runtime in microseconds', false, true);
    }
    public function execute() {
        if (getenv('MW_JOBCHRON_TEST_ISOLATED') !== '1') { throw new RuntimeException('Disposable stream tests only'); }
        $jobs = [];
        for ($i = 0; $i < (int)$this->getOption('count', 1); $i++) {
            $params = ['dispatchTest' => bin2hex(random_bytes(8)), 'usleep' => (int)$this->getOption('usleep', 0)];
            $delay = (int)$this->getOption('delay', 0);
            if ($delay > 0) { $params['jobReleaseTimestamp'] = time() + $delay; }
            $jobs[] = new MediaWiki\JobQueue\Jobs\NullJob($params);
        }
        $this->getServiceContainer()->getJobQueueGroup()->push($jobs);
        echo "Enqueued " . count($jobs) . " jobs.\n";
    }
}
return StreamQueueProbe::class;
