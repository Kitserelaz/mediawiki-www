<?php
/** @license GPL-2.0-or-later */
/** Run only against the disposable database created by run-integration.sh. */
use MediaWiki\Extension\ArchiveWikiImport\ArchiveSource;
use MediaWiki\Extension\ArchiveWikiImport\ImportJob;
use MediaWiki\Extension\ArchiveWikiImport\RunStore;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Extension\ArchiveWikiImport\Bundle;
use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWiki\Content\WikitextContent;

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class ArchiveWikiImportIntegration extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'dependencies-resume', 'Test resuming after the required content handler is enabled.' );
	}
	private function seedReport( RunStore $store, array $state ): string {
		$id = bin2hex( random_bytes( 16 ) );
		mkdir( $store->directory( $id ), 0700 );
		$store->save( $id, $state + [ 'created' => '2000-01-01T00:00:00+00:00', 'status' => 'queued',
			'pages' => 0, 'revisions' => 0, 'images' => 0, 'phase' => 'pages' ] );
		return $id;
	}
	public function execute() {
		if ( $this->getConfig()->get( 'DBname' ) !== 'archivewikiimport_disposable' ) {
			$this->fatalError( 'Refusing to test outside the disposable integration database.' );
		}
		$services = $this->getServiceContainer();
		$user = $services->getUserFactory()->newFromName( 'Admin' );
		$store = new RunStore();
		if ( $this->hasOption( 'dependencies-resume' ) ) {
			$id = trim( file_get_contents( $this->getConfig()->get( 'ArchiveWikiImportDirectory' ) . '/dependency-run' ) );
			$services->getDBLoadBalancerFactory()->commitPrimaryChanges( __METHOD__ );
			$store->resume( $id );
			$result = $services->getJobRunner()->executeJob( new ImportJob( [ 'run' => $id ] ) );
			$state = $store->read( $id );
			$this->verify( $result['status'] && $state['status'] === 'complete', 'Resume after enabling TemplateStyles: ' . ( $state['error'] ?? $result['error'] ) );
			$this->verify( !$state['preflight']['missingModels'], 'Dependency blocker cleared on resume' );
			$page = $services->getWikiPageFactory()->newFromTitle( Title::newFromText( 'Dependency stylesheet' ) );
			$this->verify( $page->getContent()->getModel() === 'sanitized-css', 'Original stylesheet content model preserved' );
			$this->output( "PASS: dependency recovery through JobRunner\n" );
			return;
		}

		$special = $services->getSpecialPageFactory()->getPage( 'ArchiveWikiImport' );
		$context = new RequestContext();
		$context->setUser( $user );
		$context->setTitle( Title::newFromText( 'Special:ArchiveWikiImport' ) );
		$context->setRequest( new FauxRequest() );
		$special->setContext( $context );
		$special->execute( null );
		$this->verify( str_contains( $context->getOutput()->getHTML(), 'awi-source' ), 'Special page registration and form rendering' );
		$context->getOutput()->clearHTML();
		$context->setRequest( new FauxRequest( [ 'operation' => 'start', 'source' => 'wiki-test' ], true ) );
		$special->execute( null );
		$this->verify( str_contains( $context->getOutput()->getHTML(), 'cdx-message--error' ), 'CSRF protection rejects tokenless start' );

		$text1 = 'First archived version';
		$text2 = 'Second archived version';
		$revision = static function ( $id, $timestamp, $text ) {
			$hash = Wikimedia\base_convert( sha1( $text ), 16, 36, 31 );
			return '<revision><id>' . $id . '</id><timestamp>' . $timestamp . '</timestamp>' .
				'<contributor><username>Admin</username><id>1</id></contributor>' .
				'<comment>Original edit summary</comment><model>wikitext</model><format>text/x-wiki</format>' .
				'<text xml:space="preserve">' . $text . '</text><sha1>' . $hash . '</sha1></revision>';
		};
		$xml = '<mediawiki xmlns="http://www.mediawiki.org/xml/export-0.11/" version="0.11" xml:lang="en">' .
			'<siteinfo><sitename>Fixture</sitename><dbname>fixture</dbname><base>https://example.org/wiki/Main_Page</base>' .
			'<generator>MediaWiki 1.43</generator><case>first-letter</case><namespaces>' .
			'<namespace key="0" case="first-letter"/><namespace key="6" case="first-letter">File</namespace>' .
			'<namespace key="3000" case="first-letter">TestSpace</namespace></namespaces></siteinfo>' .
			'<page><title>Archived page</title><ns>0</ns><id>10</id>' .
			$revision( 11, '2020-01-01T00:00:00Z', $text1 ) . $revision( 12, '2021-01-01T00:00:00Z', $text2 ) . '</page>' .
			'<page><title>TestSpace:Archived</title><ns>3000</ns><id>20</id>' .
			$revision( 21, '2022-01-01T00:00:00Z', 'Custom namespace content' ) . '</page>' .
			'<page><title>File:Archived.png</title><ns>6</ns><id>30</id>' .
			$revision( 31, '2022-01-01T00:00:00Z', 'Original image description and license' ) . '</page></mediawiki>';
		$mainTitle = Title::newMainPage();
		$mainXml = '<page><title>' . htmlspecialchars( $mainTitle->getPrefixedText(), ENT_XML1 ) . '</title><ns>0</ns><id>60</id>' .
			$revision( 61, '2020-01-01T00:00:00Z', 'Old archived main page' ) .
			$revision( 62, '2021-01-01T00:00:00Z', 'Newest archived main page' ) . '</page>';
		$xml = str_replace( '</mediawiki>', $mainXml . '</mediawiki>', $xml );
		$tmp = $this->getConfig()->get( 'ArchiveWikiImportDirectory' );
		file_put_contents( $tmp . '/fixture.xml', $xml );
		$pipes = [];
		$process = proc_open( [ '/usr/bin/zstd', '-f', $tmp . '/fixture.xml', '-o', $tmp . '/fixture.xml.zst' ],
			[ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes );
		$this->verify( proc_close( $process ) === 0, 'Zstandard fixture compression' );
		$png = imagecreatetruecolor( 10, 10 );
		imagepng( $png, $tmp . '/fixture.png' );
		$zip = new ZipArchive();
		$zip->open( $tmp . '/images.zip', ZipArchive::CREATE );
		$zip->addFile( $tmp . '/fixture.png', 'images/a/ab/Archived.png' );
		$zip->addFile( $tmp . '/fixture.png', 'images/thumb/Excluded.png' );
		$zip->close();
		$file = static fn ( $path, $name, $kind ) => [ 'name' => $name, 'kind' => $kind,
			'size' => filesize( $path ), 'sha1' => hash_file( 'sha1', $path ) ];
		$definition = [ 'item' => 'wiki-integration-fixture', 'user' => $user->getId(), 'replaceDefaultMainPage' => false,
			'dump' => $file( $tmp . '/fixture.xml.zst', 'fixture-history.xml.zst', 'dump' ),
			'imageBundle' => $file( $tmp . '/images.zip', 'fixture-images.zip', 'images' ) ];
		$seed = static function ( $id ) use ( $tmp, $store ) {
			copy( $tmp . '/fixture.xml.zst', $store->directory( $id ) . '/dump.download' );
			copy( $tmp . '/images.zip', $store->directory( $id ) . '/images.download' );
		};
		$id = $store->create( $definition );
		$seed( $id );
		// Exercise registration through JobFactory, not just direct construction.
		$job = $services->getJobFactory()->newJob( 'archiveWikiImport', [ 'run' => $id ] );
		$this->verify( $job instanceof ImportJob, 'Job registration' );
		$services->getDBLoadBalancerFactory()->commitPrimaryChanges( __METHOD__ );
		$result = $services->getJobRunner()->executeJob( $job );
		$this->verify( $result['status'] && !$result['caught'], 'JobRunner execution succeeds: ' . $result['error'] );
		$state = $store->read( $id );
		$this->verify( $state['status'] === 'complete', 'Import completed: ' . ( $state['error'] ?? '' ) );
		$this->verify( $state['pages'] === 4 && $state['revisions'] === 6, 'Page/history counts' );
		$this->verify( $state['images'] === 1 && $state['skippedImages'] === 1, 'Image filtering and upload' );
		$page = $services->getWikiPageFactory()->newFromTitle( Title::newFromText( 'Archived page' ) );
		$this->verify( $page->getContent()->getText() === $text2, 'Latest revision restored' );
		$db = $services->getConnectionProvider()->getPrimaryDatabase();
		$count = (int)$db->newSelectQueryBuilder()->select( 'COUNT(*)' )->from( 'revision' )
			->where( [ 'rev_page' => $page->getId() ] )->caller( __METHOD__ )->fetchField();
		$this->verify( $count === 2, 'Both historical revisions stored' );
		$rev = $page->getRevisionRecord();
		$this->verify( $rev->getTimestamp() === '20210101000000', 'Original timestamps' );
		$this->verify( str_contains( $rev->getUser()->getName(), 'archive-wiki-integration-fixture' ), 'Foreign contributor attribution' );
		$image = $services->getRepoGroup()->getLocalRepo()->newFile( Title::makeTitle( NS_FILE, 'Archived.png' ) );
		$this->verify( $image->exists(), 'Image stored in the local repository' );
		$description = $services->getWikiPageFactory()->newFromTitle( Title::makeTitle( NS_FILE, 'Archived.png' ) );
		$this->verify( $description->getContent()->getText() === 'Original image description and license', 'Image description preserved' );
		$mainPage = $services->getWikiPageFactory()->newFromTitle( $mainTitle );
		$this->verify( $mainPage->getRevisionRecord()->getUser()->getName() === 'MediaWiki default', 'Unchecked option preserves the newer installer main page' );
		$definition['replaceDefaultMainPage'] = true;
		$restore = $store->create( $definition );
		$seed( $restore );
		$services->getDBLoadBalancerFactory()->commitPrimaryChanges( __METHOD__ );
		$result = $services->getJobRunner()->executeJob( new ImportJob( [ 'run' => $restore ] ) );
		$mainPage = $services->getWikiPageFactory()->newFromTitle( $mainTitle );
		$mainPage->loadPageData( \Wikimedia\Rdbms\IDBAccessObject::READ_LATEST );
		$this->verify( $result['status'] && !empty( $store->read( $restore )['mainPageRestored'] ), 'Checked option restores the default main page through JobRunner' );
		$this->verify( $mainPage->getContent()->getText() === 'Newest archived main page', 'Newest archived content becomes current despite the installer timestamp' );
		$this->verify( (int)$db->newSelectQueryBuilder()->select( 'COUNT(*)' )->from( 'revision' )
			->where( [ 'rev_page' => $mainPage->getId() ] )->caller( __METHOD__ )->fetchField() === 4, 'Both source revisions, installer revision and restoration edit are retained' );
		$mainPage->newPageUpdater( $user )->setContent( 'main', new WikitextContent( 'Local main-page customization' ) )
			->saveRevision( 'Local customization after importing' );
		$customMainRevision = $mainPage->getRevisionRecord()->getId();
		// Simulate an interruption after the first page; replay must retain namespace mapping,
		// skip completed work, and recognize already committed revisions in the partial tail.
		$retry = $store->create( $definition );
		$seed( $retry );
		$checkpoint = $store->read( $retry );
		$checkpoint['pages'] = 1;
		$checkpoint['revisions'] = 2;
		$checkpoint['status'] = 'failed';
		$store->save( $retry, $checkpoint );
		$services->getDBLoadBalancerFactory()->commitPrimaryChanges( __METHOD__ );
		// Simulate worker loss after a committed checkpoint and recover the same run.
		$checkpoint['status'] = 'running';
		$store->save( $retry, $checkpoint );
		$recoveryTime = time() + 601;
		$recoveryStore = new RunStore( $this->getConfig()->get( 'ArchiveWikiImportDirectory' ),
			$this->getConfig()->get( 'DBname' ) . ':' . $this->getConfig()->get( 'DBprefix' ), 'UTC',
			static function () use ( &$recoveryTime ) { return $recoveryTime; } );
		$published = [];
		$publish = static function ( $id ) use ( &$published, $services ): void {
			$services->getJobQueueGroup()->push( new ImportJob( [ 'run' => $id ] ) );
			$published[] = $id;
		};
		$this->verify( $recoveryStore->recover( $publish )['scheduled'] === 1, 'Abandoned worker enters recovery backoff' );
		$recoveryTime += 60;
		$this->verify( $recoveryStore->recover( $publish )['requeued'] === 1 && $published === [ $retry ],
			'Recovery publishes the original checkpointed run to the job queue' );
		$result = $services->getJobRunner()->executeJob( new ImportJob( [ 'run' => $retry ] ) );
		$this->verify( $result['status'] && !$result['caught'], 'JobRunner resume succeeds: ' . $result['error'] );
		$resumed = $store->read( $retry );
		$this->verify( $resumed['status'] === 'complete', 'Resume completed: ' . ( $resumed['error'] ?? '' ) );
		$activity = $store->workerActivity( $retry );
		$this->verify( $activity && $activity['leaseExpires'] - $activity['heartbeat'] === RunStore::LEASE_SECONDS,
			'JobRunner publishes a worker identity and renewable lease' );
		$this->verify( $resumed['pages'] === 4 && $resumed['images'] === 0, 'Resume skips existing images and completed pages' );
		$custom = $services->getWikiPageFactory()->newFromTitle( Title::makeTitle( 3000, 'Archived' ) );
		$this->verify( $custom->exists(), 'Custom namespace mapping retained during resume' );
		$this->verify( (int)$db->newSelectQueryBuilder()->select( 'COUNT(*)' )->from( 'revision' )
			->where( [ 'rev_page' => $custom->getId() ] )->caller( __METHOD__ )->fetchField() === 1, 'Partial replay does not duplicate revisions' );
		$mainPage = $services->getWikiPageFactory()->newFromTitle( $mainTitle );
		$this->verify( $mainPage->getContent()->getText() === 'Local main-page customization', 'Edited main pages survive imports even with replacement checked' );
		$this->verify( empty( $resumed['mainPageRestored'] ), 'Retry does not create another main-page restoration edit' );
		$this->verify( !is_file( $store->directory( $id ) . '/dump.download' ), 'Successful downloads cleaned up' );
		// The missing model is on the second page: the first page must remain untouched.
		$sentinel = '<page><title>Dependency sentinel</title><ns>0</ns><id>40</id>' .
			$revision( 41, '2022-01-01T00:00:00Z', 'Preflight must prevent this write' ) . '</page>';
		$stylesheet = '<page><title>Dependency stylesheet</title><ns>0</ns><id>50</id>' .
			str_replace( [ '<model>wikitext</model>', '<format>text/x-wiki</format>' ],
				[ '<model>sanitized-css</model>', '<format>text/css</format>' ],
				$revision( 51, '2022-01-01T00:00:00Z', '.example { color: red; }' ) ) . '</page>';
		$dependencyXml = preg_replace( '~<page>.*</mediawiki>~s', $sentinel . $stylesheet . '</mediawiki>', $xml );
		file_put_contents( $tmp . '/dependency.xml', $dependencyXml );
		$dependency = $store->create( [ 'item' => 'wiki-dependency-fixture', 'user' => $user->getId(),
			'dump' => $file( $tmp . '/dependency.xml', 'dependency.xml', 'dump' ), 'imageBundle' => null ] );
		copy( $tmp . '/dependency.xml', $store->directory( $dependency ) . '/dump.download' );
		$services->getDBLoadBalancerFactory()->commitPrimaryChanges( __METHOD__ );
		$result = $services->getJobRunner()->executeJob( new ImportJob( [ 'run' => $dependency ] ) );
		$blocked = $store->read( $dependency );
		$this->verify( $result['status'] && $blocked['status'] === 'needs-attention', 'Missing dependency pauses the job without an exception' );
		$this->verify( $blocked['pages'] === 0 && !Title::newFromText( 'Dependency sentinel' )->exists(), 'Dependency scan runs before any page writes' );
		$this->verify( $blocked['preflight']['missingModels']['sanitized-css'] === [ 'TemplateStyles' ], 'TemplateStyles requirement appears in the report' );
		$context->getOutput()->clearHTML();
		$context->setRequest( new FauxRequest( [ 'run' => $dependency ] ) );
		$special->execute( null );
		$reportHtml = $context->getOutput()->getHTML();
		$this->verify( str_contains( $reportHtml, 'sanitized-css: TemplateStyles' ) &&
			str_contains( $reportHtml, 'Extension dependency checks' ), 'Dependency report renders actionable requirements' );
		$queuePage = $services->getSpecialPageFactory()->getPage( 'ArchiveWikiImportQueue' );
		$queuePage->setContext( $context );
		$context->getOutput()->clearHTML();
		$context->setRequest( new FauxRequest() );
		$queuePage->execute( null );
		$this->verify( str_contains( $context->getOutput()->getHTML(), $dependency ), 'Queue lists attention-needed imports by default' );
		$context->getOutput()->clearHTML();
		$context->setRequest( new FauxRequest( [ 'status' => 'complete', 'requester' => 'Admin' ] ) );
		$queuePage->execute( null );
		$this->verify( str_contains( $context->getOutput()->getHTML(), $id ) &&
			!str_contains( $context->getOutput()->getHTML(), $dependency ), 'Completed queue filter links to existing progress reports' );
		$context->getOutput()->clearHTML();
		$context->setRequest( new FauxRequest( [ 'status' => 'all', 'requester' => 'No such queue user' ] ) );
		$queuePage->execute( null );
		$this->verify( str_contains( $context->getOutput()->getHTML(), 'No imports match these filters.' ), 'Unknown requester does not match everyone' );
		for ( $i = 0; $i < 51; $i++ ) {
			$this->seedReport( $store, [ 'item' => 'queue-test-' . $i, 'user' => $user->getId(), 'status' => 'failed' ] );
		}
		$this->seedReport( $store, [ 'item' => 'queue-test-<script>alert(1)</script>', 'user' => $user->getId(), 'status' => 'failed' ] );
		$context->getOutput()->clearHTML();
		$context->setRequest( new FauxRequest( [ 'status' => 'all', 'item' => 'queue-test-' ] ) );
		$queuePage->execute( null );
		$this->verify( substr_count( $context->getOutput()->getHTML(), '<tr>' ) === 51 &&
			str_contains( $context->getOutput()->getHTML(), 'offset=50' ), 'Queue paginates at 50 and offers the next page' );
		$context->getOutput()->clearHTML();
		$context->setRequest( new FauxRequest( [ 'status' => 'all', 'item' => 'queue-test-', 'offset' => 50 ] ) );
		$queuePage->execute( null );
		$this->verify( substr_count( $context->getOutput()->getHTML(), '<tr>' ) === 3 &&
			str_contains( $context->getOutput()->getHTML(), 'offset=0' ), 'Second queue page preserves filters and offers previous page' );
		$context->getOutput()->clearHTML();
		$context->setRequest( new FauxRequest( [ 'status' => 'all', 'item' => '<script>' ] ) );
		$queuePage->execute( null );
		$this->verify( str_contains( $context->getOutput()->getHTML(), '&lt;script&gt;' ) &&
			!str_contains( $context->getOutput()->getHTML(), '<script>alert(1)</script>' ), 'Queue escapes report and filter content' );
		$guestContext = new RequestContext();
		$guestContext->setUser( $services->getUserFactory()->newAnonymous() );
		$guestContext->setTitle( Title::newFromText( 'Special:ArchiveWikiImportQueue' ) );
		$guestContext->setRequest( new FauxRequest() );
		$queuePage->setContext( $guestContext );
		$denied = false;
		try {
			$queuePage->execute( null );
		} catch ( \MediaWiki\Exception\PermissionsError $e ) {
			$denied = true;
		}
		$this->verify( $denied, 'Queue requires archivewikiimport permission' );
		$capacityRuns = [];
		for ( $i = 0; $i < RunStore::MAX_ACTIVE; $i++ ) {
			$capacityRuns[] = $this->seedReport( $store, [ 'item' => 'capacity-' . $i, 'user' => $user->getId() ] );
		}
		$context->getOutput()->clearHTML();
		$context->setRequest( new FauxRequest( [ 'operation' => 'resume', 'run' => $dependency,
			'token' => $user->getEditToken( 'archivewikiimport' ) ], true ) );
		$special->execute( null );
		$this->verify( str_contains( $context->getOutput()->getHTML(), '3 active imports' ) &&
			$store->read( $dependency )['status'] === 'needs-attention', 'Resume form enforces the wiki limit before requeuing' );
		$queuePage->setContext( $context );
		$context->getOutput()->clearHTML();
		$context->setRequest( new FauxRequest() );
		$queuePage->execute( null );
		$this->verify( str_contains( $context->getOutput()->getHTML(), 'Active imports: 3 of 3' ), 'Queue shows active usage and the per-wiki limit' );
		foreach ( $capacityRuns as $capacityId ) {
			$finished = $store->read( $capacityId );
			$finished['status'] = 'complete';
			$store->save( $capacityId, $finished );
		}
		$failed = $store->create( [ 'item' => 'fifth-new-import', 'status' => 'failed', 'user' => $user->getId() ] );
		$services->getJobRunner()->executeJob( new ImportJob( [ 'run' => $failed ] ) );
		$this->verify( $store->read( $failed )['status'] === 'failed' && !$store->workerActivity( $failed ),
			'Late duplicate jobs cannot restart a failed import' );
		$context->getOutput()->clearHTML();
		$context->setRequest( new FauxRequest( [ 'operation' => 'start', 'source' => 'must-not-be-fetched',
			'acknowledge' => '1', 'token' => $user->getEditToken( 'archivewikiimport' ) ], true ) );
		$special->execute( null );
		$this->verify( str_contains( $context->getOutput()->getHTML(), 'daily limit of 5' ) &&
			$store->dailyUsage()['count'] === 5, 'Start form rejects the sixth daily import before any archive request' );
		$context->getOutput()->clearHTML();
		$context->setRequest( new FauxRequest() );
		$queuePage->execute( null );
		$this->verify( str_contains( $context->getOutput()->getHTML(), 'New imports today: 5 of 5' ), 'Queue displays daily usage and reset information' );
		file_put_contents( $tmp . '/dependency-run', $dependency );
		if ( getenv( 'AWI_LIVE_ARCHIVE' ) === '1' ) {
			$source = new ArchiveSource();
			$manifest = $source->discover( 'https://archive.org/details/wiki-mijopedia.skywiki.org-20250511' );
			$this->verify( count( $manifest['files'] ) === 2, 'Live example discovery' );
			foreach ( $manifest['files'] as $file ) {
				if ( $file['kind'] === 'dump' ) {
					$source->download( $manifest['id'], $file, $tmp . '/live.zst', 1048576 );
					$this->verify( hash_file( 'sha1', $tmp . '/live.zst' ) === $file['sha1'], 'Live download/redirect/checksum' );
					( new Bundle( '/usr/bin/7z', 10485760, 10000 ) )->zstd( '/usr/bin/zstd', $tmp . '/live.zst', $tmp . '/live.xml' );
					$this->verify( str_contains( file_get_contents( $tmp . '/live.xml' ), '<mediawiki' ), 'Live history dump decompresses' );
				} else {
					$source->download( $manifest['id'], $file, $tmp . '/live.7z', 10485760 );
					$bundle = new Bundle( '/usr/bin/7z', 104857600, 10000 );
					$entries = $bundle->entries( $tmp . '/live.7z', $tmp . '/listing' );
					$this->verify( count( $entries ) > 0, 'Live image 7z bundle lists successfully' );
					$bundle->extract( $tmp . '/live.7z', $entries[0], $tmp . '/live-image' );
					$this->verify( filesize( $tmp . '/live-image' ) === $entries[0]['size'], 'Live image entry extracts' );
				}
			}
		}
		$this->output( "PASS: isolated MediaWiki import integration\n" );
	}
	private function verify( bool $condition, string $message ): void {
		if ( !$condition ) {
			$this->fatalError( $message );
		}
		$this->output( "OK: $message\n" );
	}
}
$maintClass = ArchiveWikiImportIntegration::class;
require_once RUN_MAINTENANCE_IF_MAIN;
