<?php

// Load only the checked-in Semver classes, without MediaWiki's autoload side effects.
$directory = $argv[1];
spl_autoload_register( static function ( string $class ) use ( $directory ): void {
	$prefix = 'Composer\\Semver\\';
	if ( str_starts_with( $class, $prefix ) ) {
		$path = $directory . '/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $path ) ) {
			require $path;
		}
	}
} );
$pairs = json_decode( stream_get_contents( STDIN ), true, 512, JSON_THROW_ON_ERROR );
$output = [];
foreach ( $pairs as [ $version, $constraint ] ) {
	try {
		$output[] = Composer\Semver\Semver::satisfies( $version, $constraint );
	} catch ( UnexpectedValueException $e ) {
		$output[] = null;
	}
}
echo json_encode( $output, JSON_THROW_ON_ERROR );
