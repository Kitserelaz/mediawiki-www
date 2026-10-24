<?php
/** @license GPL-2.0-or-later */

namespace MediaWiki\Extension\DiscordAuth;

/** Validation shared by independent OAuth transactions and trusted continuation URLs. */
class OAuthFlow {
	public const LIFETIME = 600;

	/** Title::getFullURL uses Server, while SUL3 continuations must use CanonicalServer. */
	public static function continuationUrl(
		string $url, string $wikiServer, string $canonicalServer, string $callback
	): ?string {
		if ( self::sameOrigin( $url, $callback ) ) {
			return $url;
		}
		if ( !self::sameOrigin( $url, $wikiServer )
			|| !self::sameOrigin( $canonicalServer, $callback )
		) {
			return null;
		}
		$parts = parse_url( $url );
		return rtrim( $canonicalServer, '/' ) . ( $parts['path'] ?? '/' )
			. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	public static function valid( mixed $flow, string $state, string $callback, int $now ): bool {
		if ( !is_array( $flow ) || !is_string( $flow['state'] ?? null )
			|| $state === '' || !hash_equals( $flow['state'], $state )
			|| !is_int( $flow['expires'] ?? null ) || $flow['expires'] < $now
			|| $flow['expires'] > $now + self::LIFETIME
			|| !is_string( $flow['returnUrl'] ?? null )
		) {
			return false;
		}
		return self::sameOrigin( $flow['returnUrl'], $callback );
	}

	public static function sameOrigin( string $url, string $callback ): bool {
		$a = parse_url( $url );
		$b = parse_url( $callback );
		return is_array( $a ) && is_array( $b ) && ( $a['scheme'] ?? '' ) === 'https'
			&& ( $b['scheme'] ?? '' ) === 'https'
			&& !isset( $a['user'] ) && !isset( $a['pass'] )
			&& !isset( $a['fragment'] ) && !preg_match( '/[\r\n]/', $url )
			&& ( $a['host'] ?? '' ) === ( $b['host'] ?? '' )
			&& ( $a['port'] ?? 443 ) === ( $b['port'] ?? 443 );
	}
}
