<?php

use MediaWiki\MediaWikiServices;

class NamespacePreloadHooks {

	/**
	 * @param string $text
	 * @param Title $title
	 */
	public static function onEditFormPreloadText( string &$text, Title $title ) {
		if ( $text && strlen( $text ) > 0 ) return;     // do not override other extensions
		$ns = $title->getNamespace();
		if ( $ns < 0 ) return;                          // a special page or something

		$mws = MediaWikiServices::getInstance();
		$config = $mws->getMainConfig();

		$key = 'preload-namespace-' . $ns;
		$msg = wfMessage( $key );
		if ( $msg->isDisabled() ) return;
		if ( $config->get( 'NamespacePreloadDoExpansion' ) ) {
			$text = $msg->text();
		} else {
			$text = $msg->plain();
		}

		if ( $config->get( 'NamespacePreloadDoPreSaveTransform' ) ) {
			$context = RequestContext::getMain();
			$po = ParserOptions::newCanonical( $context );
			$parser = $mws->getParser();
			$text = $parser->preSaveTransform( $text, $title, $context->getUser(), $po );
		}
	}
}
