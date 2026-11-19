<?php

namespace MediaWiki\Extension\CFCachePurge;

use DeferredUpdates;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Invalidate the edge cache after content changes.
 *
 * Only active when $wgUseCdn is true and purge credentials are configured, so
 * wikis without a CDN never make API calls. Private wikis keep $wgUseCdn false.
 */
final class Hooks {

	public static function onPageSaveComplete( $wikiPage, ...$unused ): bool {
		self::queue( $wikiPage->getTitle() );
		return true;
	}

	public static function onArticleDeleteComplete( $wikiPage, ...$unused ): bool {
		self::queue( $wikiPage->getTitle() );
		return true;
	}

	public static function onPageMoveComplete( $old, $new, ...$unused ): bool {
		self::queue( Title::newFromLinkTarget( $old ) );
		self::queue( Title::newFromLinkTarget( $new ) );
		return true;
	}

	private static function queue( ?Title $title ): void {
		if ( !$title instanceof Title || !$title->canExist() ) {
			return;
		}
		$config = MediaWikiServices::getInstance()->getMainConfig();
		if ( !$config->get( 'UseCdn' ) || CachePurger::fromConfig( $config ) === null ) {
			return;
		}
		$urls = [ $title->getFullURL() ];
		DeferredUpdates::addCallableUpdate( static function () use ( $config, $urls ): void {
			$purger = CachePurger::fromConfig( $config );
			if ( $purger === null ) {
				return;
			}
			try {
				$purger->purgeUrls( $urls );
			} catch ( \Throwable $e ) {
				// Purge failures must never surface to editors.
				wfDebugLog( 'CFCachePurge', 'Cache purge failed: ' . $e->getMessage() );
			}
		} );
	}
}
