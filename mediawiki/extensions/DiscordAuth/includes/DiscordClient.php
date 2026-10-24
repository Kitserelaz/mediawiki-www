<?php
/** @license GPL-2.0-or-later */

namespace MediaWiki\Extension\DiscordAuth;

use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use Psr\Log\NullLogger;
use RuntimeException;

/** Confidential OAuth client. Tokens are used once for identity and never persisted. */
class DiscordClient {
	public function __construct( private Config $config, private HttpRequestFactory $http ) {
	}

	public function isEnabled(): bool {
		return $this->config->get( 'DiscordAuthClientId' ) !== ''
			&& $this->config->get( 'DiscordAuthClientSecret' ) !== ''
			&& str_starts_with( $this->callbackUrl(), 'https://' );
	}

	public function callbackUrl(): string {
		return $this->config->get( 'DiscordAuthCallbackUrl' );
	}

	public function authorizationUrl( string $state ): string {
		return 'https://discord.com/oauth2/authorize?' . http_build_query( [
			'client_id' => $this->config->get( 'DiscordAuthClientId' ),
			'redirect_uri' => $this->callbackUrl(),
			'response_type' => 'code',
			'scope' => 'identify email',
			'state' => $state,
			'prompt' => 'consent',
		], '', '&', PHP_QUERY_RFC3986 );
	}

	/** @return array{id:string,label:string,email:string,emailVerified:bool} */
	public function identify( string $code ): array {
		if ( $code === '' || strlen( $code ) > 2048 ) {
			throw new RuntimeException( 'Invalid Discord authorization code' );
		}
		$token = $this->request( 'https://discord.com/api/oauth2/token', [
			'method' => 'POST',
			'postData' => http_build_query( [
				'client_id' => $this->config->get( 'DiscordAuthClientId' ),
				'client_secret' => $this->config->get( 'DiscordAuthClientSecret' ),
				'grant_type' => 'authorization_code',
				'code' => $code,
				'redirect_uri' => $this->callbackUrl(),
			], '', '&', PHP_QUERY_RFC3986 ),
		], [ 'Content-Type' => 'application/x-www-form-urlencoded' ] );
		if ( !is_string( $token['access_token'] ?? null ) || $token['access_token'] === ''
			|| strcasecmp( $token['token_type'] ?? '', 'Bearer' ) !== 0
			|| preg_match( '/[\r\n]/', $token['access_token'] )
		) {
			throw new RuntimeException( 'Invalid Discord token response' );
		}
		$identity = $this->request( 'https://discord.com/api/v10/users/@me', [], [
			'Authorization' => 'Bearer ' . $token['access_token'],
		] );
		if ( !is_string( $identity['id'] ?? null )
			|| !preg_match( '/^[1-9][0-9]{5,19}$/D', $identity['id'] )
			|| !is_string( $identity['username'] ?? null )
			|| !empty( $identity['bot'] )
		) {
			throw new RuntimeException( 'Invalid Discord identity response' );
		}
		$email = $identity['email'] ?? '';
		if ( !is_string( $email ) || strlen( $email ) > 255 || !filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$email = '';
		}
		return [ 'id' => $identity['id'], 'label' => $identity['username'],
			'email' => $email, 'emailVerified' => $email !== '' && ( $identity['verified'] ?? false ) === true ];
	}

	private function request( string $url, array $options, array $headers ): array {
		$request = $this->http->create( $url, $options + [
			'timeout' => 15, 'connectTimeout' => 5, 'followRedirects' => false,
			// Suppress HTTP debugging of credentials and authorization responses.
			'logger' => new NullLogger(),
		], __METHOD__ );
		foreach ( $headers as $name => $value ) {
			$request->setHeader( $name, $value );
		}
		$status = $request->execute();
		if ( !$status->isOK() || $request->getStatus() !== 200 ) {
			throw new RuntimeException( 'Discord request failed' );
		}
		$data = json_decode( $request->getContent(), true );
		if ( !is_array( $data ) ) {
			throw new RuntimeException( 'Invalid Discord response' );
		}
		return $data;
	}
}
