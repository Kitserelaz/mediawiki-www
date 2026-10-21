<?php
namespace CategoryExplorer;

use MediaWiki\MediaWikiServices;
use Skin;
use Title;

class Hooks {
	/**
	 * Render the array as a series of links.
	 * @param array $tree Categories tree returned by Title::getParentCategoryTree
	 * @return string Separated by &gt;, terminate with "\n"
	 */
	private static function drawCategoryBrowser( array $tree ): string {
		$return = '';
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
		foreach ( $tree as $element => $parent ) {
			if ( !$parent ) {
				# element start a new list
				$return .= "\n";
			} else {
				# grab the others elements
				$return .= self::drawCategoryBrowser( $parent ) . ' &gt; ';
			}
			# add our current element to the list
			$eltitle = Title::newFromText( $element );
			$return .= $linkRenderer->makeLink( $eltitle, $eltitle->getText() );
		}
		return $return;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinAfterPortlet
	 * @param Skin $skin
	 * @param string $portlet
	 * @param string &$html
	 */
	public static function onSkinAfterPortlet( Skin $skin, string $portlet, string &$html ) {
		// Code goes here.
		if ( $portlet === 'category-normal' ) {
			$title = $skin->getOutput()->getTitle();
			$html .= '<br /><hr />';
			# get a big array of the parents tree
			$parenttree = $title->getParentCategoryTree();
			# Skin object passed by reference cause it can not be
			# accessed under the method subfunction drawCategoryBrowser
			$tempout = explode( "\n", self::drawCategoryBrowser( $parenttree ) );
			# Clean out bogus first entry and sort them
			unset( $tempout[0] );
			asort( $tempout );
			# Output one per line
			$html .= implode( "<br />\n", $tempout );
		}
	}
}
