<?php
/** Load declared runtime classes without constructing services or invoking features. */
function catalogueRuntimeClasses( string $manifestPath, array $manifest ): array {
    $root = '/var/www/mediawiki/' . dirname( $manifestPath );
    $classes = array_keys( $manifest['AutoloadClasses'] ?? [] );
    foreach ( $manifest['AutoloadNamespaces'] ?? [] as $prefix => $directory ) {
        $path = $root . '/' . $directory;
        if ( !is_dir( $path ) ) {
            throw new RuntimeException( 'Missing autoload namespace directory: ' . $prefix );
        }
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $file ) {
            if ( $file->getExtension() !== 'php' ||
                preg_match( '~/+(tests|Tests|vendor|node_modules)/~', $file->getPathname() )
            ) {
                continue;
            }
            $tokens = token_get_all( file_get_contents( $file->getPathname() ) );
            $declared = [];
            $namespace = '';
            for ( $i = 0; $i < count( $tokens ); $i++ ) {
                if ( !is_array( $tokens[$i] ) ) { continue; }
                if ( $tokens[$i][0] === T_NAMESPACE ) {
                    $namespace = '';
                    while ( ++$i < count( $tokens ) && !in_array( $tokens[$i], [ ';', '{' ], true ) ) {
                        if ( is_array( $tokens[$i] ) && in_array( $tokens[$i][0], [ T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR ], true ) ) {
                            $namespace .= $tokens[$i][1];
                        }
                    }
                } elseif ( in_array( $tokens[$i][0], [ T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ], true ) ) {
                    $next = $i + 1;
                    while ( isset( $tokens[$next] ) && is_array( $tokens[$next] ) &&
                        in_array( $tokens[$next][0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true )
                    ) { $next++; }
                    // Anonymous classes and ::class references have no following name.
                    if ( isset( $tokens[$next] ) && is_array( $tokens[$next] ) && $tokens[$next][0] === T_STRING ) {
                        $name = ( $namespace !== '' ? $namespace . '\\' : '' ) . $tokens[$next][1];
                        if ( str_starts_with( $name, $prefix ) ) { $declared[] = $name; }
                    }
                }
            }
            // Generated parsers may define helper classes before their PSR-4
            // entry class. Load that entry first, as production autoloading does.
            $relative = substr( $file->getRealPath(), strlen( realpath( $path ) ) + 1, -4 );
            $primary = $prefix . str_replace( '/', '\\', $relative );
            if ( in_array( $primary, $declared, true ) ) {
                $classes[] = $primary;
            }
            array_push( $classes, ...$declared );
        }
    }
    if ( str_ends_with( $manifestPath, '.php' ) ) {
        foreach ( $GLOBALS['wgAutoloadClasses'] ?? [] as $name => $file ) {
            if ( str_starts_with( $file, $root . '/' ) ) { $classes[] = $name; }
        }
    }
    $classes = array_values( array_unique( $classes ) );
    $loaded = [];
    $unavailable = [];
    foreach ( $classes as $name ) {
        try {
            if ( !class_exists( $name ) && !interface_exists( $name ) && !trait_exists( $name ) ) {
                throw new RuntimeException( 'Registered runtime class cannot load: ' . $name );
            }
            $loaded[] = $name;
        } catch ( Error $error ) {
            // These integrations cannot run when their optional provider is
            // absent from the shipped catalogue. Do not skip installed providers.
            $absent = [
                'MediaWiki\\Extension\\UserMerge\\' => 'UserMerge',
                'MediaWiki\\Extension\\CommunityConfiguration\\' => 'CommunityConfiguration',
            ];
            $provider = null;
            if ( preg_match( '/^(?:Class|Interface|Trait) "([^"]+)" not found$/D', $error->getMessage(), $match ) ) {
                foreach ( $absent as $prefix => $directory ) {
                    if ( str_starts_with( $match[1], $prefix ) &&
                        !is_dir( '/var/www/mediawiki/extensions/' . $directory )
                    ) { $provider = $directory; break; }
                }
            }
            if ( $provider === null ) { throw $error; }
            $unavailable[] = [ 'class' => $name, 'absent_optional_provider' => $provider ];
        }
    }
    return [ 'loaded' => $loaded, 'unavailable_optional_integrations' => $unavailable ];
}
