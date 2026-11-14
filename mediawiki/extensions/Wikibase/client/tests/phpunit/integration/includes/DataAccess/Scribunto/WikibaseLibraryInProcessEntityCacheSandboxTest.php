<?php

namespace Wikibase\Client\Tests\Integration\DataAccess\Scribunto;

/**
 * @license GPL-2.0-or-later
 * @covers \Wikibase\Client\DataAccess\Scribunto\WikibaseLibrary
 * @group Wikibase
 * @group Database
 * @group Lua
 * @group LuaSandbox
 */
class WikibaseLibraryInProcessEntityCacheSandboxTest extends WikibaseLibraryInProcessEntityCacheTestBase {
	protected function getEngineName(): string {
		return 'LuaSandbox';
	}
}
