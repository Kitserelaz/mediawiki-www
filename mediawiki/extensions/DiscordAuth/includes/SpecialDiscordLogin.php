<?php
/** @license GPL-2.0-or-later */

namespace MediaWiki\Extension\DiscordAuth;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\CreateFromLoginAuthenticationRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Specials\SpecialUserLogin;
use MediaWiki\User\UserIdentityUtils;

/** A dedicated core login flow containing only the Discord button, never a username. */
class SpecialDiscordLogin extends SpecialUserLogin {
	public const SIGNUP_KEY = 'DiscordAuth.pendingSignup';

	public function __construct( AuthManager $manager, UserIdentityUtils $identityUtils,
		private DiscordClient $client, private AuthorizationStore $authorizations
	) {
		parent::__construct( $manager, $identityUtils );
		$this->mName = 'DiscordLogin';
	}

	public function isListed() {
		return false;
	}

	public function execute( $subPage ) {
		$request = $this->getRequest();
		$this->getOutput()->disableClientCache();
		if ( !$subPage && !$request->wasPosted() ) {
			$resume = $request->getText( 'discordResume' );
			if ( $resume !== '' ) {
				if ( !preg_match( '/^[a-f0-9]{64}$/D', $resume ) ) {
					$this->setHeaders();
					$this->getOutput()->addWikiMsg( 'discordauth-expired' );
					return;
				}
			} else {
				$this->setHeaders();
				if ( !$this->client->isEnabled() ) {
					$this->getOutput()->addWikiMsg( 'discordauth-failed' );
					return;
				}
				// No AuthManager state or MediaWiki session is needed during the OAuth trip.
				$url = $this->getPageTitle()->getCanonicalURL( $this->getPreservedParams() );
				$state = $this->authorizations->start( $request, $url, $this->client->callbackUrl(),
					'login', '', true );
				$this->getOutput()->redirect( $this->client->authorizationUrl( $state ) );
				return;
			}
		}
		parent::execute( $subPage );
	}

	protected function loadAuth( $subPage, $authAction = null, $reset = false ) {
		parent::loadAuth( $subPage, $authAction, $reset );
		if ( $this->authAction === AuthManager::ACTION_LOGIN ) {
			$this->authRequests = array_filter( $this->authRequests,
				static fn ( $request ) => $request instanceof DiscordAuthenticationRequest );
		}
	}

	protected function mainLoginForm( array $requests, $msg = '', $msgtype = 'error' ) {
		$transfer = AuthenticationRequest::getRequestByClass( $requests,
			CreateFromLoginAuthenticationRequest::class );
		if ( $transfer && $transfer->createRequest instanceof DiscordIdentityRequest
			&& $transfer->createRequest->isFresh()
		) {
			// The identity is server-side only; no Discord ID is accepted from a form or URL.
			$transfer->maybeLink = [];
			$this->getRequest()->getSession()->setSecret( self::SIGNUP_KEY, $transfer );
			$params = $this->getPreservedParams( [ 'reset' => true ] );
			$this->getOutput()->redirect( SpecialPage::getTitleFor( 'DiscordSignup' )->getCanonicalURL( $params ) );
			return;
		}
		parent::mainLoginForm( $requests, $msg, $msgtype );
	}
}
