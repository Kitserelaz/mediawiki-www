<?php

// Tokenize JSON-supplied PHP source as data. Never include farm configuration.
$input = json_decode( stream_get_contents( STDIN ), true, 512, JSON_THROW_ON_ERROR );
$output = [];
foreach ( $input as $name => $source ) {
	$output[$name] = [];
	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) ) {
			if ( in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG ], true ) ) {
				continue;
			}
			$output[$name][] = [ token_name( $token[0] ), $token[1] ];
		} else {
			$output[$name][] = [ '', $token ];
		}
	}
}
echo json_encode( $output, JSON_THROW_ON_ERROR );
