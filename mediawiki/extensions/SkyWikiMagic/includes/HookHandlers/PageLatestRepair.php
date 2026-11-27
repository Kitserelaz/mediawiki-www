<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\ObjectCache\ObjectCacheFactory;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Hook\ArticleRevisionViewCustomHook;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\ReadOnlyMode;

/**
 * Self-heals page.page_latest on view when it points to a revision belonging to another page.
 */
final class PageLatestRepair implements ArticleRevisionViewCustomHook {
	private IConnectionProvider $dbProvider;
	private ReadOnlyMode $readOnlyMode;
	private ObjectCacheFactory $objectCacheFactory;

	public function __construct(
		IConnectionProvider $dbProvider,
		ReadOnlyMode $readOnlyMode,
		ObjectCacheFactory $objectCacheFactory
	) {
		$this->dbProvider = $dbProvider;
		$this->readOnlyMode = $readOnlyMode;
		$this->objectCacheFactory = $objectCacheFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function onArticleRevisionViewCustom( $revision, $title, $oldid, $output ) {
		if ( $oldid !== 0 || $revision === null ) {
			return true;
		}

		$pageId = $title->getArticleID();
		if ( $pageId <= 0 || $revision->getPageId() === $pageId ) {
			return true;
		}

		if ( $this->readOnlyMode->isReadOnly() ) {
			return true;
		}

		$cache = $this->objectCacheFactory->getLocalClusterInstance();
		$lockKey = $cache->makeKey( 'skywiki', 'page-latest-repair', $pageId );
		if ( !$cache->add( $lockKey, 1, 30 ) ) {
			return true;
		}

		if ( !$this->repairPageLatest( $title, $pageId, $revision ) ) {
			return true;
		}

		$output->enableClientCache( false );
		$output->redirect( $title->getFullURL() );
		return false;
	}

	private function repairPageLatest( Title $title, int $pageId, RevisionRecord $brokenRevision ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$pageRow = $dbw->newSelectQueryBuilder()
			->select( [ 'page_latest', 'page_len' ] )
			->from( 'page' )
			->where( [ 'page_id' => $pageId ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$pageRow || (int)$pageRow->page_latest !== $brokenRevision->getId() ) {
			return false;
		}

		$candidate = $dbw->newSelectQueryBuilder()
			->select( [ 'rev_id', 'rev_len' ] )
			->from( 'revision' )
			->where( [
				'rev_page' => $pageId,
				'rev_deleted' => 0,
			] )
			->orderBy( 'rev_id', 'DESC' )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$candidate || (int)$candidate->rev_len !== (int)$pageRow->page_len ) {
			LoggerFactory::getInstance( 'SkyWikiMagic' )->warning(
				'Skipped on-visit page_latest repair for {title} (page_id={pageId}): '
				. 'broken rev {brokenRev} (rev_page={revPage}), candidate={candidateRev}',
				[
					'title' => $title->getPrefixedDBkey(),
					'pageId' => $pageId,
					'brokenRev' => (int)$pageRow->page_latest,
					'revPage' => $brokenRevision->getPageId(),
					'candidateRev' => $candidate ? (int)$candidate->rev_id : 0,
				]
			);
			return false;
		}

		$dbw->newUpdateQueryBuilder()
			->update( 'page' )
			->set( [
				'page_latest' => (int)$candidate->rev_id,
				'page_touched' => $dbw->timestamp(),
			] )
			->where( [
				'page_id' => $pageId,
				'page_latest' => (int)$pageRow->page_latest,
			] )
			->caller( __METHOD__ )
			->execute();

		if ( $dbw->affectedRows() === 0 ) {
			return false;
		}

		$title->invalidateCache();
		LoggerFactory::getInstance( 'SkyWikiMagic' )->warning(
			'Auto-repaired page_latest for {title} (page_id={pageId}) from {oldRev} to {newRev}',
			[
				'title' => $title->getPrefixedDBkey(),
				'pageId' => $pageId,
				'oldRev' => (int)$pageRow->page_latest,
				'newRev' => (int)$candidate->rev_id,
			]
		);

		return true;
	}
}
