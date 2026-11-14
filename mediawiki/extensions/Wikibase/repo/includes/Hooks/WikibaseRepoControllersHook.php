<?php

declare( strict_types = 1 );

namespace Wikibase\Repo\Hooks;

/**
 * @license GPL-2.0-or-later
 */
interface WikibaseRepoControllersHook {

	/**
	 * @param array<string, array<string, callable>> &$controllerDefinitions
	 */
	public function onWikibaseRepoControllers( array &$controllerDefinitions ): void;

}
