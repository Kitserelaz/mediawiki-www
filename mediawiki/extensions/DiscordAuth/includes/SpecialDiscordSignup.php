<?php
/** @license GPL-2.0-or-later */

namespace MediaWiki\Extension\DiscordAuth;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\CreateFromLoginAuthenticationRequest;
use MediaWiki\Auth\UsernameAuthenticationRequest;
use MediaWiki\Auth\UserDataAuthenticationRequest;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserNameUtils;
use MediaWiki\Specials\SpecialCreateAccount;
use MediaWiki\Language\FormatterFactory;
use MediaWiki\User\UserIdentityUtils;
use MediaWiki\Specials\Helpers\LoginHelper;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;

/** Username selection after OAuth, using all standard account creation checks. */
class SpecialDiscordSignup extends SpecialCreateAccount {
	public function __construct( AuthManager $manager, FormatterFactory $formatter, UserIdentityUtils $identityUtils ) {
		parent::__construct( $manager, $formatter, $identityUtils );
		$this->mName = 'DiscordSignup';
	}

	public function isListed() {
		return false;
	}

	public function getDescription() {
		return $this->msg( 'discordauth-choose-username' );
	}

	public function execute( $subPage ) {
		if ( !$this->pendingSignup() ) {
			$this->setHeaders();
			$this->getOutput()->addWikiMsg( 'discordauth-expired' );
			return;
		}
		parent::execute( $subPage );
	}

	protected function loadAuth( $subPage, $authAction = null, $reset = false ) {
		parent::loadAuth( $subPage, $authAction, $reset );
		$transfer = $this->pendingSignup();
		if ( $this->authAction === AuthManager::ACTION_CREATE && $transfer ) {
			$this->authRequests = array_filter( $this->authRequests,
				static fn ( $req ) => $req->required !== AuthenticationRequest::PRIMARY_REQUIRED
					&& !$req instanceof UserDataAuthenticationRequest );
			$this->authRequests[] = new UsernameAuthenticationRequest();
			$this->authRequests[] = new DiscordUserDataRequest( $transfer->createRequest->email );
			$this->authRequests[] = clone $transfer;
		}
	}

	protected function getFieldDefinitions( array $fieldInfo, array $requests ) {
		$fields = parent::getFieldDefinitions( $fieldInfo, $requests );
		$identity = $this->pendingSignup()?->createRequest;
		if ( $identity instanceof DiscordIdentityRequest && isset( $fields['username'] ) ) {
			$suggestion = MediaWikiServices::getInstance()->getUserNameUtils()
				->getCanonical( $identity->label, UserNameUtils::RIGOR_CREATABLE );
			$fields['username']['default'] = $suggestion === false
				? 'Discord user ' . substr( $identity->discordId, -6 ) : $suggestion;
		}
		return $fields;
	}

	protected function successfulAction( $direct = false, $extraMessages = null ) {
		$this->getRequest()->getSession()->remove( SpecialDiscordLogin::SIGNUP_KEY );
		parent::successfulAction( $direct, $extraMessages );
	}

	protected function showSuccessPage( $type, $title, $msgname, $injected_html, $extraMessages ) {
		if ( $type !== 'signup' || $extraMessages ) {
			parent::showSuccessPage( $type, $title, $msgname, $injected_html, $extraMessages );
			return;
		}
		// Keep the normal completion hooks and CentralAuth's signed cross-wiki handoff,
		// but never render the default welcome/return-to page after successful signup.
		$helper = new LoginHelper( $this->getContext() );
		$helper->showReturnToPage( 'signup', $this->mReturnTo, $this->mReturnToQuery,
			$this->mStickHTTPS, $this->mReturnToAnchor );
		$out = $this->getOutput();
		$out->clearHTML();
		if ( $out->getRedirect() ) {
			return;
		}
		// Direct shared-domain entry has no SUL3 return token. Its URL still identifies
		// the originating wiki; resolve the destination from trusted farm metadata.
		$target = Title::newFromText( $this->mReturnTo );
		if ( !$target || $target->isExternal() || $target->isSpecial( 'DiscordLogin' )
			|| $target->isSpecial( 'DiscordSignup' ) || $target->isSpecial( 'Userlogin' )
			|| $target->isSpecial( 'CreateAccount' )
		) {
			$target = Title::newMainPage();
		}
		$wiki = WikiMap::getWiki( WikiMap::getCurrentWikiId() );
		// Without a SUL3 token, let core's top-level autologin establish the local
		// session before it returns to the requested page. A bare page redirect can
		// arrive anonymously when the pre-OAuth cookies have been lost.
		$url = wfAppendQuery( $wiki->getCanonicalUrl( 'Special:UserLogin' ), [
			'returnto' => $target->getPrefixedText(),
			'returntoquery' => $this->mReturnToQuery,
			'returntoanchor' => $this->mReturnToAnchor,
		] );
		$out->redirect( $url );
	}

	private function pendingSignup(): ?CreateFromLoginAuthenticationRequest {
		$pending = $this->getRequest()->getSession()->getSecret( SpecialDiscordLogin::SIGNUP_KEY );
		return $pending instanceof CreateFromLoginAuthenticationRequest
			&& $pending->createRequest instanceof DiscordIdentityRequest
			&& $pending->createRequest->isFresh() ? $pending : null;
	}
}
