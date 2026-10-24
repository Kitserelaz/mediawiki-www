<?php
/** @license GPL-2.0-or-later */
// Standalone checks: php extensions/ArchiveWikiImport/tests/unit.php
require_once __DIR__ . '/../includes/ArchiveSource.php';
require_once __DIR__ . '/../includes/RunStore.php';
require_once __DIR__ . '/../includes/Bundle.php';
require_once __DIR__ . '/../includes/DependencyScanner.php';
use MediaWiki\Extension\ArchiveWikiImport\ArchiveSource;
use MediaWiki\Extension\ArchiveWikiImport\RunStore;
use MediaWiki\Extension\ArchiveWikiImport\Bundle;
use MediaWiki\Extension\ArchiveWikiImport\DependencyScanner;

$checks = 0;
function check( bool $condition, string $message ): void {
	global $checks;
	$checks++;
	if ( !$condition ) {
		throw new RuntimeException( $message );
	}
}
function rejects( callable $fn ): void {
	try {
		$fn();
	} catch ( RuntimeException $e ) {
		check( true, 'rejected' );
		return;
	}
	throw new RuntimeException( 'Expected rejection.' );
}
/** Existing historical reports are fixtures, not new import admissions. */
function seedReport( RunStore $store, array $state ): string {
	$id = bin2hex( random_bytes( 16 ) );
	mkdir( $store->directory( $id ), 0700 );
	$store->save( $id, $state + [ 'created' => '2000-01-01T00:00:00+00:00', 'status' => 'queued',
		'pages' => 0, 'revisions' => 0, 'images' => 0, 'phase' => 'pages' ] );
	return $id;
}
foreach ( [ 'wiki-example', 'https://archive.org/details/wiki-example',
	'https://archive.org/download/wiki-example/' ] as $url ) {
	check( ArchiveSource::identifier( $url ) === 'wiki-example', 'Identifier normalization' );
}
foreach ( [ 'https://evil.example/details/wiki-example', 'http://archive.org/details/test',
	'https://archive.org@evil.example/details/test', 'https://u@archive.org/details/test',
	'https://archive.org:443/details/test', '../test', 'https://archive.org/details/%2e%2e%2ftest' ] as $url ) {
	rejects( static fn () => ArchiveSource::identifier( $url ) );
}
foreach ( [ '../x', '/absolute', "a\0b", 'a/../b', 'a\\b' ] as $path ) {
	check( !ArchiveSource::safePath( $path ), 'Unsafe archive path' );
}
check( ArchiveSource::safePath( 'images/a/A picture.png' ), 'Nested safe path' );
$manifest = ArchiveSource::manifest( 'wiki-test', [ 'files' => [
	[ 'name' => 'test-history.xml.zst', 'size' => '20', 'sha1' => str_repeat( 'a', 40 ) ],
	[ 'name' => 'test-images.7z', 'size' => '30', 'sha1' => str_repeat( 'b', 40 ) ],
	[ 'name' => '../test.xml', 'size' => '20', 'sha1' => str_repeat( 'a', 40 ) ],
	[ 'name' => 'test_meta.xml', 'size' => '20', 'sha1' => str_repeat( 'a', 40 ) ],
	[ 'name' => 'private.xml', 'size' => '20', 'sha1' => str_repeat( 'a', 40 ), 'private' => 'true' ],
	[ 'name' => 'bad.xml', 'size' => '20', 'sha1' => 'bad' ]
] ] );
check( count( $manifest['files'] ) === 2, 'Only supported verified files are selectable' );
rejects( static fn () => ArchiveSource::manifest( 'empty', [ 'files' => [] ] ) );
$root = sys_get_temp_dir() . '/awi-unit-' . bin2hex( random_bytes( 8 ) );
$a = new RunStore( $root, 'wiki-a' );
$b = new RunStore( $root, 'wiki-b' );
$id = $a->create( [ 'item' => 'wiki-example' ] );
check( $a->read( $id )['status'] === 'queued', 'Run creation' );
rejects( static fn () => $b->read( $id ) );
rejects( static fn () => $a->directory( '../escape' ) );
$lock = $a->lock( $id );
check( is_resource( $lock ) && $a->lock( $id ) === false, 'Exclusive run locking' );
fclose( $lock );
$lock = $a->lock( $id );
check( is_resource( $lock ), 'Locks release after worker exits' );
fclose( $lock );
$state = $a->read( $id );
$state['pages'] = 12;
$a->save( $id, $state );
check( $a->read( $id )['pages'] === 12, 'Checkpoint persistence' );

if ( is_executable( '/usr/bin/7z' ) && is_executable( '/usr/bin/zstd' ) ) {
	$zipPath = $root . '/test.zip';
	$zip = new ZipArchive();
	$zip->open( $zipPath, ZipArchive::CREATE );
	$zip->addFromString( 'nested/Test.png', 'payload' );
	$zip->close();
	$bundle = new Bundle( '/usr/bin/7z', 1024, 10 );
	$entries = $bundle->entries( $zipPath, $root . '/listing' );
	check( count( $entries ) === 1 && $entries[0]['name'] === 'nested/Test.png', 'Archive listing' );
	$bundle->extract( $zipPath, $entries[0], $root . '/extracted' );
	check( file_get_contents( $root . '/extracted' ) === 'payload', 'Stream extraction' );
	rejects( static fn () => ( new Bundle( '/usr/bin/7z', 3, 10 ) )->entries( $zipPath, $root . '/listing' ) );
	$zip->open( $root . '/unsafe.zip', ZipArchive::CREATE );
	$zip->addFromString( '../escape', 'bad' );
	$zip->close();
	rejects( static fn () => $bundle->entries( $root . '/unsafe.zip', $root . '/listing' ) );
	// A failed extraction must not leave a publishable output.
	rejects( static fn () => $bundle->extract( $zipPath, [ 'name' => 'nested/Test.png', 'size' => 1 ], $root . '/short' ) );
} else {
	echo "Archive extraction checks skipped: install 7z and zstd.\n";
}
$scanPath = $root . '/scan.xml';
file_put_contents( $scanPath, '<mediawiki><page><title>Example</title><revision><model>sanitized-css</model>' .
	'<text>.example {color:red}</text></revision><revision><model>wikitext</model>' .
	'<text>&lt;ref&gt;citation&lt;/ref&gt; {{#invoke:Example}} {{#if:x|y}} &lt;templatestyles src="x" /&gt;</text>' .
	'</revision></page></mediawiki>' );
$scanner = new DependencyScanner();
$providers = [ 'sanitized-css' => [ 'TemplateStyles' ] ];
$scan = $scanner->inspect( $scanPath, [ 'wikitext' ], [ 'ParserFunctions' ], $providers );
check( $scan['pages'] === 1 && $scan['revisions'] === 2, 'Dependency scan counts all revisions' );
check( $scan['missingModels'] === [ 'sanitized-css' => [ 'TemplateStyles' ] ], 'Historical content model requires TemplateStyles' );
check( isset( $scan['extensions']['Cite'], $scan['extensions']['Scribunto'] ), 'Parser tag and Lua suggestions' );
check( $scan['extensions']['ParserFunctions']['enabled'], 'Enabled parser extensions recognized' );
check( !$scan['extensions']['TemplateStyles']['enabled'], 'Missing extension identified' );
$ready = $scanner->inspect( $scanPath, [ 'wikitext', 'sanitized-css' ], [ 'TemplateStyles' ], $providers );
check( !$ready['missingModels'], 'Installing the content handler clears the blocker' );
check( !$ready['extensions']['Cite']['enabled'], 'Markup hints remain advisory without a handler blocker' );
file_put_contents( $scanPath, '<mediawiki><page><revision><model>unknown-model</model></revision></page></mediawiki>' );
check( $scanner->inspect( $scanPath, [], [], [] )['missingModels'] === [ 'unknown-model' => [] ], 'Unknown providers reported without guessing' );
file_put_contents( $scanPath, '<!DOCTYPE mediawiki [<!ENTITY external SYSTEM "file:///etc/passwd">]><mediawiki>&external;</mediawiki>' );
rejects( static fn () => $scanner->inspect( $scanPath, [], [], [] ) );
file_put_contents( $scanPath, '<mediawiki><page>' );
rejects( static fn () => $scanner->inspect( $scanPath, [], [], [] ) );
mkdir( $root . '/catalogue/Installed', 0700, true );
file_put_contents( $root . '/catalogue/Installed/extension.json', json_encode( [
	'name' => 'InstalledProvider', 'ContentHandlers' => [ 'custom-data' => 'UnusedClass' ]
] ) );
check( DependencyScanner::providers( $root . '/catalogue' )['custom-data'] === [ 'InstalledProvider' ], 'Content providers discovered from installed manifests' );
$queueRoot = $root . '/queue';
$queue = new RunStore( $queueRoot, 'queue-wiki' );
$otherQueue = new RunStore( $queueRoot, 'other-wiki' );
$ids = [];
foreach ( [ 'queued', 'running', 'needs-attention', 'failed', 'complete' ] as $index => $status ) {
	$queueId = $queue->create( [ 'item' => 'archive-' . $index, 'status' => $status, 'user' => $index % 2 + 1 ] );
	$state = $queue->read( $queueId );
	$state['updated'] = '2026-01-0' . ( $index + 1 ) . 'T00:00:00+00:00';
	file_put_contents( $queue->directory( $queueId ) . '/state.json', json_encode( $state ) );
	$ids[$status] = $queueId;
}
$otherQueue->create( [ 'item' => 'private-other-wiki', 'user' => 1 ] );
check( $queue->listRuns()['total'] === 4, 'Queue defaults to active and actionable imports' );
check( $queue->listRuns( 'all' )['total'] === 5, 'Completed imports available with all-status filter' );
check( $queue->listRuns( 'failed' )['runs'][0]['id'] === $ids['failed'], 'Exact queue status filtering' );
check( $queue->listRuns( 'all', 2 )['total'] === 2, 'Requester ID filtering' );
check( $queue->listRuns( 'all', null, 'ARCHIVE-2' )['runs'][0]['id'] === $ids['needs-attention'], 'Case-insensitive archive item filtering' );
check( $queue->listRuns( 'all', null, '', 1, 2 )['runs'][0]['id'] === $ids['failed'], 'Newest-first pagination keeps stable run identities' );
check( count( $queue->listRuns( 'all', null, '', 1, 2 )['runs'] ) === 2, 'Queue page size is honored' );
check( $queue->listRuns( 'all', null, 'private-other-wiki' )['total'] === 0, 'Queue never exposes other wiki reports' );
$corrupt = seedReport( $queue, [ 'item' => 'corrupt' ] );
file_put_contents( $queue->directory( $corrupt ) . '/state.json', '{broken' );
check( $queue->listRuns( 'all' )['total'] === 5, 'A corrupt report does not break the queue' );
file_put_contents( $queue->directory( $corrupt ) . '/state.json', json_encode( [ 'status' => [], 'item' => 'bad', 'updated' => [] ] ) );
check( $queue->listRuns( 'all' )['total'] === 5, 'Invalid report field types are skipped' );
$limited = new RunStore( $root . '/limits', 'limited-wiki' );
$slots = [];
for ( $i = 0; $i < 3; $i++ ) {
	$slots[] = $limited->create( [ 'item' => 'slot-' . $i ] );
}
check( $limited->activeCount() === 3, 'Three queued imports reserve all wiki slots' );
rejects( static fn () => $limited->create( [ 'item' => 'fourth' ] ) );
check( $limited->listRuns( 'all' )['total'] === 3, 'Rejected starts do not leave a run or reservation' );
$paused = seedReport( $limited, [ 'item' => 'paused', 'status' => 'needs-attention' ] );
$failed = seedReport( $limited, [ 'item' => 'failed', 'status' => 'failed' ] );
$complete = seedReport( $limited, [ 'item' => 'complete', 'status' => 'complete' ] );
check( $limited->activeCount() === 3, 'Paused, failed and complete runs consume no slots' );
rejects( static fn () => $limited->resume( $failed ) );
check( $limited->read( $failed )['status'] === 'failed', 'Rejected resume preserves the failed report' );
rejects( static fn () => $limited->activate( $failed ) );
$limited->resume( $slots[0] );
check( $limited->activeCount() === 3, 'Already queued resume reuses its existing slot' );
$runLock = $limited->lock( $slots[0] );
$limited->activate( $slots[0] );
fclose( $runLock );
check( $limited->activeCount() === 3 && $limited->read( $slots[0] )['status'] === 'running', 'Starting an admitted job does not reserve a second slot' );
$state = $limited->read( $slots[0] );
$state['status'] = 'complete';
$limited->save( $slots[0], $state );
$limited->resume( $paused );
check( $limited->activeCount() === 3 && $limited->read( $paused )['status'] === 'queued', 'Finishing releases a slot which resume reserves immediately' );
$limited->resume( $paused );
check( $limited->activeCount() === 3, 'Repeated resume cannot reserve the same run twice' );
rejects( static fn () => $limited->resume( $complete ) );
$independent = new RunStore( $root . '/limits', 'independent-wiki' );
$independent->create( [ 'item' => 'other-wiki' ] );
check( $independent->activeCount() === 1, 'Other wikis have independent limits' );

$clock = strtotime( '2026-10-24T06:59:59Z' ); // October 23, 23:59:59 in Vancouver.
$daily = new RunStore( $root . '/daily', 'daily-wiki', 'America/Vancouver', static function () use ( &$clock ) { return $clock; } );
$dailyIds = [];
for ( $i = 0; $i < 5; $i++ ) {
	$dailyIds[] = $daily->create( [ 'item' => 'daily-' . $i, 'status' => 'failed' ] );
}
check( $daily->dailyUsage()['count'] === 5 && $daily->activeCount() === 0, 'Five new imports count even after failure' );
rejects( static fn () => $daily->create( [ 'item' => 'sixth' ] ) );
check( $daily->listRuns( 'all' )['total'] === 5, 'Daily rejection creates no run' );
$daily->resume( $dailyIds[0] );
check( $daily->dailyUsage()['count'] === 5, 'Resuming an old run remains allowed at the daily limit and does not charge again' );
foreach ( $dailyIds as $dailyId ) {
	unlink( $daily->directory( $dailyId ) . '/state.json' );
}
check( $daily->dailyUsage()['count'] === 5, 'Removing reports cannot refund daily admissions' );
check( $daily->dailyUsage()['resets'] === '2026-10-24 00:00:00 -07:00', 'Reset follows the configured wiki timezone' );
$clock += 2;
check( $daily->dailyUsage()['count'] === 0, 'Allowance resets at local midnight rather than after 24 hours' );
$daily->create( [ 'item' => 'new-day' ] );
check( $daily->dailyUsage()['count'] === 1, 'New day accepts new imports' );
$dailyOther = new RunStore( $root . '/daily', 'other-wiki', 'America/Vancouver', static function () use ( &$clock ) { return $clock; } );
check( $dailyOther->dailyUsage()['count'] === 0, 'Daily allowances are isolated per wiki' );
$clock = strtotime( '2026-03-08T08:00:00Z' );
$dst = new RunStore( $root . '/daily', 'dst-wiki', 'America/Vancouver', static function () use ( &$clock ) { return $clock; } );
check( $dst->dailyUsage()['resets'] === '2026-03-09 00:00:00 -07:00', 'Midnight reset handles daylight saving changes' );
$legacy = new RunStore( $root . '/daily', 'legacy-wiki', 'UTC', static function () use ( &$clock ) { return $clock; } );
$legacyId = seedReport( $legacy, [ 'item' => 'legacy' ] );
$legacyState = $legacy->read( $legacyId );
unset( $legacyState['created'] );
$legacyState['updated'] = gmdate( 'c', $clock );
file_put_contents( $legacy->directory( $legacyId ) . '/state.json', json_encode( $legacyState ) );
check( $legacy->dailyUsage()['count'] === 1, 'Existing reports are accounted for when the daily limit is first installed' );
$clock += 86400;
$legacyState['updated'] = gmdate( 'c', $clock );
file_put_contents( $legacy->directory( $legacyId ) . '/state.json', json_encode( $legacyState ) );
check( $legacy->dailyUsage()['count'] === 0, 'Later updates to a legacy run cannot charge it on another day' );
if ( function_exists( 'pcntl_fork' ) ) {
	// Force genuinely simultaneous contenders, each opening the shared wiki lock independently.
	foreach ( [ 'create', 'resume', 'daily' ] as $operation ) {
		$race = new RunStore( $root . '/races', $operation );
		$resumeIds = [];
		if ( $operation === 'resume' ) {
			for ( $i = 0; $i < 8; $i++ ) {
				$resumeIds[] = seedReport( $race, [ 'item' => 'resume-' . $i, 'status' => 'failed' ] );
			}
		}
		$gate = $root . '/race-' . $operation;
		$children = [];
		for ( $i = 0; $i < 8; $i++ ) {
			$pid = pcntl_fork();
			if ( $pid === -1 ) {
				throw new RuntimeException( 'Cannot fork concurrency test.' );
			}
			if ( $pid === 0 ) {
				while ( !is_file( $gate ) ) {
					usleep( 10000 );
				}
				try {
					$workerStore = new RunStore( $root . '/races', $operation );
					if ( $operation === 'create' || $operation === 'daily' ) {
						$workerStore->create( [ 'item' => 'parallel-' . $i,
							'status' => $operation === 'daily' ? 'failed' : 'queued' ] );
					} else {
						$workerStore->resume( $resumeIds[$i] );
					}
					exit( 0 );
				} catch ( RuntimeException $e ) {
					exit( str_contains( $e->getMessage(), $operation === 'daily' ? 'daily limit of 5' : '3 active imports' ) ? 10 : 11 );
				}
			}
			$children[] = $pid;
		}
		touch( $gate );
		$accepted = 0;
		$rejected = 0;
		foreach ( $children as $pid ) {
			pcntl_waitpid( $pid, $childStatus );
			$code = pcntl_wexitstatus( $childStatus );
			$accepted += $code === 0 ? 1 : 0;
			$rejected += $code === 10 ? 1 : 0;
		}
		if ( $operation === 'daily' ) {
			check( $accepted === 5 && $rejected === 3 && $race->dailyUsage()['count'] === 5,
				'Eight concurrent new admissions cannot exceed five per day, even when earlier runs have failed' );
		} else {
			check( $accepted === 3 && $rejected === 5 && $race->activeCount() === 3,
				'Eight concurrent ' . $operation . ' requests admit exactly three imports' );
		}

	}
} else {
	echo "Concurrency checks skipped: pcntl is unavailable.\n";
}

// Lease recovery uses injected time and never consumes a fresh admission.
$recoveryTime = time();
$recovery = new RunStore( $root, 'recovery', 'UTC', static function () use ( &$recoveryTime ) { return $recoveryTime; } );
$rid = $recovery->create( [ 'item' => 'recovery-fixture' ] );
$queued = [];
$publish = static function ( $id ) use ( &$queued ) { $queued[] = $id; };
check( $recovery->recover( $publish )['scheduled'] === 0, 'Fresh queued runs are left alone' );
$workerLock = $recovery->lock( $rid );
$state = $recovery->activate( $rid );
$recovery->heartbeat( $rid, 'test-worker' );
$recoveryTime += 301;
check( $recovery->recover( $publish )['stalled'] === 1 && !$queued,
	'Expired heartbeats never override a held worker lock' );
fclose( $workerLock );
check( $recovery->recover( $publish )['scheduled'] === 1 && $recovery->activeCount() === 1,
	'Abandoned run reserves its existing active slot during backoff' );
check( $recovery->read( $rid )['recoveryDue'] === $recoveryTime + 60, 'First recovery backs off one minute' );
check( $recovery->recover( $publish )['requeued'] === 0, 'Backoff prevents early publication' );
$recoveryTime += 60;
rejects( static fn () => $recovery->recover( static function () { throw new RuntimeException( 'Queue unavailable' ); } ) );
check( isset( $recovery->read( $rid )['recoveryDue'] ), 'Failed publication retains durable recovery intent' );
check( $recovery->recover( $publish )['requeued'] === 1 && $queued === [ $rid ], 'Same run ID is requeued after backoff' );
check( $recovery->recover( $publish )['requeued'] === 0, 'Repeated recovery scans do not flood the queue' );
check( $recovery->dailyUsage()['count'] === 1, 'Recovery does not consume the daily quota' );
foreach ( [ 120, 240 ] as $delay ) {
	$recoveryTime += 301;
	check( $recovery->recover( $publish )['scheduled'] === 1 &&
		$recovery->read( $rid )['recoveryDue'] === $recoveryTime + $delay, 'Recovery backoff increases' );
	$recoveryTime += $delay;
	check( $recovery->recover( $publish )['requeued'] === 1, 'Recovery retry is published' );
}
$recoveryTime += 301;
check( $recovery->recover( $publish )['failed'] === 1 && $recovery->activeCount() === 0,
	'Three unsuccessful recoveries release the slot and require manual intervention' );
check( $recovery->recover( $publish )['scheduled'] === 0, 'Failed imports never retry automatically' );
$recovery->resume( $rid );
check( !isset( $recovery->read( $rid )['recoveryAttempts'] ), 'Manual resume resets the recovery budget' );
$recoveryTime += 301;
$recovery->recover( $publish );
$recoveryTime += 60;
$recovery->recover( $publish );
$state = $recovery->read( $rid );
$state['pages']++;
$recovery->save( $rid, $state );
$recoveryTime += 301;
$recovery->recover( $publish );
check( $recovery->read( $rid )['recoveryAttempts'] === 1, 'Committed progress resets the no-progress recovery budget' );
$other = new RunStore( $root, 'recovery-other', 'UTC', static fn () => $recoveryTime );
check( $other->recover( $publish )['scheduled'] === 0, 'Recovery is wiki scoped' );
$paused = seedReport( $other, [ 'item' => 'paused', 'status' => 'needs-attention' ] );
check( $other->recover( $publish )['scheduled'] === 0, 'Dependency pauses require manual resume' );
if ( function_exists( 'pcntl_fork' ) ) {
	$killTime = time();
	$killed = new RunStore( $root, 'killed-worker', 'UTC', static function () use ( &$killTime ) { return $killTime; } );
	$kid = $killed->create( [ 'item' => 'killed-worker' ] );
	$ready = $root . '/worker-ready';
	$script = $root . '/decompressor.sh';
	file_put_contents( $script, "#!/bin/sh\ntouch " . escapeshellarg( $ready ) . "\nsleep 2\nprintf test\n" );
	chmod( $script, 0700 );
	$pid = pcntl_fork();
	if ( $pid === 0 ) {
		$held = $killed->lock( $kid );
		$killed->activate( $kid );
		$killed->heartbeat( $kid, 'child-worker' );
		( new Bundle( '/bin/false', 1000, 10, null, $held ) )->zstd( $script, 'unused', $root . '/extracted' );
		exit( 1 );
	}
	if ( $pid < 0 ) { throw new RuntimeException( 'Cannot fork worker test' ); }
	$deadline = microtime( true ) + 5;
	while ( !is_file( $ready ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
	check( is_file( $ready ), 'Child worker acquired its lock and published heartbeat' );
	$killTime += 301;
	check( $killed->recover( $publish )['stalled'] === 1, 'Live child cannot be replaced after lease expiry' );
	posix_kill( $pid, SIGKILL );
	pcntl_waitpid( $pid, $exitStatus );
	check( $killed->recover( $publish )['stalled'] === 1, 'Surviving decompressor retains the killed worker lock' );
	$deadline = microtime( true ) + 5;
	$scheduled = 0;
	while ( !$scheduled && microtime( true ) < $deadline ) {
		usleep( 10000 );
		$scheduled = $killed->recover( $publish )['scheduled'];
	}
	check( $scheduled === 1, 'Recovery starts after the orphan decompressor exits' );
	// Competing scanners can publish a due retry only once.
	$killTime += 60;
	$publishedFile = $root . '/recovery-published';
	$children = [];
	for ( $i = 0; $i < 8; $i++ ) {
		$pid = pcntl_fork();
		if ( $pid === 0 ) {
			$killed->recover( static function ( $id ) use ( $publishedFile ): void {
				file_put_contents( $publishedFile, $id . "\n", FILE_APPEND | LOCK_EX );
			} );
			exit( 0 );
		}
		if ( $pid < 0 ) { throw new RuntimeException( 'Cannot fork recovery test' ); }
		$children[] = $pid;
	}
	foreach ( $children as $pid ) { pcntl_waitpid( $pid, $exitStatus ); }
	check( file( $publishedFile, FILE_IGNORE_NEW_LINES ) === [ $kid ], 'Concurrent recovery scanners publish one retry' );
}
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root,
	FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $iterator as $file ) {
	$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
}
rmdir( $root );
echo "PASS: $checks checks\n";
