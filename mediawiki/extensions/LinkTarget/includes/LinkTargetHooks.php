<?php

namespace MediaWiki\Extension\LinkTarget;

use DOMXPath;
use HtmlFormatter\HtmlFormatter;
use MediaWiki\Hook\OutputPageParserOutputHook;
use OutputPage;
use ParserOutput;

class LinkTargetHooks implements OutputPageParserOutputHook {
	/**
	 * @param OutputPage $out
	 * @param ParserOutput $parseroutput
	 */
	public function onOutputPageParserOutput( $out, $parseroutput ): void {
		$config = $out->getConfig();
		$linkTargetParentClasses = $config->get( 'LinkTargetParentClasses' );
		$linkTargetDefault = $config->get( 'LinkTargetDefault' );

		if ( !$linkTargetParentClasses ) {
			// Save all that processing
			return;
		}

		if ( !is_array( $linkTargetParentClasses ) ) {
			$linkTargetParentClasses = [ $linkTargetParentClasses ];
		}

		$text = $parseroutput->getText();
		if ( !$text ) {
			return;
		}

		/** @todo Support framename? */
		$validTargets = [ '_blank', '_self', '_parent', '_top' ];

		$htmlFormatter = new HtmlFormatter( $text );
		$dom = $htmlFormatter->getDoc();
		$xpath = new DOMXpath( $dom );
		foreach ( $linkTargetParentClasses as $target => $parentClasses ) {
			if ( !is_array( $parentClasses ) ) {
				$parentClasses = [ $parentClasses ];
			}
			foreach ( $parentClasses as $parentClass ) {
				if ( !in_array( $target, $validTargets, true ) ) {
					$target = $linkTargetDefault;
				}
				$nodes = $xpath->query(
					"//*[contains(concat(' ', normalize-space(@class), ' '), ' {$parentClass} ')]//a" );
				foreach ( $nodes as $node ) {
					$node->setAttribute( 'target', $target );
				}
			}
		}
		$parseroutput->setText( $htmlFormatter->getText() );
	}
}
