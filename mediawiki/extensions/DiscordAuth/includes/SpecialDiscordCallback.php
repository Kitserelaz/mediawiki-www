<?php
/** @license GPL-2.0-or-later */

namespace MediaWiki\Extension\DiscordAuth;

use MediaWiki\SpecialPage\SpecialPage;

/** Stable registered redirect URI; resumes the original AuthManager flow. */
class SpecialDiscordCallback extends SpecialPage {
	public function __construct( private DiscordClient $client, private AuthorizationStore $authorizations ) {
		parent::__construct( 'DiscordCallback' );
	}

	public function isListed() {
		return false;
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$out = $this->getOutput();
		$out->enableClientCache( false );
		$out->addJsConfigVars( 'wgReferrerPolicy', 'no-referrer' );
		$this->getRequest()->response()->header( 'Referrer-Policy: no-referrer' );
		$request = $this->getRequest();
		$state = $request->getText( 'state' );
		$flow = $this->client->isEnabled()
			? $this->authorizations->consume( $request, $state, $this->client->callbackUrl() ) : null;
		if ( !$flow ) {
			$out->addWikiMsg( 'discordauth-expired' );
			return;
		}
		// Commit consumption before the external request, even if it subsequently fails.
		\MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancerFactory()
			->commitPrimaryChanges( __METHOD__ );
		if ( $request->getText( 'error' ) !== '' ) {
			$out->addWikiMsg( 'discordauth-cancelled' );
			return;
		}
		try {
			$data = $this->client->identify( $request->getText( 'code' ) );
		} catch ( \Exception ) {
			$out->addWikiMsg( 'discordauth-failed' );
			return;
		}
		$identity = new DiscordIdentityRequest( $data['id'], $data['label'], time(),
			$data['email'], $data['emailVerified'] );
		// The fixed callback can run on a different wiki. Carry only a single-use,
		// browser-bound ticket there, never an identity, code, or MediaWiki session ID.
		$grant = $this->authorizations->grant( $request, $flow, $identity );
		$params = $flow['freshLogin'] ? [ 'discordResume' => $grant ]
			: [ 'code' => '', 'state' => $grant, 'error' => '' ];
		$out->redirect( wfAppendQuery( $flow['returnUrl'], $params ) );
	}
}
