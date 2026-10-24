<?php
/** @license GPL-2.0-or-later */

namespace MediaWiki\Extension\DiscordAuth;

use MediaWiki\Html\Html;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Context\RequestContext;

class Hooks implements LoadExtensionSchemaUpdatesHook, GetPreferencesHook {
	public function onGetFullURL( $title, &$url, $query ) {
		if ( !$title->isSpecial( 'DiscordLogin' ) && !$title->isSpecial( 'DiscordSignup' )
			&& !$title->isSpecial( 'LinkAccounts' )
		) {
			return;
		}
		$config = \MediaWiki\MediaWikiServices::getInstance()->getMainConfig();
		$canonical = $config->get( 'CanonicalServer' );
		$callback = $config->get( 'DiscordAuthCallbackUrl' );
		if ( OAuthFlow::sameOrigin( $canonical, $callback ) ) {
			// Keep both the /return step and core's subsequent URL cleanup on auth's host.
			$url = OAuthFlow::continuationUrl( $url, $config->get( 'Server' ), $canonical, $callback ) ?? $url;
		}
	}

	public function onAuthChangeFormFields( array $requests, array $fieldInfo, array &$descriptor, $action ) {
		if ( !in_array( $action, [ AuthManager::ACTION_LOGIN, AuthManager::ACTION_CREATE ], true )
			|| !isset( $descriptor['discordLogin'] )
			|| RequestContext::getMain()->getTitle()?->isSpecial( 'DiscordLogin' )
		) {
			return;
		}
		// A separate link never submits or validates the password/username form.
		$request = RequestContext::getMain()->getRequest();
		$params = array_intersect_key( $request->getQueryValues(),
			array_flip( [ 'returnto', 'returntoquery', 'returntoanchor', 'uselang', 'useskin' ] ) );
		// Retain CentralAuth's signed return token, not a caller-provided destination URL.
		\MediaWiki\MediaWikiServices::getInstance()->getHookContainer()->run(
			'AuthPreserveQueryParams', [ &$params, [ 'request' => $request ] ]
		);
		$button = Html::element( 'a', [
				'href' => SpecialPage::getTitleFor( 'DiscordLogin' )->getLocalURL( $params ),
				'class' => 'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled ' .
					'cdx-button--action-progressive cdx-button--weight-primary discordauth-login',
			], wfMessage( 'discordauth-login' )->text() );
		$title = RequestContext::getMain()->getTitle();
		$isNativeForm = $title?->isSpecial( 'Userlogin' ) || $title?->isSpecial( 'CreateAccount' );
		if ( $isNativeForm ) {
			$button = Html::rawElement( 'aside', [
				'id' => 'discordauth-social', 'aria-labelledby' => 'discordauth-social-heading',
			], Html::rawElement( 'div', [ 'class' => 'discordauth-social-content' ],
				Html::element( 'h2', [ 'id' => 'discordauth-social-heading' ],
					wfMessage( 'discordauth-social-heading' )->text() ) . $button
			) );
		}
		$descriptor['discordLogin'] = [ 'type' => 'info', 'raw' => true,
			'rawrow' => $isNativeForm, 'default' => $button,
		];
	}

	public function onBeforePageDisplay( $out, $skin ) {
		$text = $out->getHTML();
		$title = $out->getTitle();
		if ( !( $title?->isSpecial( 'Userlogin' ) || $title?->isSpecial( 'CreateAccount' ) )
			|| !str_contains( $text, 'id="discordauth-social"' )
		) {
			return;
		}
		$document = new \DOMDocument();
		$loaded = $document->loadHTML( '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' .
			$text . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		if ( !$loaded ) {
			return;
		}
		$social = $document->getElementById( 'discordauth-social' );
		$form = $document->getElementById( 'userloginForm' );
		$container = $form?->parentNode;
		if ( !$social || !$container instanceof \DOMElement
			|| !in_array( 'mw-ui-container', explode( ' ', $container->getAttribute( 'class' ) ), true )
		) {
			return;
		}
		foreach ( $container->childNodes as $child ) {
			if ( $child instanceof \DOMElement && in_array( 'mw-createacct-benefits-container',
				explode( ' ', $child->getAttribute( 'class' ) ), true ) ) {
				$container->removeChild( $child );
				break;
			}
		}
		// The social link is a sibling of the entire password form, including without JavaScript.
		$container->appendChild( $social );
		$container->setAttribute( 'class', $container->getAttribute( 'class' ) . ' discordauth-columns' );
		$body = $document->getElementsByTagName( 'body' )->item( 0 );
		$text = '';
		foreach ( $body->childNodes as $node ) {
			$text .= $document->saveHTML( $node );
		}
		$out->clearHTML();
		$out->addHTML( $text );
		$out->addModuleStyles( 'ext.discordAuth.social.styles' );
	}
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-centralauth', 'addTable', 'discordauth_transaction',
			dirname( __DIR__ ) . '/sql/transaction.' . $updater->getDB()->getType() . '.sql', true,
		] );
		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-centralauth', 'addTable', 'discordauth_link',
			dirname( __DIR__ ) . '/sql/' . $updater->getDB()->getType() . '.sql', true,
		] );
	}

	public function onGetPreferences( $user, &$preferences ) {
		$services = \MediaWiki\MediaWikiServices::getInstance();
		$client = $services->getService( 'DiscordAuth.Client' );
		if ( !$client->isEnabled() ) {
			return;
		}
		$links = [];
		foreach ( [ 'LinkAccounts' => 'discordauth-link', 'RemoveCredentials' => 'discordauth-unlink' ]
			as $page => $message ) {
			$links[] = Html::element( 'a', [
				'href' => SpecialPage::getTitleFor( $page )->getLocalURL(),
			], wfMessage( $message )->text() );
		}
		$preferences['discordauth-accounts'] = [
			'type' => 'info', 'raw' => true, 'section' => 'personal/info',
			'label-message' => 'discordauth-provider', 'default' => implode( ' · ', $links ),
		];
	}
}
