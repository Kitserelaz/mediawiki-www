<?php

declare( strict_types = 1 );
namespace Wikibase\Client\Tests\Integration\Hooks;

use MediaWiki\Exception\LoginErrorHelper;
use MediaWikiIntegrationTestCase;

/**
 * @covers \Wikibase\Client\Hooks\LoginFormValidErrorMessagesHandler
 *
 * @group WikibaseClient
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class LoginFormValidErrorMessagesHandlerTest extends MediaWikiIntegrationTestCase {

	public function testIsRegistered() {
		$messages = LoginErrorHelper::getValidErrorMessages();

		$this->assertContains( 'wikibase-client-data-bridge-login-warning', $messages );
	}

}
