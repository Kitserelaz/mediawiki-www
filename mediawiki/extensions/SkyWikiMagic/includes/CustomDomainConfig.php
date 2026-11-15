<?php

namespace WikiOasis\WikiOasisMagic;

/** Shared by the wiki runtime and the private Caddy lookup, which does not bootstrap MediaWiki. */
final class CustomDomainConfig {
	public static function hostname( string $value ): ?string {
		$value = rtrim( trim( $value ), '.' );
		if ( $value === '' || strlen( $value ) > 1024 ) {
			return null;
		}
		$ascii = idn_to_ascii( $value, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );
		if ( $ascii === false || strlen( $ascii ) > 253 ) {
			return null;
		}
		$host = strtolower( $ascii );
		if ( !preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host )
			|| preg_match( '/\.[0-9]+$/D', $host )
		) {
			return null;
		}
		return $host;
	}

	public static function target(): string {
		return self::hostname( getenv( 'MW_CUSTOM_DOMAINS_TARGET' ) ?:
			( 'custom-domains.' . ( getenv( 'MW_DOMAIN' ) ?: 'cloudwiki.us' ) ) ) ?? '';
	}

	public static function enabled(): bool {
		return self::routingEnabled() && getenv( 'MW_CUSTOM_DOMAINS_ENABLED' ) === '1';
	}

	public static function routingEnabled(): bool {
		return getenv( 'MW_DOCKER' ) && getenv( 'MW_CUSTOM_DOMAINS_ROUTING' ) === '1';
	}

	public static function isReserved( string $host ): bool {
		$zones = [
			getenv( 'MW_DOMAIN' ) ?: 'cloudwiki.us',
			getenv( 'MW_BETA_DOMAIN' ) ?: 'beta.localhost',
			'localhost', 'local', 'internal', 'home.arpa',
		];
		foreach ( $zones as $zone ) {
			$zone = strtolower( rtrim( $zone, '.' ) );
			if ( $host === $zone || str_ends_with( $host, ".$zone" ) ) {
				return true;
			}
		}
		foreach ( explode( ',', getenv( 'MW_PUBLIC_HOSTS' ) ?: '' ) as $site ) {
			$site = trim( $site );
			$name = parse_url( str_contains( $site, '://' ) ? $site : 'https://' . $site, PHP_URL_HOST );
			if ( $name && ( self::hostname( $name ) === $host ||
				( str_starts_with( $name, '*.' ) && str_ends_with( $host, strtolower( substr( $name, 1 ) ) ) )
			) ) {
				return true;
			}
		}
		return $host === self::target();
	}

	public static function wikiId( string $slug ): ?string {
		$suffix = getenv( 'MW_REALM' ) === 'beta' ? 'wikibeta' : 'wiki';
		if ( !preg_match( '/^[a-z0-9][a-z0-9-]*$/D', $slug ) || strlen( $slug . $suffix ) > 64
			|| in_array( $slug, [ 'meta', 'login', 'auth', 'counter', 'code', 'diff', 'custom-domains' ], true )
		) {
			return null;
		}
		return $slug . $suffix;
	}

	public static function slug( string $wiki ): string {
		return preg_replace( '/wikibeta$|wiki$/D', '', $wiki );
	}
}
