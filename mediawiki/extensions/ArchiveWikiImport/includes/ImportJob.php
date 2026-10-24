<?php
/** @license GPL-2.0-or-later */
namespace MediaWiki\Extension\ArchiveWikiImport;

use MediaWiki\Import\ImportStreamSource;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use MediaWiki\Upload\UploadBase;
use MediaWiki\Upload\UploadFromFile;
use RuntimeException;
use Throwable;
use Wikimedia\Rdbms\IDBAccessObject;

class ImportJob extends Job implements GenericParameterJob {
	public function __construct( array $params ) {
		parent::__construct( 'archiveWikiImport', $params );
		// Checkpoints commit each page/file before publishing progress. Let this job
		// own those commits instead of wrapping the entire import in JobRunner's round.
		$this->executionFlags |= self::JOB_NO_EXPLICIT_TRX_ROUND;
	}
	private static function isDuplicate( $revision, MediaWikiServices $services ): bool {
		$hash = $revision->getSha1Base36();
		if ( !$hash ) {
			return false;
		}
		$db = $services->getConnectionProvider()->getPrimaryDatabase();
		$ids = $db->newSelectQueryBuilder()->select( 'rev_id' )->from( 'revision' )
			->join( 'page', null, 'page_id = rev_page' )->where( [
				'page_namespace' => $revision->getTitle()->getNamespace(),
				'page_title' => $revision->getTitle()->getDBkey(),
				'rev_timestamp' => $db->timestamp( $revision->getTimestamp() )
			] )->caller( __METHOD__ )->fetchFieldValues();
		foreach ( $ids as $id ) {
			$existing = $services->getRevisionStore()->getRevisionById( $id, IDBAccessObject::READ_LATEST );
			if ( $existing && $existing->getSha1() === $hash ) {
				return true;
			}
		}
		return false;
	}
	public function run() {
		$store = new RunStore();
		$id = $this->params['run'];
		$lock = $store->lock( $id );
		if ( !$lock ) {
			return true;
		}
		$state = null;
		try {
			$state = $store->read( $id );
			if ( !in_array( $state['status'], [ 'queued', 'running' ], true ) ||
				( isset( $state['recoveryDue'] ) && time() < $state['recoveryDue'] ) ) {
				return true;
			}
			$services = MediaWikiServices::getInstance();
			$config = $services->getMainConfig();
			$user = $services->getUserFactory()->newFromId( $state['user'] );
			foreach ( [ 'archivewikiimport', 'importupload' ] as $right ) {
				if ( !$services->getPermissionManager()->userHasRight( $user, $right ) ) {
					throw new RuntimeException( 'The initiating user no longer has import permissions.' );
				}
			}
			if ( $services->getReadOnlyMode()->isReadOnly() || $user->getBlock() ) {
				throw new RuntimeException( 'Wiki is read-only or initiating user is blocked.' );
			}
			$state = $store->activate( $id );
			$worker = gethostname() . ':' . getmypid() . ':' . bin2hex( random_bytes( 8 ) );
			$lastHeartbeat = 0;
			$pulse = static function () use ( $store, $id, $worker, &$lastHeartbeat ): void {
				if ( time() - $lastHeartbeat >= 30 ) {
					$store->heartbeat( $id, $worker );
					$lastHeartbeat = time();
				}
			};
			$pulse();
			$dir = $store->directory( $id );
			$source = new ArchiveSource( $pulse );
			$bundle = new Bundle( $config->get( 'ArchiveWikiImportSevenZip' ),
				$config->get( 'ArchiveWikiImportMaxExpandedBytes' ), $config->get( 'ArchiveWikiImportMaxEntries' ), $pulse, $lock );
			if ( $state['phase'] === 'pages' ) {
				$file = $state['dump'];
				$archive = $dir . '/dump.download';
				$source->download( $state['item'], $file, $archive, $config->get( 'ArchiveWikiImportMaxDownloadBytes' ) );
				$xml = $archive;
				if ( str_ends_with( strtolower( $file['name'] ), '.xml.zst' ) ) {
					$xml = $dir . '/dump.xml';
					$bundle->zstd( $config->get( 'ArchiveWikiImportZstd' ), $archive, $xml );
				} elseif ( !str_ends_with( strtolower( $file['name'] ), '.xml' ) ) {
					$entries = $bundle->entries( $archive, $dir . '/listing' );
					if ( count( $entries ) !== 1 || !str_ends_with( strtolower( $entries[0]['name'] ), '.xml' ) ) {
						throw new RuntimeException( 'A compressed dump must contain exactly one XML file.' );
					}
					$xml = $dir . '/dump.xml';
					$bundle->extract( $archive, $entries[0], $xml );
				}
				// Check the entire dump before the first write, including historical revisions.
				$state['preflight'] = ( new DependencyScanner( $pulse ) )->inspect( $xml,
					$services->getContentHandlerFactory()->getContentModels(),
					array_keys( ExtensionRegistry::getInstance()->getAllThings() ),
					DependencyScanner::providers( MW_INSTALL_PATH . '/extensions' ) );
				if ( $state['preflight']['missingModels'] ) {
					$state['status'] = 'needs-attention';
					$state['error'] = 'Required content handlers are missing. Enable the extensions listed below, then resume the import.';
					$store->save( $id, $state );
					return true;
				}
				$store->save( $id, $state );
				// Do not use setPageOffset: core skips siteinfo too, breaking custom namespace mapping.
				// Other jobs/upload sniffers can leave errors in libxml's process-global queue.
				libxml_clear_errors();
				$handle = fopen( $xml, 'rb' );
				$importer = $services->getWikiImporterFactory()->getWikiImporter( new ImportStreamSource( $handle ), $user );
				$importer->setUsernamePrefix( 'archive-' . $state['item'], false );
				$importer->setImportUploads( false );
				// Never follow embedded upload URLs or restore source logs as privileged local actions.
				$importer->setLogItemCallback( static fn () => false );
				$seen = 0;
				$skip = false;
				$revisionCount = 0;
				$mainPageRevision = null;
				$defaultMainPageId = 0;
				$previousPage = $importer->setPageCallback( static function ( $titles ) use (
					&$seen, &$skip, &$revisionCount, &$state, &$previousPage,
					&$mainPageRevision, &$defaultMainPageId, $services, $pulse
				) {
					$pulse();
					$seen++;
					$revisionCount = 0;
					$mainPageRevision = null;
					$defaultMainPageId = !empty( $state['replaceDefaultMainPage'] )
						? MainPageRestorer::defaultRevisionId( $titles[0], $services ) : 0;
					$skip = $seen <= $state['pages'];
					if ( !$skip ) {
						$previousPage( $titles );
					}
				} );
				$revision = $importer->setRevisionCallback( static function ( $rev ) use ( &$skip, &$revisionCount, &$revision, $services, &$mainPageRevision, &$defaultMainPageId, $pulse ) {
					$pulse();
					if ( $skip ) {
						return false;
					}
					$result = $revision( $rev );
					if ( $result === false && !self::isDuplicate( $rev, $services ) ) {
						throw new RuntimeException( 'A revision could not be imported; see the wiki import logs.' );
					}
					if ( $defaultMainPageId && ( !$mainPageRevision || $rev->getTimestamp() >= $mainPageRevision->getTimestamp() ) ) {
						$mainPageRevision = $rev;
					}
					$revisionCount++;
					return $result;
				} );
				$finish = $importer->setPageOutCallback( static function ( ...$args ) use (
					&$skip, &$seen, &$revisionCount, &$state, $store, $id, &$finish, $services,
					&$mainPageRevision, &$defaultMainPageId, $user
				) {
					if ( !$skip ) {
						$finish( ...$args );
						if ( $mainPageRevision && MainPageRestorer::restore( $mainPageRevision->getTitle(),
							$defaultMainPageId, $mainPageRevision, $user, $state['item'], $services ) ) {
							$state['mainPageRestored'] = true;
						}
						$services->getDBLoadBalancerFactory()->commitPrimaryChanges( __METHOD__ );
						$state['pages'] = $seen;
						$state['revisions'] += $revisionCount;
						$store->save( $id, $state );
					}
				} );
				$importer->setNoticeCallback( static function ( $message, $params ) use ( &$state ) {
					if ( count( $state['warnings'] ) < 100 ) {
						$state['warnings'][] = wfMessage( $message, $params )->inLanguage( 'en' )->text();
					}
				} );
				try {
					if ( !$importer->doImport() ) {
						throw new RuntimeException( 'MediaWiki could not complete the XML import.' );
					}
				} finally {
					fclose( $handle );
				}
				$state['phase'] = 'images';
				$store->save( $id, $state );
			}
			if ( $state['imageBundle'] ) {
				$file = $state['imageBundle'];
				$archive = $dir . '/images.download';
				$source->download( $state['item'], $file, $archive, $config->get( 'ArchiveWikiImportMaxDownloadBytes' ) );
				$entries = $bundle->entries( $archive, $dir . '/listing' );
				foreach ( $entries as $index => $entry ) {
					$pulse();
					if ( $index < $state['imageOffset'] ) {
						continue;
					}
					$name = basename( $entry['name'] );
					$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
					$title = Title::makeTitleSafe( NS_FILE, $name );
					$skip = !$title || !$entry['size'] || !in_array( $extension, $config->get( 'FileExtensions' ), true ) ||
						preg_match( '~(^|/)(?:thumb|archive|deleted)(/|$)~i', $entry['name'] );
					if ( !$skip ) {
						$local = $services->getRepoGroup()->getLocalRepo()->newFile( $title );
						$skip = $local->exists();
					}
					if ( $skip ) {
						$state['skippedImages']++;
					} else {
						$path = $dir . '/upload.tmp';
						$bundle->extract( $archive, $entry, $path );
						$upload = new UploadFromFile();
						$upload->initializePathInfo( $name, $path, filesize( $path ) );
						if ( !$upload->authorizeUpload( $user )->isGood() || $upload->verifyUpload()['status'] !== UploadBase::OK ) {
							throw new RuntimeException( 'Upload permissions or file validation rejected: ' . $name );
						}
						$status = $upload->performUpload( 'Imported from https://archive.org/details/' . $state['item'], false, false, $user );
						if ( !$status->isGood() ) {
							throw new RuntimeException( 'Image import failed: ' . $name );
						}
						$state['images']++;
					}
					$services->getDBLoadBalancerFactory()->commitPrimaryChanges( __METHOD__ );
					$state['imageOffset'] = $index + 1;
					$store->save( $id, $state );
				}
			}
			$state['status'] = 'complete';
			$store->save( $id, $state );
			// Keep only the report/checkpoints after success; failed runs retain verified downloads.
			foreach ( [ 'dump.download', 'dump.xml', 'images.download', 'upload.tmp' ] as $name ) {
				if ( is_file( $dir . '/' . $name ) ) {
					unlink( $dir . '/' . $name );
				}
			}
			return true;
		} catch ( Throwable $e ) {
			if ( $state === null ) {
				throw $e;
			}
			$state['status'] = 'failed';
			$state['error'] = $e->getMessage();
			$store->save( $id, $state );
			$this->error = $e->getMessage();
			// Manual retry only, to avoid retry storms for corrupt or unsupported archives.
			return true;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}
}
