<?php

declare( strict_types = 1 );

namespace Wikibase\Lexeme\Search\Elastic\Hooks;

use Wikibase\Repo\Hooks\WikibaseRepoEntityTypesHook;

/**
 * Wikibase hook handlers for the WikibaseLexemeCirrusSearch extension.
 *
 * @license GPL-2.0-or-later
 */
class WikibaseRepoEntityTypesHookHandler implements WikibaseRepoEntityTypesHook {

	/**
	 * Adds the definition of the lexeme entity type to the definitions array Wikibase uses.
	 *
	 * @see WikibaseLexeme.entitytypes.php
	 * @see WikibaseLexeme.entitytypes.repo.php
	 *
	 * @param array[] &$entityTypeDefinitions
	 */
	public function onWikibaseRepoEntityTypes( array &$entityTypeDefinitions ): void {
		if ( empty( $GLOBALS['wgLexemeUseCirrus'] ) ) {
			return;
		}
		$searchDefinitions = require __DIR__ . '/../../WikibaseSearch.entitytypes.repo.php';
		foreach ( $searchDefinitions as $type => $definition ) {
			$entityTypeDefinitions[$type] = $definition + ( $entityTypeDefinitions[$type] ?? [] );
		}
	}

}
