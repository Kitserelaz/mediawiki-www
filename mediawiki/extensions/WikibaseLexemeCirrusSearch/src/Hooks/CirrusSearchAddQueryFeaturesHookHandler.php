<?php

namespace Wikibase\Lexeme\Search\Elastic\Hooks;

use CirrusSearch\Hooks\CirrusSearchAddQueryFeaturesHook;
use CirrusSearch\SearchConfig;
use Wikibase\Lexeme\Search\Elastic\HasLemmaKeyword;

class CirrusSearchAddQueryFeaturesHookHandler implements CirrusSearchAddQueryFeaturesHook {

	/**
	 * @inheritDoc
	 */
	public function onCirrusSearchAddQueryFeatures( SearchConfig $config, array &$extraFeatures ): void {
		$extraFeatures[] = new HasLemmaKeyword();
	}
}
