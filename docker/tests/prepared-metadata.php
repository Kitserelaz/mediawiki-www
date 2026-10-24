<?php
$helper = $argv[1] ?? '/opt/farm/provision-metadata.php';
require $helper;
$directory = '/tmp/prepared-metadata-test-' . bin2hex(random_bytes(6));
mkdir($directory);
$wiki = '9preparedtestwiki';
$data = ['mtime'=>time(), 'database'=>$wiki, 'core'=>['wgLanguageCode'=>'en','wgSitename'=>'Test'],
    'states'=>['private'=>false], 'extensions'=>[], 'settings'=>[], 'permissions'=>[]];
$entry = ['s'=>'Test', 'c'=>null];
$lists = ['databases'=>[$wiki=>$entry], 'deleted'=>[], 'active'=>[$wiki=>$entry],
    'private'=>[], 'public'=>[$wiki=>$entry], 'alpha-wikis'=>[$wiki=>$entry],
    'beta-wikis'=>[$wiki=>$entry], 'stable-wikis'=>[$wiki=>$entry]];
$write = static function (string $name, array $value) use ($directory): void {
    file_put_contents("$directory/$name.php", '<?php return ' . var_export($value,true) . ';');
    if (function_exists('opcache_invalidate')) { opcache_invalidate("$directory/$name.php", true); }
};
$check = static function (bool $expected, string $message) use ($wiki,$directory): void {
    if (farmPreparedWikiMetadataIsCurrent($wiki,$directory) !== $expected) { throw new RuntimeException($message); }
};
try {
    $write($wiki,$data);
    foreach ($lists as $name=>$value) { $write($name,['mtime'=>time(),'databases'=>$value]); }
    $check(false,'Unsealed metadata was trusted');
    farmRecordPreparedWikiMetadata($wiki,$directory);
    $check(true,'Prepared metadata was rejected');
    $data['mtime']++;
    $write($wiki,$data);
    $lists['databases']['unrelatedwiki']=$entry;
    $write('databases',['mtime'=>time()+1,'databases'=>$lists['databases']]);
    $check(true,'Timestamp or unrelated creation invalidated the target');
    $changed=$data;$changed['settings']['wgEnableUploads']=false;
    $write($wiki,$changed);
    $check(false,'Changed settings were trusted');
    $write($wiki,$data);
    $check(true,'Original metadata was not restored');
    $write('databases',['mtime'=>time(),'databases'=>[]]);
    $check(false,'Missing shared wiki registration was trusted');
    $write('databases',['mtime'=>time(),'databases'=>$lists['databases']]);
    $write($wiki,['mtime'=>time(),'database'=>$wiki]);
    $check(false,'Partial metadata was trusted');
    $data['states']['private']=true;
    $write($wiki,$data);
    try { farmRecordPreparedWikiMetadata($wiki,$directory); throw new RuntimeException('Inconsistent privacy lists were sealed'); }
    catch (RuntimeException $error) {
        if (!str_starts_with($error->getMessage(),'Incomplete provisioning metadata')) { throw $error; }
    }
    $write('private',['mtime'=>time(),'databases'=>[$wiki=>$entry]]);
    $write('public',['mtime'=>time(),'databases'=>[]]);
    farmRecordPreparedWikiMetadata($wiki,$directory);
    $check(true,'Complete private metadata was rejected');
    file_put_contents("$directory/.provision-$wiki.metadata.json", '{');
    $check(false,'Broken receipt was trusted');
    if (farmPreparedWikiMetadataIsCurrent('../invalid',$directory)) { throw new RuntimeException('Invalid target accepted'); }
    // Exercise the actual coordinator while a creator owns the handoff lock.
    // Only run this part in a disposable container, never on the main farm.
    if (getenv('METADATA_LOCK_TEST_ISOLATED') === '1') {
        require_once dirname($helper) . '/process.php';
        $pending = '/var/www/mediawiki/cw_cache/.provision-' . $wiki;
        file_put_contents($pending, 'lock test');
        $lock = fopen($pending . '.metadata.lock', 'ce');
        flock($lock, LOCK_EX);
        $process = proc_open([PHP_BINARY, dirname($helper) . '/provision.php', $wiki],
            [0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function () use ($process): void { proc_terminate($process, SIGKILL); });
        $pid = proc_get_status($process)['pid'];
        $until = microtime(true) + 5;
        do {
            $ready = false;
            foreach (glob('/proc/' . $pid . '/fd/*') as $fd) {
                if (@readlink($fd) === $pending . '.metadata.lock') { $ready = true; break; }
            }
            if ($ready) { break; }
            usleep(10000);
        } while (microtime(true) < $until);
        if (!$ready) { proc_terminate($process,SIGKILL); throw new RuntimeException('Coordinator did not reach metadata handoff'); }
        $start = hrtime(true);
        proc_terminate($process,SIGTERM);
        pcntl_alarm(2);
        $status = farmWaitProcess($process);
        pcntl_alarm(0);
        flock($lock,LOCK_UN);fclose($lock);
        if ($status !== 1 || (hrtime(true)-$start)/1e9 > 1) { throw new RuntimeException('Coordinator shutdown blocked on creator lock: status='.$status.' elapsed='.(hrtime(true)-$start)/1e9); }
        if (!is_file($pending)) { throw new RuntimeException('Interrupted preparation cleared pending work'); }
        foreach (glob($pending.'*') as $file) { unlink($file); }
    }
    echo "Prepared cache reuse, stale/partial repair, list consistency, privacy, timestamps and receipts passed\n";
} finally {
    foreach (glob("$directory/*") as $path) { unlink($path); }
    foreach (glob("$directory/.*") as $path) { if (is_file($path)) { unlink($path); } }
    rmdir($directory);
}
