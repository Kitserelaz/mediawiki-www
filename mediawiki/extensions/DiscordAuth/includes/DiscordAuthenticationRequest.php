<?php
/** @license GPL-2.0-or-later */

namespace MediaWiki\Extension\DiscordAuth;

use MediaWiki\Auth\ButtonAuthenticationRequest;

class DiscordAuthenticationRequest extends ButtonAuthenticationRequest {
	public function __construct() {
		parent::__construct( 'discordLogin', wfMessage( 'discordauth-login' ),
			wfMessage( 'discordauth-login-help' ), true );
	}
}
