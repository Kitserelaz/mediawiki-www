<?php

namespace WikiOasis\WikiOasisMagic;

use Miraheze\CreateWiki\Services\CreateWikiValidator;

class SubdomainSuggester {

	public function __construct( private readonly CreateWikiValidator $validator ) {
	}

	public function suggest( string $sitename ): ?string {
		$latinName = transliterator_transliterate( 'Any-Latin; Latin-ASCII; Lower()', $sitename );
		$base = preg_replace( '/[^a-z0-9]/', '', strtolower( $latinName ?: $sitename ) );
		// The domain already identifies this as a wiki, so omit a trailing 'wiki'.
		$base = preg_replace( '/wiki$/', '', $base );
		// Leave room for collision suffixes and the database suffix.
		$base = substr( $base, 0, 50 ) ?: 'wiki';

		for ( $attempt = 0; $attempt < 100; $attempt++ ) {
			if ( $attempt === 0 ) {
				$candidate = $base;
			} elseif ( $attempt < 20 ) {
				$candidate = $base . ( $attempt + 1 );
			} else {
				$candidate = $base . bin2hex( random_bytes( 4 ) );
			}

			if ( $this->validator->validateSubdomain( $candidate, [] ) === true ) {
				return $candidate;
			}
		}

		return null;
	}
}
