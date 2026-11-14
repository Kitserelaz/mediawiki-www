<?php

namespace Wikibase\Client\Tests\Integration\DataAccess\Scribunto;

/**
 * @license GPL-2.0-or-later
 * @covers \Wikibase\Client\DataAccess\Scribunto\WikibaseLibrary
 * @covers \Wikibase\Client\DataAccess\Scribunto\WikibaseEntityLibrary
 * @group Wikibase
 * @group Database
 * @group Lua
 * @group LuaStandalone
 */
class WikibaseStatsStandaloneTest extends WikibaseStatsTestBase {
	protected function getEngineName(): string {
		return 'LuaStandalone';
	}
}
