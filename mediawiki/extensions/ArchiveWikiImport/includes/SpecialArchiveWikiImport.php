<?php
/** @license GPL-2.0-or-later */
namespace MediaWiki\Extension\ArchiveWikiImport;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use RuntimeException;
use Throwable;

class SpecialArchiveWikiImport extends SpecialPage {
	public function __construct() {
		parent::__construct( 'ArchiveWikiImport' );
	}
	public function getRestriction(): string {
		return 'archivewikiimport';
	}
	public function doesWrites() {
		return true;
	}
	protected function getGroupName() {
		return 'pagetools';
	}
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->checkPermissions();
		$this->getOutput()->disallowUserJs();
		$this->getOutput()->addModuleStyles( [ 'ext.archiveWikiImport', 'mediawiki.codex.messagebox.styles' ] );
		$this->getOutput()->addHTML( Html::element( 'p', [], $this->msg( 'archivewikiimport-intro' )->text() ) );
		$this->getOutput()->addHTML( Html::element( 'a', [
			'href' => SpecialPage::getTitleFor( 'ArchiveWikiImportQueue' )->getLocalURL()
		], $this->msg( 'archivewikiimport-view-queue' )->text() ) );
		$request = $this->getRequest();
		try {
			// Also validates the deployment before allowing a preview/start.
			$store = new RunStore();
			$this->getOutput()->addHTML( Html::element( 'p', [],
				$this->msg( 'archivewikiimport-active-limit', $store->activeCount(), RunStore::MAX_ACTIVE )->text() ) );
			$daily = $store->dailyUsage();
			$this->getOutput()->addHTML( Html::element( 'p', [], $this->msg( 'archivewikiimport-daily-limit',
				$daily['count'], $daily['limit'], $daily['resets'], $daily['timezone'] )->text() ) );
			$action = $request->getText( 'operation' );
			if ( $request->wasPosted() ) {
				$this->checkReadOnly();
				if ( !$this->getUser()->matchEditToken( $request->getText( 'token' ), 'archivewikiimport' ) ) {
					throw new RuntimeException( $this->msg( 'sessionfailure' )->text() );
				}
				if ( $action === 'start' || $action === 'resume' ) {
					if ( !$this->getAuthority()->isAllowed( 'importupload' ) ) {
						throw new RuntimeException( 'The importupload permission is also required.' );
					}
					if ( $action === 'start' ) {
						if ( !$request->getBool( 'acknowledge' ) ) {
							throw new RuntimeException( $this->msg( 'archivewikiimport-ack-required' )->text() );
						}
						$store->assertCanStart();
						$manifest = ( new ArchiveSource() )->discover( $request->getText( 'source' ) );
						$dump = $manifest['files'][$request->getText( 'dump' )] ?? null;
						$imagesName = $request->getText( 'images' );
						$images = $imagesName !== '' ? ( $manifest['files'][$imagesName] ?? null ) : null;
						if ( !$dump || $dump['kind'] !== 'dump' ||
							( $imagesName !== '' && ( !$images || $images['kind'] !== 'images' ) ) ) {
							throw new RuntimeException( 'Selected files are no longer available. Preview the archive again.' );
						}
						$limit = $this->getConfig()->get( 'ArchiveWikiImportMaxDownloadBytes' );
						if ( $dump['size'] + ( $images['size'] ?? 0 ) > $limit ) {
							throw new RuntimeException( 'Selected files exceed the configured download limit.' );
						}
						$id = $store->create( [ 'item' => $manifest['id'], 'dump' => $dump,
							'imageBundle' => $images, 'user' => $this->getUser()->getId(),
							'replaceDefaultMainPage' => $request->getBool( 'replaceDefaultMainPage' ) ] );
					} else {
						$id = $request->getText( 'run' );
						$state = $store->read( $id );
						if ( $state['status'] === 'complete' ) {
							throw new RuntimeException( 'This import is already complete.' );
						}
						if ( $state['user'] !== $this->getUser()->getId() ) {
							throw new RuntimeException( 'Only the initiating administrator can resume this run.' );
						}
						$store->resume( $id );
					}
					MediaWikiServices::getInstance()->getJobQueueGroup()->push( new ImportJob( [ 'run' => $id ] ) );
					$this->getOutput()->redirect( $this->getPageTitle()->getLocalURL( [ 'run' => $id ] ) );
					return;
				}
				if ( $action === 'preview' ) {
					$this->preview( ( new ArchiveSource() )->discover( $request->getText( 'source' ) ) );
					return;
				}
			}
			if ( $request->getText( 'run' ) !== '' ) {
				$this->report( $store, $request->getText( 'run' ) );
				return;
			}
		} catch ( Throwable $e ) {
			$this->getOutput()->addHTML( Html::errorBox( htmlspecialchars( $e->getMessage(), ENT_QUOTES ) ) );
		}
		$this->getOutput()->addHTML( $this->formStart( 'preview' ) .
			Html::label( $this->msg( 'archivewikiimport-source' )->text(), 'awi-source' ) .
			Html::input( 'source', $request->getText( 'source' ), 'text', [ 'id' => 'awi-source',
				'required' => true, 'class' => 'awi-source', 'placeholder' => 'https://archive.org/details/wiki-example' ] ) .
			Html::submitButton( $this->msg( 'archivewikiimport-preview' )->text() ) . Html::closeElement( 'form' ) );
	}
	private function formStart( string $operation ): string {
		return Html::openElement( 'form', [ 'method' => 'post', 'action' => $this->getPageTitle()->getLocalURL(),
			'class' => 'awi-form' ] ) . Html::hidden( 'operation', $operation ) .
			Html::hidden( 'token', $this->getUser()->getEditToken( 'archivewikiimport' ) );
	}
	private function preview( array $manifest ): void {
		$out = $this->getOutput();
		$out->addHTML( Html::element( 'h2', [], $manifest['title'] ) .
			Html::warningBox( htmlspecialchars( $this->msg( 'archivewikiimport-preview-warning' )->text(), ENT_QUOTES ) ) );
		$html = $this->formStart( 'start' ) . Html::hidden( 'source', $manifest['id'] );
		foreach ( [ 'dump' => 'dump', 'images' => 'images' ] as $field => $kind ) {
			$html .= Html::label( $this->msg( 'archivewikiimport-' . $field )->text(), 'awi-' . $field );
			$html .= Html::openElement( 'select', [ 'name' => $field, 'id' => 'awi-' . $field ] );
			if ( $field === 'images' ) {
				$html .= Html::element( 'option', [ 'value' => '' ], $this->msg( 'archivewikiimport-no-images' )->text() );
			}
			foreach ( $manifest['files'] as $file ) {
				if ( $file['kind'] === $kind ) {
					$html .= Html::element( 'option', [ 'value' => $file['name'] ],
						$file['name'] . ' (' . number_format( $file['size'] / 1048576, 1 ) . ' MiB)' );
				}
			}
			$html .= Html::closeElement( 'select' );
		}
		$html .= Html::openElement( 'label', [ 'class' => 'awi-ack' ] ) .
			Html::input( 'replaceDefaultMainPage', '1', 'checkbox', [ 'checked' => true ] ) . ' ' .
			htmlspecialchars( $this->msg( 'archivewikiimport-replace-mainpage' )->text(), ENT_QUOTES ) . Html::closeElement( 'label' );
		$html .= Html::openElement( 'label', [ 'class' => 'awi-ack' ] ) .
			Html::input( 'acknowledge', '1', 'checkbox', [ 'required' => true ] ) . ' ' .
			htmlspecialchars( $this->msg( 'archivewikiimport-ack' )->text(), ENT_QUOTES ) . Html::closeElement( 'label' ) .
			Html::submitButton( $this->msg( 'archivewikiimport-start' )->text() ) . Html::closeElement( 'form' );
		$out->addHTML( $html );
	}
	private function report( RunStore $store, string $id ): void {
		$state = $store->read( $id );
		$html = Html::element( 'h2', [], $this->msg( 'archivewikiimport-report' )->text() );
		$html .= Html::element( 'p', [], 'https://archive.org/details/' . $state['item'] );
		$html .= Html::openElement( 'dl', [ 'class' => 'awi-report' ] );
		foreach ( [ 'status', 'phase', 'pages', 'revisions', 'images', 'skippedImages', 'updated' ] as $key ) {
			$html .= Html::element( 'dt', [], $this->msg( 'archivewikiimport-report-' . strtolower( $key ) )->text() ) .
				Html::element( 'dd', [], (string)$state[$key] );
		}
		$html .= Html::closeElement( 'dl' );
		$activity = $store->workerActivity( $id );
		if ( $state['status'] === 'running' && $activity ) {
			$html .= Html::element( 'p', [], $this->msg( 'archivewikiimport-worker',
				$activity['worker'], gmdate( 'c', $activity['heartbeat'] ) )->text() );
			if ( $activity['leaseExpires'] < time() ) {
				$html .= Html::warningBox( htmlspecialchars( $this->msg( 'archivewikiimport-stalled' )->text(), ENT_QUOTES ) );
			}
		}
		if ( isset( $state['recoveryAttempts'] ) ) {
			$html .= Html::element( 'p', [], $this->msg( 'archivewikiimport-recovery',
				$state['recoveryAttempts'], RunStore::MAX_RECOVERIES )->text() );
		}
		if ( isset( $state['recoveryDue'] ) ) {
			$html .= Html::element( 'p', [], $this->msg( 'archivewikiimport-recovery-due', gmdate( 'c', $state['recoveryDue'] ) )->text() );
		}

		if ( !empty( $state['mainPageRestored'] ) ) {
			$html .= Html::element( 'p', [], $this->msg( 'archivewikiimport-mainpage-restored' )->text() );
		}
		if ( isset( $state['error'] ) ) {
			$html .= Html::errorBox( htmlspecialchars( $state['error'], ENT_QUOTES ) );
		}
		if ( isset( $state['preflight'] ) ) {
			$scan = $state['preflight'];
			$html .= Html::element( 'h3', [], $this->msg( 'archivewikiimport-dependencies' )->text() );
			$html .= Html::element( 'p', [], $this->msg( 'archivewikiimport-scan-counts', $scan['pages'], $scan['revisions'] )->text() );
			$html .= Html::element( 'p', [], $this->msg( 'archivewikiimport-models', implode( ', ', $scan['models'] ) )->text() );
			if ( $scan['missingModels'] ) {
				$html .= Html::element( 'h4', [], $this->msg( 'archivewikiimport-missing-models' )->text() ) . Html::openElement( 'ul' );
				foreach ( $scan['missingModels'] as $model => $providers ) {
					$html .= Html::element( 'li', [], $model . ': ' . ( $providers
						? implode( ', ', $providers ) : $this->msg( 'archivewikiimport-unknown-provider' )->text() ) );
				}
				$html .= Html::closeElement( 'ul' );
				if ( MediaWikiServices::getInstance()->getSpecialPageFactory()->exists( 'ManageWiki' ) ) {
					$html .= Html::element( 'a', [ 'href' => SpecialPage::getTitleFor( 'ManageWiki', 'extensions' )->getLocalURL() ],
						$this->msg( 'archivewikiimport-manage-extensions' )->text() );
				}
			}
			if ( $scan['extensions'] ) {
				$html .= Html::openElement( 'ul' );
				foreach ( $scan['extensions'] as $name => $extension ) {
					$html .= Html::element( 'li', [], $name . ' — ' .
						$this->msg( $extension['enabled'] ? 'archivewikiimport-extension-enabled' : 'archivewikiimport-extension-missing' )->text() .
						' (' . implode( ', ', $extension['evidence'] ) . ')' );
				}
				$html .= Html::closeElement( 'ul' );
			}
			$html .= Html::element( 'p', [], $this->msg( 'archivewikiimport-dependencies-help' )->text() );
		}
		if ( $state['warnings'] ) {
			$html .= Html::element( 'h3', [], $this->msg( 'archivewikiimport-warnings' )->text() ) . Html::openElement( 'ul' );
			foreach ( $state['warnings'] as $warning ) {
				$html .= Html::element( 'li', [], $warning );
			}
			$html .= Html::closeElement( 'ul' );
		}
		$html .= Html::element( 'p', [], $this->msg( 'archivewikiimport-report-help' )->text() );
		$html .= Html::element( 'a', [ 'href' => $this->getPageTitle()->getLocalURL( [ 'run' => $id ] ) ],
			$this->msg( 'archivewikiimport-refresh' )->text() );
		if ( $state['status'] !== 'complete' && $state['user'] === $this->getUser()->getId() ) {
			$html .= $this->formStart( 'resume' ) . Html::hidden( 'run', $id ) .
				Html::submitButton( $this->msg( 'archivewikiimport-resume' )->text() ) . Html::closeElement( 'form' );
		}
		$this->getOutput()->addHTML( $html );
	}
}
