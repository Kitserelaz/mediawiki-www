<?php

use Wikimedia\AtEase\AtEase;

class ExtSubpageFun {

	private const MAG_SUBPAGETITLE = 'subpagetitle';
	private const MAG_SUBPAGES = 'subpages';
	private const MAG_PARENTPAGES = 'parentpages';
	private const MAG_SIBLINGPAGES = 'siblingpages';
	private const MAG_SUBPAGELEVEL = 'subpagelevel';
	private const MAG_NUMBEROFSUBPAGES = 'numberofsubpages';
	private const MAG_TOPLEVELPAGE = 'toplevelpage';

	/**
	 * @param Parser &$parser
	 */
	public static function init( Parser &$parser ) {
		// optional Parser::SFH_NO_HASH to omit the hash '#' from function names
		$parser->setFunctionHook( self::MAG_SUBPAGETITLE, [ __CLASS__, 'pf_subpagetitle' ], Parser::SFH_NO_HASH );
		$parser->setFunctionHook( self::MAG_SUBPAGES, [ __CLASS__, 'pf_subpages' ], Parser::SFH_NO_HASH );
		$parser->setFunctionHook( self::MAG_PARENTPAGES, [ __CLASS__, 'pf_parentpages' ], Parser::SFH_NO_HASH );
		$parser->setFunctionHook( self::MAG_SIBLINGPAGES, [ __CLASS__, 'pf_siblingpages' ], Parser::SFH_NO_HASH );
		$parser->setFunctionHook( self::MAG_SUBPAGELEVEL, [ __CLASS__, 'pf_subpagelevel' ], Parser::SFH_NO_HASH );
		$parser->setFunctionHook( self::MAG_NUMBEROFSUBPAGES, [ __CLASS__, 'pf_numberofsubpages' ], Parser::SFH_NO_HASH );
		$parser->setFunctionHook( self::MAG_TOPLEVELPAGE, [ __CLASS__, 'pf_toplevelpage' ], Parser::SFH_NO_HASH );
	}

	/**
	 * Helper function for separating n arguments of a MW parser function
	 * @param array $args
	 * @return string[]
	 */
	private static function getFunctionArgsArray( $args ) {
		# Populate $argv with both named and numeric parameters
		$argv = [];
		$numargs = 0;

		foreach ( $args as $arg ) {
			if ( !is_object( $arg ) ) {
				if ( preg_match(
					'/^([^\\n\\r]+?)\\s*=\\s*(.*)$/s', // s - include newline. Parameter name is not supposed to have linebreaks
					$arg,
					$match
				) ) {
					$argv[ trim( $match[1] ) ] = trim( $match[2] );
				} else {
					$numargs++;
					$argv[ $numargs ] = trim( $arg );
				}
			}
		}
		return $argv;
	}

	/**
	 * Helper to get a new title from user input. Returns null if invalid title is given.
	 *
	 * @param Parser &$parser
	 * @param string|null $title
	 *
	 * @return Title|null
	 */
	private static function newTitleObject( Parser &$parser, $title = null ) {
		if ( is_array( $title ) ) {
			/*
			 * Instead of one Title, all arguments given to the parser function are given.
			 * This is because it makes things more generic to deal with extension 'Parser Fun' support
			 * especially for functions only requiring an option title.
			 */
			// get all possible arguments:
			$args = self::getFunctionArgsArray( $title );
			$title = $args[1] ?? null;
		}

		if ( $title !== null && $title !== '' ) {
			return Title::newFromText( $title );
		}
		// returns object of current page if nothing else is requested:
		return $parser->getTitle();
	}

	/**
	 * Create a list with page titles as final output of a SubpageFun function.
	 * The output ist un-parsed wiki markup, no HTML.
	 *
	 * @param array $pages array of Title elements
	 * @param bool $link whether or not to link the pages in the list
	 * @param string $sep glue between the pages
	 *
	 * @return string
	 */
	protected static function createSiteList( $pages, $link = false, $sep = ', ' ) {
		$out = [];
		foreach ( $pages as $page ) {
			$text = $page->getPrefixedText();
			if ( $link ) {
				$out[] = "[[:{$text}]]";
			} else {
				$text = wfEscapeWikiText( $text );
				$out[] = $text;
			}
		}
		return implode( $sep, $out );
	}

	/**
	 * Filters a list of title elements by a word or a regular expression.
	 * The titles name without prefix is taken for comparision.
	 *
	 * @param array $list
	 * @param string|null $filter
	 *
	 * @return array
	 */
	protected static function filterSiteList( array $list, $filter = null ) {
		// return all if no filter set:
		if ( $filter === null ) {
			return $list;
		}
		if ( !self::isValidRegEx( $filter ) ) {
			// no regex given, create one returning everything having the $filter words in it
			$filters = explode( '|', $filter );
			foreach ( $filters as &$part ) {
				$part = preg_quote( trim( $part ), '/' );
			}
			$filter = '/^.*(?:' . implode( '|', $filters ) . ').*$/i';
		}

		// create new array from all matches:
		$newList = [];

		foreach ( $list as $t ) {
			if ( preg_match( $filter, $t->getText() ) ) {
				$newList[] = $t;
			}
		}
		return $newList;
	}

	/**
	 * Decides for the given $pattern whether its a valid regular expression acceptable for
	 * the 'filter' parameter or not.
	 *
	 * @param string $pattern regular expression including delimiters and optional flags
	 *
	 * @return bool
	 */
	public static function isValidRegEx( $pattern ) {
		// validate first for allowd delimiters '/%|' and flags
		if ( !preg_match( '/^([\\/\\|%]).*\\1[imsSuUx]*$/', $pattern ) ) {
			return false;
		}
		AtEase::suppressWarnings(); // instead of using the evil @ operator!
		$isValid = preg_match( $pattern, ' ' ) !== false; // preg_match returns false on error
		AtEase::restoreWarnings();
		return $isValid;
	}

	/**
	 * Function to validate the paraameter depth required by some functions
	 * returns null if no value or invalid value is given, an integer if
	 * a number, including negative value, is given
	 *
	 * @param mixed $depth
	 *
	 * @return int|null
	 */
	protected static function valDepth( $depth ) {
		if ( $depth === null || $depth === false || trim( $depth ) === '' ) {
			return null;
		}
		// if it is '0'
		if ( $depth == 0 ) {
			return 0;
		}
		// if it is a number:
		if ( $depth < 0 || (string)(int)$depth === $depth ) {
			return $depth;
		} else {
			// invalid value like text:
			return null;
		}
	}

	/** 'Subpage Fun' parser functions: */

	/**
	 * @param Parser &$parser
	 * @return string Wikitext
	 */
	public static function pf_subpagetitle( &$parser /* , $title = null */ ) {
		$t = self::newTitleObject( $parser, func_get_args() );
		if ( $t === null ) {
			return ''; // invalid title given
		}
		return wfEscapeWikiText( SubpageInfo::getSubpageTitle( $t ) );
	}

	/**
	 * @param Parser &$parser
	 * @return string Wikitext
	 */
	public static function pf_subpages( &$parser ) {
		// get all possible arguments:
		$args = self::getFunctionArgsArray( func_get_args() );

		$title = $args[1] ?? null;
		$linked = isset( $args['linked'] );
		$sep = $args['sep'] ?? ', ';
		$filter = $args['filter'] ?? null;
		$depth = self::valDepth( $args['depth'] ?? null );

		// function logic:
		$t = self::newTitleObject( $parser, $title );
		if ( $t === null ) {
			return ''; // invalid title given
		}

		// get subpages:
		$subpages = SubpageInfo::getSubpages( $t, $depth );

		// filter by filter criterion:
		$subpages = self::filterSiteList( $subpages, $filter );

		return self::createSiteList( $subpages, $linked, $sep );
	}

	/**
	 * @param Parser &$parser
	 * @return string Wikitext
	 */
	public static function pf_parentpages( &$parser ) {
		// get all possible arguments:
		$args = self::getFunctionArgsArray( func_get_args() );

		$title = $args[1] ?? null;
		$linked = isset( $args['linked'] );
		$sep = $args['sep'] ?? ', ';
		$filter = $args['filter'] ?? null;
		$depth = self::valDepth( $args['depth'] ?? null );

		// function logic:
		$t = self::newTitleObject( $parser, $title );
		if ( $t === null ) {
			return ''; // invalid title given
		}

		// get parent pages:
		$parentpages = SubpageInfo::getAncestorPages( $t, $depth );

		// filter by filter criterion:
		$parentpages = self::filterSiteList( $parentpages, $filter );

		return self::createSiteList( $parentpages, $linked, $sep );
	}

	/**
	 * @param Parser &$parser
	 * @return string Wikitext
	 */
	public static function pf_siblingpages( &$parser ) {
		// get all possible arguments:
		$args = self::getFunctionArgsArray( func_get_args() );

		$title = $args[1] ?? null;
		$linked = isset( $args['linked'] );
		$sep = $args['sep'] ?? ', ';
		$filter = $args['filter'] ?? null;

		// function logic:
		$t = self::newTitleObject( $parser, $title );
		if ( $t === null ) {
			return ''; // invalid title given
		}

		// get siblings:
		$siblingpages = SubpageInfo::getSiblingPages( $t );

		// filter by filter criterion:
		$siblingpages = self::filterSiteList( $siblingpages, $filter );

		return self::createSiteList( $siblingpages, $linked, $sep );
	}

	/**
	 * @param Parser &$parser
	 * @return string Wikitext
	 */
	public static function pf_subpagelevel( &$parser /* , $title = null */ ) {
		$t = self::newTitleObject( $parser, func_get_args() );
		if ( $t === null ) {
			return ''; // invalid title given
		}
		return SubpageInfo::getSubpageLevel( $t );
	}

	/**
	 * @param Parser &$parser
	 * @return string Wikitext
	 */
	public static function pf_numberofsubpages( &$parser ) {
		// get all possible arguments:
		$args = self::getFunctionArgsArray( func_get_args() );

		$title = $args[1] ?? null;
		$depth = self::valDepth( $args['depth'] ?? null );
		$filter = $args['filter'] ?? null;

		// function logic:
		$t = self::newTitleObject( $parser, $title );
		if ( $t === null ) {
			return ''; // invalid title given
		}

		// get subpages:
		$subpages = SubpageInfo::getSubpages( $t, $depth );

		// filter by filter criterion:
		$subpages = self::filterSiteList( $subpages, $filter );

		return count( $subpages );
	}

	/**
	 * @param Parser &$parser
	 * @return string Wikitext
	 */
	public static function pf_toplevelpage( &$parser /* , $title = null */ ) {
		$t = self::newTitleObject( $parser, func_get_args() );
		if ( $t === null ) {
			return ''; // invalid title given
		}

		// get all parents because the toplevel is the highest existing parent:
		$parentpages = SubpageInfo::getAncestorPages( $t );

		if ( !empty( $parentpages ) ) {
			return wfEscapeWikiText( $parentpages[0]->getPrefixedText() );
		} else {
			////no parent! The page itself is the top level:
			return wfEscapeWikiText( $t->getPrefixedText() );
		}
	}

	/** All the SubpageFunctions for use with MW Variables on the current page */

	/**
	 * @param Parser $parser
	 * @param array &$cache
	 * @param string $magicWordId
	 * @param string|null &$ret
	 */
	public static function onParserGetVariableValueSwitch( Parser $parser, &$cache, $magicWordId, &$ret ) {
		switch ( $magicWordId ) {
			case self::MAG_SUBPAGETITLE:
			case self::MAG_SUBPAGES:
			case self::MAG_PARENTPAGES:
			case self::MAG_SIBLINGPAGES:
			case self::MAG_SUBPAGELEVEL:
			case self::MAG_NUMBEROFSUBPAGES:
			case self::MAG_TOPLEVELPAGE:
				self::variableValueSwitch( $parser, $magicWordId, $ret );
				$cache[$magicWordId] = $ret;
		}
	}

	/**
	 * Make 'Parser Fun' extensions 'THIS' function work with our variables/functions
	 *
	 * @param Parser &$parser
	 * @param Title $title
	 * @param string &$magicWordId
	 * @param string|null &$ret
	 * @param PPframe $frame
	 * @param array $args
	 */
	public static function onGetThisVariableValueSwitch( Parser &$parser, Title $title, &$magicWordId, &$ret, PPFrame $frame, array $args ) {
		$expArgs = [];
		foreach ( $args as $arg ) {
			$expArgs[] = trim( $frame->expand( $arg ) );
		}
		$expArgs[] = '1=' . $title->getPrefixedText();

		self::variableValueSwitch( $parser, $magicWordId, $ret, $expArgs );
	}

	/**
	 * Where value assigning for normal variables and 'Parser Fun' extensions 'THIS' come together
	 *
	 * @param Parser &$parser
	 * @param string $magicWordId
	 * @param string|null &$ret
	 * @param mixed $args
	 */
	private static function variableValueSwitch( Parser &$parser, $magicWordId, &$ret, $args = [] ) {
		// function to call
		$func = null;

		switch ( $magicWordId ) {
			case self::MAG_SUBPAGETITLE:
				$func = 'pf_subpagetitle';
				break;
			case self::MAG_SUBPAGES:
				$func = 'pf_subpages';
				break;
			case self::MAG_PARENTPAGES:
				$func = 'pf_parentpages';
				break;
			case self::MAG_SIBLINGPAGES:
				$func = 'pf_siblingpages';
				break;
			case self::MAG_SUBPAGELEVEL:
				$func = 'pf_subpagelevel';
				break;
			case self::MAG_NUMBEROFSUBPAGES:
				$func = 'pf_numberofsubpages';
				break;
			case self::MAG_TOPLEVELPAGE:
				$func = 'pf_toplevelpage';
				break;
		}
		if ( $func !== null ) {
			$args = array_merge( [ &$parser ], $args ); // $parser as first argument!
			$ret = call_user_func_array( [ __CLASS__, $func ], $args );
		}
	}

	/**
	 * @param string[] &$customVariableIds Array of custom variables that MediaWiki recognizes
	 */
	public static function onMagicWordwgVariableIDs( &$customVariableIds ) {
		// register variable ids:
		$customVariableIds[] = self::MAG_SUBPAGETITLE;
		$customVariableIds[] = self::MAG_SUBPAGES;
		$customVariableIds[] = self::MAG_PARENTPAGES;
		$customVariableIds[] = self::MAG_SIBLINGPAGES;
		$customVariableIds[] = self::MAG_SUBPAGELEVEL;
		$customVariableIds[] = self::MAG_NUMBEROFSUBPAGES;
		$customVariableIds[] = self::MAG_TOPLEVELPAGE;
	}

}
