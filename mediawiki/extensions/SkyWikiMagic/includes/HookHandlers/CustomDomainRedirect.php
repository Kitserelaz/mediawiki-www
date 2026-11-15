<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Hook\MediaWikiPerformActionHook;
use MediaWiki\MediaWikiServices;
use WikiOasis\WikiOasisMagic\CustomDomainConfig;

final class CustomDomainRedirect implements MediaWikiPerformActionHook {
	public function onMediaWikiPerformAction( $output, $article, $title, $user, $request, $mediaWiki ) {
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( !CustomDomainConfig::routingEnabled() ||
			!in_array( $request->getMethod(), [ 'GET', 'HEAD' ], true ) ||
			$request->getVal( 'action', 'view' ) !== 'view' || !str_starts_with( $uri, '/wiki/' ) ||
			preg_match( '/[\x00-\x20\x7f]/', $uri )
		) {
			return true;
		}
		$wiki = $output->getConfig()->get( 'DBname' );
		$builtin = CustomDomainConfig::slug( $wiki ) . '.' . \MirahezeFunctions::getPrimaryDomain( $wiki );
		if ( strtolower( $_SERVER['HTTP_HOST'] ?? '' ) !== $builtin ) {
			return true;
		}
		$store = MediaWikiServices::getInstance()->get( 'SkyWikiCustomDomains' )->store;
		$host = $store->active( $wiki );
		if ( !$host || CustomDomainConfig::hostname( $host ) !== $host ||
			$store->wiki( $wiki )?->wiki_url !== "https://$host"
		) {
			return true;
		}
		$output->enableClientCache( false );
		$output->redirect( "https://$host$uri" );
		return false;
	}
}
