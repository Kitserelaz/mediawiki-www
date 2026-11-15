<?php

declare( strict_types = 1 );

namespace Wikibase\Lexeme\Search\Elastic\Hooks;

use MediaWiki\Search\Hook\ShowSearchHitHook;
use MediaWiki\Search\SearchResult;
use MediaWiki\Specials\SpecialSearch;
use Wikibase\Lexeme\Search\Elastic\LexemeResult;

/**
 * MediaWiki Core hook handlers for the WikibaseLexemeCirrusSearch extension.
 *
 * @license GPL-2.0-or-later
 */
class ShowSearchHitHookHandler implements ShowSearchHitHook {

	/**
	 * @param SpecialSearch $searchPage
	 * @param SearchResult $result
	 * @param string[] $terms
	 * @param string &$link
	 * @param string &$redirect
	 * @param string &$section
	 * @param string &$extract
	 * @param string &$score
	 * @param string &$size
	 * @param string &$date
	 * @param string &$related
	 * @param string &$html
	 */
	public function onShowSearchHit( $searchPage, $result,
		$terms, &$link, &$redirect, &$section, &$extract, &$score, &$size, &$date, &$related,
		&$html
	) {
		if ( empty( $GLOBALS['wgLexemeUseCirrus'] ) ) {
			return;
		}
		if ( !( $result instanceof LexemeResult ) ) {
			return;
		}

		// set $size to size metrics
		$size = $searchPage->msg(
			'wikibaselexeme-search-result-stats',
			$result->getStatementCount(),
			$result->getFormCount()
		)->escaped();
	}

}
