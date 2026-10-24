<?php
/** @license GPL-2.0-or-later */

namespace MediaWiki\Extension\DiscordAuth;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Language\RawMessage;

class RemoveDiscordRequest extends AuthenticationRequest {
	public function __construct( public string $discordId ) {
	}

	public function getFieldInfo() {
		return [];
	}

	public function describeCredentials() {
		return [ 'provider' => wfMessage( 'discordauth-provider' ),
			'account' => new RawMessage( '$1', [ $this->discordId ] ) ];
	}
}
