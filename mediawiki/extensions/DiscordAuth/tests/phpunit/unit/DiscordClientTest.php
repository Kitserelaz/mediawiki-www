<?php
/** @license GPL-2.0-or-later */

namespace MediaWiki\Extension\DiscordAuth\Tests;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\DiscordAuth\DiscordClient;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Http\MWHttpRequest;
use MediaWikiUnitTestCase;
use RuntimeException;
use StatusValue;

/** @covers \MediaWiki\Extension\DiscordAuth\DiscordClient */
class DiscordClientTest extends MediaWikiUnitTestCase {
	private function config(): HashConfig {
		return new HashConfig( [ 'DiscordAuthClientId' => 'test-client',
			'DiscordAuthClientSecret' => 'test-secret',
			'DiscordAuthCallbackUrl' => 'https://auth.example.org/wiki/Special:DiscordCallback' ] );
	}

	public function testOnlyIdentityScopeIsRequestedAndSecretStaysServerSide(): void {
		$client = new DiscordClient( $this->config(), $this->createMock( HttpRequestFactory::class ) );
		$this->assertTrue( $client->isEnabled() );
		parse_str( parse_url( $client->authorizationUrl( 'state' ), PHP_URL_QUERY ), $query );
		$this->assertSame( 'identify email', $query['scope'] );
		$this->assertSame( 'state', $query['state'] );
		$this->assertArrayNotHasKey( 'client_secret', $query );
		$this->assertSame( 'code', $query['response_type'] );
	}

	public function testMissingCredentialsDisableSignIn(): void {
		$config = $this->config();
		$config->set( 'DiscordAuthClientSecret', '' );
		$this->assertFalse( ( new DiscordClient( $config,
			$this->createMock( HttpRequestFactory::class ) ) )->isEnabled() );
	}

	/** @dataProvider discordResponses */
	public function testTokenExchangeAndIdentityValidation(
		array $token, array $user, bool $valid, string $email = '', bool $verified = false
	): void {
		$http = $this->createMock( HttpRequestFactory::class );
		$tokenRequest = $this->response( $token );
		$userRequest = $this->response( $user );
		$http->method( 'create' )->willReturnCallback( function ( $url, $options ) use (
			$tokenRequest, $userRequest
		) {
			$this->assertFalse( $options['followRedirects'] );
			if ( $url === 'https://discord.com/api/oauth2/token' ) {
				parse_str( $options['postData'], $data );
				$this->assertSame( 'test-secret', $data['client_secret'] );
				$this->assertSame( 'test-code', $data['code'] );
				return $tokenRequest;
			}
			$this->assertSame( 'https://discord.com/api/v10/users/@me', $url );
			return $userRequest;
		} );
		if ( !$valid ) {
			$this->expectException( RuntimeException::class );
		}
		$identity = ( new DiscordClient( $this->config(), $http ) )->identify( 'test-code' );
		$this->assertSame( [ 'id' => '123456789012345678', 'label' => 'example',
			'email' => $email, 'emailVerified' => $verified ], $identity );
	}

	public static function discordResponses(): array {
		$token = [ 'access_token' => 'test-access', 'token_type' => 'Bearer' ];
		$user = [ 'id' => '123456789012345678', 'username' => 'example' ];
		return [
			[ $token, $user, true ],
			[ $token, $user + [ 'email' => 'test@example.org', 'verified' => true ], true, 'test@example.org', true ],
			[ $token, $user + [ 'email' => 'test@example.org', 'verified' => false ], true, 'test@example.org', false ],
			[ $token, $user + [ 'email' => 'invalid', 'verified' => true ], true ],
			[ [], $user, false ],
			[ [ 'access_token' => "bad\r\ntoken", 'token_type' => 'Bearer' ], $user, false ],
			[ $token, [ 'id' => 'not-a-snowflake', 'username' => 'example' ], false ],
			[ $token, $user + [ 'bot' => true ], false ],
		];
	}

	private function response( array $body ): MWHttpRequest {
		$request = $this->getMockBuilder( MWHttpRequest::class )->disableOriginalConstructor()->getMock();
		$request->method( 'execute' )->willReturn( StatusValue::newGood() );
		$request->method( 'getStatus' )->willReturn( 200 );
		$request->method( 'getContent' )->willReturn( json_encode( $body ) );
		return $request;
	}
}
