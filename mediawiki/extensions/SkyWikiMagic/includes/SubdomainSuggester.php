<?php

namespace WikiOasis\WikiOasisMagic;

use Miraheze\CreateWiki\Services\CreateWikiValidator;

class SubdomainSuggester {

	public function __construct( private readonly CreateWikiValidator $validator ) {
	}

	public function suggest( string $sitename, ?string $lang = null ): ?string {
		$latinName = $this->romanize( $sitename, $lang );
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

	/**
	 * Convert a wiki name to Latin characters. The wiki's selected language
	 * is the primary signal: Japanese (ja) titles get proper readings via
	 * kakasi (e.g. 薬屋のひとりごと -> kusuriyanohitorigoto); ICU alone
	 * would transliterate kanji as Chinese pinyin. As a fallback when no
	 * language is known, the presence of kana is treated as Japanese.
	 * Kanji-only names without a Japanese language selection stay on the
	 * ICU path, which keeps Chinese-language names transliterating
	 * correctly.
	 */
	private function romanize( string $sitename, ?string $lang ): ?string {
		$japanese = ( $lang !== null && strtolower( $lang ) === 'ja' ) ||
			preg_match( '/[\p{Hiragana}\p{Katakana}]/u', $sitename ) === 1;
		if ( !$japanese ) {
			return transliterator_transliterate( 'Any-Latin; Latin-ASCII; Lower()', $sitename );
		}

		$romaji = $this->runKakasi( $sitename );
		if ( is_string( $romaji ) && trim( $romaji ) !== '' ) {
			return trim( $romaji );
		}

		// kakasi is unavailable (e.g. an image built before it was added).
		return transliterator_transliterate( 'Any-Latin; Latin-ASCII; Lower()', $sitename );
	}

	/**
	 * Pipe the title through kakasi via stdin. Arguments are passed as an
	 * array so UTF-8 titles survive any shell locale.
	 */
	private function runKakasi( string $sitename ): ?string {
		if ( !function_exists( 'proc_open' ) ) {
			return null;
		}

		$pipes = [];
		$process = proc_open(
			[ 'kakasi', '-i', 'utf8', '-Ja', '-Ka', '-Ha' ],
			[ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes
		);
		if ( !is_resource( $process ) ) {
			return null;
		}

		fwrite( $pipes[0], $sitename );
		fclose( $pipes[0] );
		$romaji = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		return $romaji !== false ? $romaji : null;
	}
}
