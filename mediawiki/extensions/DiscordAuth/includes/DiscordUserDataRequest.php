<?php
/** @license GPL-2.0-or-later */

namespace MediaWiki\Extension\DiscordAuth;

use MediaWiki\Auth\UserDataAuthenticationRequest;

/** Email comes exclusively from the verified server-side OAuth response, never a form. */
class DiscordUserDataRequest extends UserDataAuthenticationRequest {
	public function __construct( string $email ) {
		$this->email = $email;
	}

	public function getFieldInfo() {
		return [];
	}

	public function loadFromSubmission( array $data ) {
		return true;
	}
}
