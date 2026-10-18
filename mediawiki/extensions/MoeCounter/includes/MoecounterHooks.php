<?php

use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;

class MoecounterHooks {

    public static function onParserFirstCallInit( Parser $parser ) {
        $parser->setHook( 'moecounter', [ self::class, 'renderTag' ] );
    }

    public static function renderTag( $input, array $args, Parser $parser, PPFrame $frame ) {

        // expand tag body
        $expandedInput = trim( $parser->recursiveTagParse( $input, $frame ) );
        $encodedName = rawurlencode( $expandedInput );

        $baseUrl = 'https://count.cloudwiki.us/@' . $encodedName;

        // alt handling
        if ( isset( $args['alt'] ) ) {
            $alt = $parser->recursiveTagParse( $args['alt'], $frame );
            unset( $args['alt'] );
        } else {
            $alt = $expandedInput;
        }

        // build query
        $queryParts = [];
        foreach ( $args as $k => $v ) {
            $expanded = $parser->recursiveTagParse( $v, $frame );
            $queryParts[] = rawurlencode( $k ) . '=' . rawurlencode( $expanded );
        }

        $query = $queryParts ? '?' . implode( '&', $queryParts ) : '';

        $src = $baseUrl . $query;

        return '<img src="' .
            htmlspecialchars( $src, ENT_QUOTES ) .
            '" alt="' .
            htmlspecialchars( $alt, ENT_QUOTES ) .
            '" />';
    }
}
