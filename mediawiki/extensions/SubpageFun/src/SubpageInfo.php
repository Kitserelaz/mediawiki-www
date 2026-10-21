<?php

/**
 * Static class with functions to gain subpage related information, MediaWiki doesn't offer
 * without further information processing.
 *
 * @since 0.4
 *
 * @author Daniel Werner
 *
 * @file
 * @ingroup SubpageFun
 */
class SubpageInfo {

	/**
	 * Delivers all subpages of a given page.
	 *
	 * @param Title $page The page to get the subpages from.
	 * @param int|null $depth Depth of the deepest subpage level to be counted relative from the given
	 *              page. null means no limit.
	 *
	 * @return Title[] All subpages of the given page.
	 */
	public static function getSubpages( Title $page, $depth = null ) {
		if ( $depth === 0 ) {
			return [];
		}

		$subpages = $page->getSubpages( -1 );
		$allSubpages = [];

		if ( $depth !== null ) {
			$maxSubpageLevel = self::getSubpageLevel( $page ) + $depth;
		}
		if ( !empty( $subpages ) ) {
			while ( $subpages->valid() ) {
				$curSub = $subpages->current();

				if ( $depth !== null ) {
					if ( self::getSubpageLevel( $curSub ) <= $maxSubpageLevel ) {
						$allSubpages[] = $curSub;
					}
				} else {
					$allSubpages[] = $curSub;
				}

				$subpages->next();
			}
		}
		return $allSubpages;
	}

	/**
	 * Delivers all ancestors of a given subpage.
	 *
	 * @param Title $page The page to get the ancestors from.
	 * @param int|null $depth Maximum depth back to the most distant ancestor relative from the given
	 *              subpage. If negative, that many elements from the top-level parent will be returend.
	 *              null means no limit.
	 *
	 * @return Title[] All ancestor pages of the given subpage in order from the top-level ancestor
	 *                 to the direct parent.
	 */
	public static function getAncestorPages( Title $page, $depth = null ) {
		$parts = preg_split( "/\//", $page->getPrefixedText(), 2 );
		$rootPage = Title::newFromText( $parts[0] );
		$pageFamily = self::getSubpages( $rootPage );
		$pages = [];

		// A page can't be it's own parent AND only a existing page can be a parent:
		if ( !( $rootPage->equals( $page ) ) && $rootPage->exists() ) {
			$pages[] = $rootPage;
		}

		if ( !empty( $pageFamily ) ) {
			// order is top-level parent to direct parent
			foreach ( $pageFamily as &$relativePage ) {
				if ( self::isAncestorOf( $relativePage, $page ) ) {
					$pages[] = $relativePage;
				}
			}
		}

		if ( $depth !== null ) {
			if ( $depth <= 0 ) {
				$pages = array_slice( $pages, 0, -$depth );
			} else {
				$pages = array_slice( $pages, -$depth );
			}
		}

		return $pages;
	}

	/**
	 * Delivers all siblings of a given subpage (won't deliver siblings of top level pages, would be to much)
	 *
	 * @param Title $page The page to get the siblings from.
	 *
	 * @return Title[] All sibling pages of the given subpage.
	 */
	public static function getSiblingPages( Title $page ) {
		$parent = self::getParentPage( $page );
		if ( empty( $parent ) ) {
			return [];
		}

		$siblingsAndMore = self::getSubpages( $parent );
		$allSiblings = [];

		foreach ( $siblingsAndMore as &$potentialSibling ) {
			if ( self::isSiblingOf( $page, $potentialSibling ) ) {
				$allSiblings[] = $potentialSibling;
			}
		}

		return $allSiblings;
	}

	/**
	 * Delivers the one and only parent page of a given page.
	 *
	 * @param Title $page Page to get the parent page from.
	 *
	 * @return Title Result The parent page of the given one if one exists, otherwise null
	 */
	private static function getParentPage( Title $page ) {
		$ancestors = self::getAncestorPages( $page );
		if ( !empty( $ancestors ) ) {
			return $ancestors[ count( $ancestors ) - 1 ];
		}

		return null;
	}

	/**
	 * Delivers the subpage level of a given Page.
	 *
	 * @param Title $page Page to get the level from.
	 *
	 * @return int Result The Level the given page has. Level 0 means the given page is no subpage at all.
	 */
	public static function getSubpageLevel( Title $page ) {
		$ancestors = self::getAncestorPages( $page );

		if ( !empty( $ancestors ) ) {
			return count( $ancestors );
		} else {
			// no parent! The page itself is the top level:
			return 0;
		}
	}

	/**
	 * Decide wheter a subpage is a sibling of another subpage.
	 *
	 * @param Title $page1 First subpage.
	 * @param Title $page2 Seccond subpage.
	 *
	 * @return bool Result true if both pages are each others siblings.
	 */
	private static function isSiblingOf( Title $page1, Title $page2 ) {
		if ( $page1->equals( $page2 ) ) {
			return false;
		}

		// if both pages have the same parent page:
		return self::getParentPage( $page1 )->equals( self::getParentPage( $page2 ) );
	}

	/**
	 * Decide wheter a page is the ancestor of another given page or not.
	 *
	 * @param Title $ancestor The parent/ancestor page.
	 * @param Title $descendant The child/descendant page.
	 *
	 * @return bool true if the given page really is the ancestor of the seccond page.
	 */
	private static function isAncestorOf( Title $ancestor, Title $descendant ) {
		// in case of both pages beeing the same:
		if ( $ancestor->equals( $descendant ) ) {
			return false;
		}

		// in case the ancestor is a part of the given descendant it is a parrent, except for ancestors
		//ending with "/": These could also be siblings and need further checking:
		$ancestorChildSlash = ( substr( $descendant->getText(), -1 ) != '/' ) ? '/' : '';

		return preg_match( '%^' . preg_quote( $ancestor->getText() . $ancestorChildSlash, '%' ) . '%', $descendant->getText() ) != 0;
	}

	/**
	 * Delivers the real subpage title, not only the part behind the last "/" like PAGENAME does.
	 *
	 * @param Title $page The page to get the title from.
	 *
	 * @return string Result The subpage title of the given page. If page isn't a subpage, the Pages
	 *                name (without prefix) will be returned.
	 */
	public static function getSubpageTitle( Title $page ) {
		$parent = self::getParentPage( $page );
		// return the whole subpage name not like SUBPAGENAME only the last part after the last "/":
		if ( !empty( $parent ) ) {
			return substr( $page->getText(), strlen( $parent->getText() . '/' ) );
		}
		return $page->getText(); // return PAGENAME
	}
}
