<?php
/** @license GPL-2.0-or-later */
namespace MediaWiki\Extension\ArchiveWikiImport;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use RuntimeException;
use Wikimedia\Rdbms\IDBAccessObject;

/** Preserve imported history and replace only the installer's current placeholder. */
class MainPageRestorer {
	public static function defaultRevisionId( Title $title, MediaWikiServices $services ): int {
		if ( !$title->equals( Title::newMainPage() ) ) {
			return 0;
		}
		$page = $services->getWikiPageFactory()->newFromTitle( $title );
		$page->loadPageData( IDBAccessObject::READ_LATEST );
		$revision = $page->getRevisionRecord();
		return $revision && $revision->getUser()->getName() === 'MediaWiki default' ? $revision->getId() : 0;
	}

	/** @param \MediaWiki\Import\WikiRevision|\MediaWiki\Revision\RevisionRecord $source */
	public static function restore( Title $title, int $defaultRevisionId, $source, User $user,
		string $item, MediaWikiServices $services ): bool {
		if ( !$defaultRevisionId || !$title->equals( Title::newMainPage() ) ) {
			return false;
		}
		if ( !$user->authorizeWrite( 'edit', $title ) ) {
			throw new RuntimeException( 'Cannot restore the archived main page: edit permission denied.' );
		}
		$page = $services->getWikiPageFactory()->newFromTitle( $title );
		$updater = $page->newPageUpdater( $user );
		$current = $updater->grabParentRevision();
		// Re-check under the update lock: an edit or a newer imported revision wins.
		if ( !$current || $current->getId() !== $defaultRevisionId ||
			$current->getUser()->getName() !== 'MediaWiki default' ) {
			return false;
		}
		foreach ( $source->getSlotRoles() as $role ) {
			$content = $source->getContent( $role );
			if ( !$content ) {
				throw new RuntimeException( 'Cannot restore a main-page revision with hidden content.' );
			}
			$updater->setContent( $role, $content );
		}
		$updater->saveRevision( 'Restore archived main page from https://archive.org/details/' . $item .
			' (source timestamp ' . $source->getTimestamp() . ')', EDIT_UPDATE );
		if ( !$updater->getStatus()->isOK() ) {
			throw new RuntimeException( 'Could not make the archived main page current.' );
		}
		return true;
	}
}
