<?php
/** @license GPL-2.0-or-later */

namespace MediaWiki\Extension\DiscordAuth\Tests;

use MediaWiki\Extension\DiscordAuth\OAuthFlow;
use MediaWikiUnitTestCase;

/** @covers \MediaWiki\Extension\DiscordAuth\OAuthFlow */
class OAuthFlowTest extends MediaWikiUnitTestCase {
	public function testSharedDomainContinuationUsesCanonicalServer(): void {
		$callback = 'https://auth.example.org/metawiki/wiki/Special:DiscordCallback';
		$this->assertSame(
			'https://auth.example.org/metawiki/wiki/Special:DiscordLogin/return?authToken=test',
			OAuthFlow::continuationUrl(
				'https://meta.example.org/metawiki/wiki/Special:DiscordLogin/return?authToken=test',
				'https://meta.example.org', 'https://auth.example.org', $callback
			)
		);
		$this->assertNull( OAuthFlow::continuationUrl( 'https://attacker.example.org/',
			'https://meta.example.org', 'https://auth.example.org', $callback ) );
		$this->assertNull( OAuthFlow::continuationUrl( 'https://meta.example.org/',
			'https://meta.example.org', 'https://meta.example.org', $callback ) );
	}
	public function testCallbackMustMatchBrowserStateAndExpiry(): void {
		$callback = 'https://auth.example.org/wiki/Special:DiscordCallback';
		$flow = [ 'state' => 'browser-secret', 'expires' => 1600,
			'returnUrl' => 'https://auth.example.org/wiki/Special:UserLogin/return?authToken=test' ];
		$this->assertTrue( OAuthFlow::valid( $flow, 'browser-secret', $callback, 1000 ) );
		$this->assertFalse( OAuthFlow::valid( $flow, 'wrong-browser', $callback, 1000 ) );
		$this->assertFalse( OAuthFlow::valid( $flow, '', $callback, 1000 ) );
		$this->assertFalse( OAuthFlow::valid( $flow, 'browser-secret', $callback, 1601 ) );
		$this->assertFalse( OAuthFlow::valid( $flow, 'browser-secret', $callback, 999 ) );
		$this->assertFalse( OAuthFlow::valid( null, 'browser-secret', $callback, 1000 ) );
	}

	/** @dataProvider unsafeReturnUrls */
	public function testCannotRedirectCredentialsOffOrigin( string $url ): void {
		$this->assertFalse( OAuthFlow::sameOrigin( $url,
			'https://auth.example.org/wiki/Special:DiscordCallback' ) );
	}

	public static function unsafeReturnUrls(): array {
		return array_map( static fn ( $url ) => [ $url ], [
			'https://evil.example.org/', 'http://auth.example.org/',
			'https://auth.example.org.evil.org/', '//auth.example.org/',
			'https://user:password@auth.example.org/', 'https://auth.example.org:444/',
			'https://auth.example.org/#fragment', "https://auth.example.org/\r\nLocation: evil",
		] );
	}
}
