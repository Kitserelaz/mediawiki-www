<?php
/** @license GPL-2.0-or-later */
namespace MediaWiki\Extension\ArchiveWikiImport;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use Throwable;

/** Read-only queue of this wiki's archive imports, including completed history. */
class SpecialArchiveWikiImportQueue extends SpecialPage {
	private const STATUSES = [ 'active', 'all', 'queued', 'running', 'needs-attention', 'failed', 'complete' ];
	private const LIMIT = 50;

	public function __construct() {
		parent::__construct( 'ArchiveWikiImportQueue' );
	}
	public function getRestriction(): string {
		return 'archivewikiimport';
	}
	protected function getGroupName() {
		return 'pagetools';
	}
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->checkPermissions();
		$this->getOutput()->disallowUserJs();
		$this->getOutput()->addModuleStyles( [ 'ext.archiveWikiImport', 'mediawiki.codex.messagebox.styles' ] );
		if ( $subPage ) {
			// Share the canonical progress report and its existing, permission-checked actions.
			$this->getOutput()->redirect( SpecialPage::getTitleFor( 'ArchiveWikiImport' )->getLocalURL( [ 'run' => $subPage ] ) );
			return;
		}
		$request = $this->getRequest();
		$status = $request->getText( 'status', 'active' );
		if ( !in_array( $status, self::STATUSES, true ) ) {
			$status = 'active';
		}
		$requester = trim( $request->getText( 'requester' ) );
		$item = trim( $request->getText( 'item' ) );
		$offset = max( 0, $request->getInt( 'offset', 0 ) );
		$out = $this->getOutput();
		$out->addHTML( Html::element( 'p', [], $this->msg( 'archivewikiimport-queue-intro' )->text() ) .
			Html::element( 'a', [ 'href' => SpecialPage::getTitleFor( 'ArchiveWikiImport' )->getLocalURL() ],
				$this->msg( 'archivewikiimport-new-import' )->text() ) );
		$html = Html::openElement( 'form', [ 'method' => 'get', 'action' => $this->getConfig()->get( 'Script' ), 'class' => 'awi-form' ] ) .
			Html::hidden( 'title', $this->getPageTitle()->getPrefixedText() ) .
			Html::label( $this->msg( 'archivewikiimport-report-status' )->text(), 'awi-status' ) .
			Html::openElement( 'select', [ 'id' => 'awi-status', 'name' => 'status' ] );
		foreach ( self::STATUSES as $value ) {
			$html .= Html::element( 'option', [ 'value' => $value, 'selected' => $status === $value ],
				$this->msg( 'archivewikiimport-status-' . $value )->text() );
		}
		$html .= Html::closeElement( 'select' );
		foreach ( [ 'requester' => $requester, 'item' => $item ] as $key => $value ) {
			$html .= Html::label( $this->msg( 'archivewikiimport-queue-' . $key )->text(), 'awi-' . $key ) .
				Html::input( $key, $value, 'text', [ 'id' => 'awi-' . $key, 'class' => 'awi-source' ] );
		}
		$html .= Html::submitButton( $this->msg( 'archivewikiimport-queue-filter' )->text() ) . Html::closeElement( 'form' );
		$out->addHTML( $html );
		try {
			$services = MediaWikiServices::getInstance();
			$userFactory = $services->getUserFactory();
			$userId = null;
			if ( $requester !== '' ) {
				$user = $userFactory->newFromName( $requester );
				$userId = $user && $user->getId() ? $user->getId() : -1;
			}
			$store = new RunStore();
			$result = $store->listRuns( $status, $userId, $item, $offset, self::LIMIT );
			$out->addHTML( Html::element( 'p', [],
				$this->msg( 'archivewikiimport-active-limit', $store->activeCount(), RunStore::MAX_ACTIVE )->text() ) );
			$daily = $store->dailyUsage();
			$this->getOutput()->addHTML( Html::element( 'p', [], $this->msg( 'archivewikiimport-daily-limit',
				$daily['count'], $daily['limit'], $daily['resets'], $daily['timezone'] )->text() ) );
			$filters = [ 'status' => $status, 'requester' => $requester, 'item' => $item ];
			$html = Html::element( 'p', [], $this->msg( 'archivewikiimport-queue-count', $result['total'] )->text() );
			if ( !$result['runs'] ) {
				$html .= Html::element( 'p', [], $this->msg( 'archivewikiimport-queue-empty' )->text() );
			} else {
				$html .= Html::openElement( 'div', [ 'class' => 'awi-queue-scroll' ] ) .
					Html::openElement( 'table', [ 'class' => 'wikitable awi-queue' ] ) . Html::openElement( 'thead' ) . Html::openElement( 'tr' );
				foreach ( [ 'updated', 'item', 'requester', 'status', 'phase', 'progress', 'details' ] as $field ) {
					$html .= Html::element( 'th', [ 'scope' => 'col' ], $this->msg( 'archivewikiimport-queue-' . $field )->text() );
				}
				$html .= Html::closeElement( 'tr' ) . Html::closeElement( 'thead' ) . Html::openElement( 'tbody' );
				$users = [];
				foreach ( $result['runs'] as $run ) {
					$id = (int)( $run['user'] ?? 0 );
					$users[$id] ??= $id ? $userFactory->newFromId( $id )->getName() : $this->msg( 'archivewikiimport-queue-unknown-user' )->text();
					$html .= Html::openElement( 'tr' ) . Html::element( 'td', [], $run['updated'] ) .
						Html::rawElement( 'td', [], Html::element( 'a', [
							'href' => 'https://archive.org/details/' . rawurlencode( $run['item'] )
						], $run['item'] ) ) . Html::element( 'td', [], $users[$id] ) .
						Html::element( 'td', [], $this->msg( 'archivewikiimport-status-' . $run['status'] )->text() ) .
						Html::element( 'td', [], (string)( $run['phase'] ?? '' ) ) .
						Html::element( 'td', [], $this->msg( 'archivewikiimport-queue-progress-counts',
							(int)( $run['pages'] ?? 0 ), (int)( $run['revisions'] ?? 0 ), (int)( $run['images'] ?? 0 ) )->text() ) .
						Html::rawElement( 'td', [], Html::element( 'a', [
							'href' => SpecialPage::getTitleFor( 'ArchiveWikiImport' )->getLocalURL( [ 'run' => $run['id'] ] )
						], $this->msg( 'archivewikiimport-queue-view' )->text() ) ) . Html::closeElement( 'tr' );
				}
				$html .= Html::closeElement( 'tbody' ) . Html::closeElement( 'table' ) . Html::closeElement( 'div' );
			}
			$html .= Html::openElement( 'nav', [ 'class' => 'awi-queue-nav', 'aria-label' => $this->msg( 'archivewikiimport-queue-pages' )->text() ] );
			if ( $offset > 0 ) {
				$html .= Html::element( 'a', [ 'href' => $this->getPageTitle()->getLocalURL( $filters + [ 'offset' => max( 0, $offset - self::LIMIT ) ] ) ],
					$this->msg( 'archivewikiimport-queue-previous' )->text() );
			}
			if ( $offset + self::LIMIT < $result['total'] ) {
				$html .= Html::element( 'a', [ 'href' => $this->getPageTitle()->getLocalURL( $filters + [ 'offset' => $offset + self::LIMIT ] ) ],
					$this->msg( 'archivewikiimport-queue-next' )->text() );
			}
			$html .= Html::element( 'a', [ 'href' => $this->getPageTitle()->getLocalURL( $filters + [ 'offset' => $offset ] ) ],
				$this->msg( 'archivewikiimport-refresh' )->text() ) . Html::closeElement( 'nav' );
			$out->addHTML( $html );
		} catch ( Throwable $e ) {
			$out->addHTML( Html::errorBox( htmlspecialchars( $e->getMessage(), ENT_QUOTES ) ) );
		}
	}
}
