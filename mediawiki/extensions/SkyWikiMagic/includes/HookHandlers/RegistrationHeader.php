<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\SpecialPage\Hook\AuthChangeFormFieldsHook;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

class RegistrationHeader implements BeforePageDisplayHook, AuthChangeFormFieldsHook {

	public function onBeforePageDisplay( $out, $skin ): void {
		$name = $this->getWikiName( $out->getContext() );
		if ( $name === null ) {
			return;
		}
		// Keep success and error page headings supplied by account creation itself.
		if ( $out->getPageTitle() !== $out->msg( 'createaccount' )->escaped() ) {
			return;
		}

		$out->setPageTitleMsg(
			$out->msg( 'createnewwiki-registration-heading' )->plaintextParams( $name )
		);
	}

	public function onAuthChangeFormFields( $requests, $fieldInfo, &$formDescriptor, $action ): void {
		if ( in_array( $action, [ AuthManager::ACTION_CREATE, AuthManager::ACTION_CREATE_CONTINUE ], true ) ) {
			unset( $formDescriptor['realname'] );
		}

		$context = RequestContext::getMain();
		if ( $action !== AuthManager::ACTION_CREATE || $this->getWikiName( $context ) === null ) {
			return;
		}

		$request = $context->getRequest();
		$params = [
			'returnto' => $request->getText( 'returnto' ),
			'returntoquery' => $request->getText( 'returntoquery' ),
		];
		// Preserve the centralized-login state using the same hook as the core auth forms.
		MediaWikiServices::getInstance()->getHookContainer()->run( 'AuthPreserveQueryParams', [ &$params, [] ] );
		$link = Html::element( 'a', [
			'href' => SpecialPage::getTitleFor( 'Userlogin' )->getLocalURL( $params ),
		], $context->msg( 'login' )->text() );
		$formDescriptor['wikiCreationLogin'] = [
			'type' => 'info',
			'raw' => true,
			'cssclass' => 'mw-form-related-link-container skywiki-registration-login',
			'default' => $context->msg( 'createnewwiki-registration-login-prompt' )->rawParams( $link )->escaped(),
			'weight' => 200,
		];
	}

	private function getWikiName( IContextSource $context ): ?string {
		$title = $context->getTitle();
		if ( !$title || !$title->isSpecial( 'CreateAccount' ) || $context->getUser()->isRegistered() ) {
			return null;
		}
		$request = $context->getRequest();
		$returnTo = Title::newFromText( $request->getText( 'returnto' ) );
		if ( !$returnTo || !$returnTo->equals( SpecialPage::getTitleFor( 'CreateNewWiki', 'wiki' ) ) ) {
			return null;
		}
		$query = wfCgiToArray( $request->getText( 'returntoquery' ) );
		$name = $query['wpsitename'] ?? '';
		return is_string( $name ) && trim( $name ) !== '' ? trim( $name ) : null;
	}
}
