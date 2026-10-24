<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiCreationTasksHook {

	/**
	 * Runs after creation handlers have seeded the wiki's configuration.
	 * Return false only when the handler durably owns the standard container,
	 * main-page, requester-account and extra-data post-creation work.
	 */
	public function onCreateWikiCreationTasks( string $dbname, string $requester, array $extra ): bool;
}
