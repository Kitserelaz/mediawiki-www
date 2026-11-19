<?php

namespace MediaWiki\Extension\CFCachePurge;

use MediaWiki\Config\Config;
use RuntimeException;

/**
 * Minimal Cloudflare zone client used by cache invalidation hooks.
 *
 * Credentials are the same values Special:CFCachePurge uses, so the zone-scoped
 * SaaS token can serve both custom hostnames and cache purging.
 */
final class CachePurger {

	/** Cloudflare accepts at most 30 individual URLs per purge request. */
	private const MAX_URLS_PER_REQUEST = 30;

	public function __construct(
		private readonly string $zone,
		private readonly string $token,
	) {
	}

	public static function fromConfig( Config $config ): ?self {
		$zone = (string)$config->get( 'CFCachePurgeZoneId' );
		$token = (string)$config->get( 'CFCachePurgeApiToken' );
		if ( !preg_match( '/^[a-f0-9]{32}$/D', $zone ) || $token === '' || preg_match( '/\s/', $token ) ) {
			return null;
		}
		return new self( $zone, $token );
	}

	/**
	 * @param string[] $urls Absolute https URLs on this Cloudflare zone
	 */
	public function purgeUrls( array $urls ): void {
		$urls = array_values( array_unique( array_filter(
			$urls,
			static fn ( $url ) => is_string( $url ) && str_starts_with( $url, 'https://' )
		) ) );
		foreach ( array_chunk( $urls, self::MAX_URLS_PER_REQUEST ) as $chunk ) {
			$this->request( [ 'files' => $chunk ] );
		}
	}

	public function purgeHost( string $host ): void {
		if ( $host !== '' ) {
			$this->request( [ 'hosts' => [ $host ] ] );
		}
	}

	private function request( array $payload ): void {
		$curl = curl_init( "https://api.cloudflare.com/client/v4/zones/{$this->zone}/purge_cache" );
		curl_setopt_array( $curl, [
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_HTTPHEADER => [ 'Authorization: Bearer ' . $this->token, 'Content-Type: application/json' ],
			CURLOPT_POSTFIELDS => json_encode( $payload, JSON_THROW_ON_ERROR ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 12,
		] );
		$body = curl_exec( $curl );
		$status = (int)curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		curl_close( $curl );
		$data = is_string( $body ) ? json_decode( $body, true ) : null;
		if ( $status < 200 || $status >= 300 || ( $data['success'] ?? false ) !== true ) {
			$errors = is_array( $data['errors'] ?? null ) ? $data['errors'] : [];
			$codes = array_filter( array_column( $errors, 'code' ), 'is_int' );
			throw new RuntimeException( 'Cloudflare cache purge returned HTTP ' . $status .
				( $codes ? ' (' . implode( ', ', $codes ) . ')' : '' ), $status );
		}
	}
}
