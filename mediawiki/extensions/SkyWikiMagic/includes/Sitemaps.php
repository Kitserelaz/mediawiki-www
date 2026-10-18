<?php

namespace WikiOasis\WikiOasisMagic;

use InvalidArgumentException;

class Sitemaps {

	/** Remove published sitemaps for one wiki, including the legacy upload location. */
	public static function purge( string $dbname ): void {
		global $IP;

		if ( !preg_match( '/\A[a-z0-9_]+\z/', $dbname ) ) {
			throw new InvalidArgumentException( 'Invalid wiki database name' );
		}

		$root = realpath( $IP );
		if ( $root === false ) {
			throw new InvalidArgumentException( 'Invalid MediaWiki directory' );
		}

		foreach ( [
			"$root/sitemaps/$dbname",
			"$root/sitemaps/$dbname/sitemaps",
			"$root/images/$dbname/sitemaps",
		] as $directory ) {
			if ( !file_exists( $directory ) ) {
				continue;
			}
			// Never follow a symlink into another wiki's files.
			if ( realpath( $directory ) !== $directory ) {
				wfDebugLog( 'SkywikiMagic', "Refusing symlinked sitemap directory: $directory" );
				continue;
			}
			$paths = array_merge(
				glob( "$directory/sitemap*.xml" ) ?: [],
				glob( "$directory/sitemap*.xml.gz" ) ?: []
			);
			foreach ( $paths as $path ) {
				if ( ( is_file( $path ) || is_link( $path ) ) && !unlink( $path ) ) {
					wfDebugLog( 'SkywikiMagic', "Failed to remove sitemap: $path" );
				}
			}
		}
	}
}
