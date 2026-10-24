<?php
/** @license GPL-2.0-or-later */
namespace MediaWiki\Extension\ArchiveWikiImport;

use MediaWiki\MediaWikiServices;
use RuntimeException;

/** Internet Archive discovery and bounded, checksum-verified streaming downloads. */
class ArchiveSource {
	public function __construct( private $pulse = null ) {
	}

	public static function identifier( string $input ): string {
		$input = trim( $input );
		if ( str_contains( $input, '://' ) ) {
			$url = parse_url( $input );
			if ( !$url || ( $url['scheme'] ?? '' ) !== 'https' ||
				!in_array( strtolower( $url['host'] ?? '' ), [ 'archive.org', 'www.archive.org' ], true ) ||
				( isset( $url['user'] ) || isset( $url['pass'] ) ) || isset( $url['port'] ) ||
				!preg_match( '~^/(?:details|download)/([^/]+)/?$~D', $url['path'] ?? '', $m ) ) {
				throw new RuntimeException( 'Enter an https://archive.org/details/ITEM URL or an item identifier.' );
			}
			$input = rawurldecode( $m[1] );
		}
		if ( !preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,199}$/D', $input ) ) {
			throw new RuntimeException( 'Invalid Internet Archive item identifier.' );
		}
		return $input;
	}

	public static function safePath( string $name ): bool {
		return $name !== '' && !str_contains( $name, '\\' ) &&
			!preg_match( '~(^/|(^|/)\.\.?(/|$)|[\x00-\x1f\x7f])~', $name );
	}

	public static function kind( string $name ): ?string {
		if ( preg_match( '/\.xml(?:\.(?:7z|zip|gz|bz2|zst))?$/iD', $name ) &&
			!preg_match( '/(?:_meta|_files|_reviews)\.xml$/iD', $name ) ) {
			return 'dump';
		}
		if ( preg_match( '/(?:images|uploads|media).*\.(?:7z|zip|tar)$/iD', basename( $name ) ) ) {
			return 'images';
		}
		return null;
	}

	public static function manifest( string $id, array $metadata ): array {
		$files = [];
		foreach ( $metadata['files'] ?? [] as $file ) {
			$name = $file['name'] ?? '';
			$kind = self::kind( $name );
			if ( !$kind || !self::safePath( $name ) || !empty( $file['private'] ) ||
				!preg_match( '/^[a-f0-9]{40}$/iD', $file['sha1'] ?? '' ) ||
				!is_numeric( $file['size'] ?? null ) || (float)$file['size'] <= 0 ) {
				continue;
			}
			$files[$name] = [ 'name' => $name, 'kind' => $kind,
				'size' => (int)$file['size'], 'sha1' => strtolower( $file['sha1'] ) ];
		}
		if ( !array_filter( $files, static fn ( $f ) => $f['kind'] === 'dump' ) ) {
			throw new RuntimeException( 'This item has no supported XML dump with a size and SHA-1 checksum.' );
		}
		return [ 'id' => $id, 'title' => (string)( $metadata['metadata']['title'] ?? $id ), 'files' => $files ];
	}

	public function discover( string $input ): array {
		$id = self::identifier( $input );
		$handle = tmpfile();
		try {
			$this->fetch( 'https://archive.org/metadata/' . rawurlencode( $id ), $handle, 8388608 );
			rewind( $handle );
			$data = json_decode( stream_get_contents( $handle ), true, 64, JSON_THROW_ON_ERROR );
			return self::manifest( $id, $data );
		} finally {
			fclose( $handle );
		}
	}

	public function download( string $id, array $file, string $target, int $limit ): void {
		if ( $file['size'] > $limit ) {
			throw new RuntimeException( 'Archive file exceeds the configured download limit.' );
		}
		if ( is_file( $target ) && filesize( $target ) === $file['size'] &&
			hash_file( 'sha1', $target ) === $file['sha1'] ) {
			return;
		}
		$out = fopen( $target . '.part', 'w+b' );
		try {
			$path = implode( '/', array_map( 'rawurlencode', explode( '/', $file['name'] ) ) );
			$this->fetch( 'https://archive.org/download/' . rawurlencode( $id ) . '/' . $path, $out, $file['size'] );
			fflush( $out );
		} finally {
			fclose( $out );
		}
		if ( filesize( $target . '.part' ) !== $file['size'] ||
			hash_file( 'sha1', $target . '.part' ) !== $file['sha1'] ) {
			unlink( $target . '.part' );
			throw new RuntimeException( 'Archive download failed size or SHA-1 verification.' );
		}
		if ( !rename( $target . '.part', $target ) ) {
			throw new RuntimeException( 'Cannot publish downloaded archive.' );
		}
	}

	private function fetch( string $url, $out, int $limit ): void {
		$pulse = $this->pulse;
		for ( $redirects = 0; $redirects < 6; $redirects++ ) {
			$parts = parse_url( $url );
			$host = strtolower( $parts['host'] ?? '' );
			if ( ( $parts['scheme'] ?? '' ) !== 'https' ||
				!( $host === 'archive.org' || str_ends_with( $host, '.archive.org' ) ) ||
				isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
				throw new RuntimeException( 'Archive redirected outside its trusted HTTPS hosts.' );
			}
			ftruncate( $out, 0 );
			rewind( $out );
			$bytes = 0;
			$request = MediaWikiServices::getInstance()->getHttpRequestFactory()->create( $url, [
				'method' => 'GET', 'followRedirects' => false, 'timeout' => 3600, 'connectTimeout' => 30,
				'callback' => static function ( $resource, $buffer ) use ( $out, &$bytes, $limit, $pulse ) {
					if ( $pulse ) { $pulse(); }
					$bytes += strlen( $buffer );
					return $bytes <= $limit ? ( fwrite( $out, $buffer ) ?: 0 ) : 0;
				}
			], __METHOD__ );
			$status = $request->execute();
			$code = $request->getStatus();
			if ( in_array( $code, [ 301, 302, 303, 307, 308 ], true ) ) {
				$location = $request->getResponseHeader( 'location' );
				if ( !is_string( $location ) || $location === '' ) {
					throw new RuntimeException( 'Archive returned a redirect without a location.' );
				}
				$url = str_starts_with( $location, '/' ) && !str_starts_with( $location, '//' )
					? 'https://' . $host . $location : $location;
				continue;
			}
			if ( !$status->isOK() || $code !== 200 || $bytes > $limit ) {
				throw new RuntimeException( 'Archive request failed (HTTP ' . $code . ') or exceeded its byte limit.' );
			}
			return;
		}
		throw new RuntimeException( 'Too many Archive redirects.' );
	}
}
