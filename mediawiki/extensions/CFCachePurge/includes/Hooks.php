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
 *
 * Edits in the MediaWiki namespace change interface messages and site CSS/JS,
 * which appear on every page of that wiki. Those purge the wiki's whole host
 * instead of a single URL, scoped by hostname so other wikis in the same
 * Cloudflare zone keep their cached pages.
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

		if ( $title->getNamespace() === NS_MEDIAWIKI ) {
			$host = self::hostOf( $title );
			if ( $host === null ) {
				return;
			}
			DeferredUpdates::addCallableUpdate( static function () use ( $config, $host ): void {
				$purger = CachePurger::fromConfig( $config );
				if ( $purger === null ) {
					return;
				}
				try {
					$purger->purgeHost( $host );
				} catch ( \Throwable $e ) {
					// Purge failures must never surface to editors.
					wfDebugLog( 'CFCachePurge', 'Host cache purge failed: ' . $e->getMessage() );
				}
			} );
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

	/**
	 * The canonical hostname this wiki is served on.
	 *
	 * Deriving it from the title keeps the purge scoped to one wiki in the
	 * shared Cloudflare zone; a zone-wide purge would drop every other wiki.
	 */
	private static function hostOf( Title $title ): ?string {
		$host = parse_url( $title->getFullURL(), PHP_URL_HOST );
		if ( !is_string( $host ) || !preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $host ) ) {
			return null;
		}
		return strtolower( $host );
	}
}
