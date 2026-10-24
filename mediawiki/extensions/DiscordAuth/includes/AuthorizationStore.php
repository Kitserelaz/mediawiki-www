<?php
/** @license GPL-2.0-or-later */

namespace MediaWiki\Extension\DiscordAuth;

use MediaWiki\Extension\CentralAuth\CentralAuthDatabaseManager;
use MediaWiki\Request\WebRequest;
use InvalidArgumentException;

/** Short-lived, single-use OAuth transactions, independent of MediaWiki sessions. */
class AuthorizationStore {
	public function __construct( private CentralAuthDatabaseManager $databases ) {
	}

	public static function cookieName( string $state ): string {
		return '__Host-discordAuth-' . $state;
	}

	private static function cookieOptions(): array {
		return [ 'prefix' => '', 'domain' => '', 'path' => '/', 'secure' => true,
			'httpOnly' => true, 'sameSite' => 'Lax' ];
	}

	public function start( WebRequest $request, string $returnUrl, string $callback,
		string $action, string $target = '', bool $freshLogin = false
	): string {
		if ( !OAuthFlow::sameOrigin( $returnUrl, $callback )
			|| !in_array( $action, [ 'login', 'create', 'link' ], true )
		) {
			throw new InvalidArgumentException( 'Invalid Discord authorization destination' );
		}
		return $this->issue( $request, [ 'returnUrl' => $returnUrl, 'action' => $action,
			'target' => $target, 'freshLogin' => $freshLogin, 'kind' => 'authorization' ] );
	}

	/** A second single-use transaction carries identity to the starting wiki after callback. */
	public function grant( WebRequest $request, array $flow, DiscordIdentityRequest $identity ): string {
		return $this->issue( $request, [ 'returnUrl' => $flow['returnUrl'],
			'action' => $flow['action'], 'target' => $flow['target'], 'kind' => 'identity',
			'identity' => [ 'id' => $identity->discordId, 'label' => $identity->label,
				'email' => $identity->email, 'emailVerified' => $identity->emailVerified,
				'verifiedAt' => $identity->verifiedAt ],
		] );
	}

	private function issue( WebRequest $request, array $payload ): string {
		$state = bin2hex( random_bytes( 32 ) );
		$binding = bin2hex( random_bytes( 32 ) );
		$expires = time() + OAuthFlow::LIFETIME;
		$db = $this->databases->getCentralPrimaryDB();
		// Remove abandoned transactions; access tokens and authorization codes are never stored.
		$db->newDeleteQueryBuilder()->deleteFrom( 'discordauth_transaction' )
			->where( $db->expr( 'dat_expires', '<', time() ) )->caller( __METHOD__ )->execute();
		$db->newInsertQueryBuilder()->insertInto( 'discordauth_transaction' )->row( [
			'dat_state' => hash( 'sha256', $state ),
			'dat_binding' => hash( 'sha256', $binding ),
			'dat_expires' => $expires,
			'dat_payload' => json_encode( $payload, JSON_THROW_ON_ERROR ),
		] )->caller( __METHOD__ )->execute();
		// A separate cookie per transaction permits simultaneous sign-ins in different tabs.
		$request->response()->setCookie( self::cookieName( $state ), $binding, $expires,
			self::cookieOptions() );
		return $state;
	}

	public function consume( WebRequest $request, string $state, string $callback, string $kind = 'authorization' ): ?array {
		if ( !preg_match( '/^[a-f0-9]{64}$/D', $state ) ) {
			return null;
		}
		$binding = $request->getCookie( self::cookieName( $state ), '' );
		if ( !is_string( $binding ) || !preg_match( '/^[a-f0-9]{64}$/D', $binding ) ) {
			return null;
		}
		$db = $this->databases->getCentralPrimaryDB();
		$where = [ 'dat_state' => hash( 'sha256', $state ), 'dat_binding' => hash( 'sha256', $binding ) ];
		$row = $db->newSelectQueryBuilder()->select( [ 'dat_expires', 'dat_payload' ] )
			->from( 'discordauth_transaction' )->where( $where )->caller( __METHOD__ )->fetchRow();
		if ( !$row ) {
			return null;
		}
		// Only the winner of this conditional delete may exchange the authorization code.
		$db->newDeleteQueryBuilder()->deleteFrom( 'discordauth_transaction' )->where( $where )
			->caller( __METHOD__ )->execute();
		if ( $db->affectedRows() !== 1 ) {
			return null;
		}
		$request->response()->clearCookie( self::cookieName( $state ), self::cookieOptions() );
		$flow = json_decode( $row->dat_payload, true, 512, JSON_THROW_ON_ERROR );
		$flow['state'] = $state;
		$flow['expires'] = (int)$row->dat_expires;
		return ( $flow['kind'] ?? null ) === $kind
			&& OAuthFlow::valid( $flow, $state, $callback, time() ) ? $flow : null;
	}
}
