<?php

// Standalone checks: php extensions/SkyWikiMagic/tests/audit-regressions.php
// These fixtures never bootstrap MediaWiki or connect to its databases.
namespace MediaWiki\Parser {
	class Parser {
		public function recursiveTagParse( $text, $frame ) {
			return $text;
		}
	}
	class PPFrame {
	}
}

namespace {
	function check( bool $condition, string $message ): void {
		if ( !$condition ) {
			throw new RuntimeException( $message );
		}
	}

	function wfDebugLog( ...$args ): void {
	}

	class RefuseHttp {
		public function stream_open( ...$args ) {
			throw new RuntimeException( 'Parser must not make HTTP requests' );
		}
	}

	$installRoot = dirname( __DIR__, 3 );
	require_once dirname( __DIR__ ) . '/includes/Sitemaps.php';
	require_once "$installRoot/extensions/MoeCounter/includes/MoecounterHooks.php";

	$IP = sys_get_temp_dir() . '/cloudwiki-audit-' . bin2hex( random_bytes( 8 ) );
	$directories = [
		"$IP/sitemaps/targetwiki",
		"$IP/sitemaps/targetwiki/sitemaps",
		"$IP/images/targetwiki/sitemaps",
		"$IP/sitemaps/otherwiki",
	];
	try {
		foreach ( $directories as $directory ) {
			mkdir( $directory, 0755, true );
			file_put_contents( "$directory/sitemap.xml", 'fixture' );
			file_put_contents( "$directory/sitemap-index.xml.gz", 'fixture' );
			file_put_contents( "$directory/keep.txt", 'keep' );
		}
		\WikiOasis\WikiOasisMagic\Sitemaps::purge( 'targetwiki' );
		foreach ( array_slice( $directories, 0, 3 ) as $directory ) {
			check( !file_exists( "$directory/sitemap.xml" ), 'Published sitemap survived privacy cleanup' );
			check( !file_exists( "$directory/sitemap-index.xml.gz" ), 'Compressed sitemap survived privacy cleanup' );
			check( file_exists( "$directory/keep.txt" ), 'Cleanup removed unrelated content' );
		}
		check( file_exists( "$IP/sitemaps/otherwiki/sitemap.xml" ), 'Cleanup affected another wiki' );
		try {
			\WikiOasis\WikiOasisMagic\Sitemaps::purge( '../otherwiki' );
			throw new RuntimeException( 'Unsafe database name accepted' );
		} catch ( InvalidArgumentException $expected ) {
		}
		symlink( "$IP/sitemaps/otherwiki", "$IP/sitemaps/linkwiki" );
		\WikiOasis\WikiOasisMagic\Sitemaps::purge( 'linkwiki' );
		check( file_exists( "$IP/sitemaps/otherwiki/sitemap.xml" ), 'Cleanup followed a cross-wiki symlink' );
		echo "PASS: privacy cleanup removes both sitemap locations and preserves other wikis\n";

		stream_wrapper_unregister( 'http' );
		stream_wrapper_register( 'http', RefuseHttp::class );
		$parser = new \MediaWiki\Parser\Parser();
		$frame = new \MediaWiki\Parser\PPFrame();
		$html = MoecounterHooks::renderTag( 'visits', [], $parser, $frame );
		check( str_contains( $html, 'alt="visits"' ), 'Missing default alt text' );
		check( str_contains( $html, 'https://count.cloudwiki.us/@visits' ), 'Counter image URL changed' );
		$html = MoecounterHooks::renderTag( 'visits', [ 'alt' => '"><script>bad</script>' ], $parser, $frame );
		check( !str_contains( $html, '<script>' ), 'Alt text is not escaped' );
		echo "PASS: counter parsing makes no HTTP request and escapes alt text\n";

		$manifest = json_decode( file_get_contents( dirname( __DIR__ ) . '/extension.json' ), true );
		$handler = file_get_contents( dirname( __DIR__ ) . '/includes/CreateNewWikiForm.php' );
		preg_match( "/pingLimiter\\( '([^']+)' \\)/", $handler, $match );
		check( isset( $match[1] ), 'Missing wiki creation limiter' );
		check( ( $manifest['RateLimits'][$match[1]]['user'] ?? null ) === [ 1, 86400 ], 'Daily limit does not match the submission action' );
		echo "PASS: wiki creation handler and daily rate limit use the same action\n";

		$_SERVER['HTTP_HOST'] = '<script>private-marker</script>';
		ob_start();
		include "$installRoot/ErrorPages/MissingWiki.php";
		$errorPage = ob_get_clean();
		check( !str_contains( $errorPage, 'private-marker' ), 'Error page exposes request data' );
		check( !str_contains( $errorPage, 'var_dump' ), 'Error page includes diagnostics' );
		echo "PASS: missing-wiki page does not expose diagnostics or request data\n";
	} finally {
		if ( is_dir( $IP ) ) {
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $IP, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $files as $file ) {
				if ( $file->isDir() && !$file->isLink() ) {
					rmdir( $file->getPathname() );
				} else {
					unlink( $file->getPathname() );
				}
			}
			rmdir( $IP );
		}
	}
}
