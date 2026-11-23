<?php

namespace WikiOasis\WikiOasisMagic;

use RuntimeException;

/** Cloudflare access for the background worker. The API token is mounted only there. */
final class CloudflareCustomHostnames {
	public function __construct(
		private readonly string $zone,
		private readonly string $token,
	) {
	}

	public static function fromEnvironment(): ?self {
		$zone = getenv( 'MW_CLOUDFLARE_SAAS_ZONE_ID' ) ?: '';
		$file = getenv('MW_CLOUDFLARE_SAAS_TOKEN_FILE') ?: '/run/secrets/cloudflare-saas-token';
		if ( !preg_match( '/^[a-f0-9]{32}$/D', $zone ) || !is_readable( $file ) ) {
			return null;
		}
		$token = trim( file_get_contents( $file ) ?: '' );
		return $token !== '' && !preg_match( '/\s/', $token ) ? new self( $zone, $token ) : null;
	}

	public function find( string $host ): ?array {
		$query = http_build_query( [ 'hostname' => $host, 'per_page' => 5 ] );
		foreach ( $this->request( 'GET', "?$query" ) as $item ) {
			if ( ( $item['hostname'] ?? null ) === $host ) {
				return $item;
			}
		}
		return null;
	}

	public function get( string $id ): ?array {
		return $this->request( 'GET', '/' . rawurlencode( $id ), allowMissing: true );
	}

	public function create( string $host ): array {
		return $this->request( 'POST', '', [ 'hostname' => $host, 'ssl' => [ 'method' => 'http', 'type' => 'dv' ] ] );
	}

	public function delete( string $id ): void {
		$this->request( 'DELETE', '/' . rawurlencode( $id ), allowMissing: true );
	}

	private function request( string $method, string $suffix, ?array $payload = null, bool $allowMissing = false ): ?array {
		$url = "https://api.cloudflare.com/client/v4/zones/{$this->zone}/custom_hostnames$suffix";
		$curl = curl_init( $url );
		curl_setopt_array( $curl, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => [ 'Authorization: Bearer ' . $this->token, 'Content-Type: application/json' ],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 12,
		] );
		if ( $payload !== null ) {
			curl_setopt( $curl, CURLOPT_POSTFIELDS, json_encode( $payload, JSON_THROW_ON_ERROR ) );
		}
		$body = curl_exec( $curl );
		$status = (int)curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$data = is_string( $body ) ? json_decode( $body, true ) : null;
		if ( $allowMissing && $status === 404 ) {
			return null;
		}
		if ( $status < 200 || $status >= 300 || ( $data['success'] ?? false ) !== true ||
			!is_array( $data['result'] ?? null )
		) {
			// Response bodies can contain customer validation records. Keep them out of logs.
			$errors = $data['errors'] ?? [];
			$codes = is_array( $errors ) ? array_filter( array_column( $errors, 'code' ), 'is_int' ) : [];
			throw new RuntimeException( "Cloudflare custom hostname API: $method returned HTTP $status" .
				( $codes ? ' (' . implode( ', ', $codes ) . ')' : '' ), $status );
		}
		return $data['result'];
	}
}
