<?php
/** @license GPL-2.0-or-later */

namespace MediaWiki\Extension\DiscordAuth;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Language\RawMessage;

/** Verified identity, held server-side during the handoff and account creation. */
class DiscordIdentityRequest extends AuthenticationRequest {
	public string $email = '';
	public bool $emailVerified = false;

	public function __construct(
		public string $discordId, public string $label, public int $verifiedAt,
		string $email = '', bool $emailVerified = false
	) {
		$this->email = $email;
		$this->emailVerified = $emailVerified;
	}

	public function getFieldInfo() {
		return [];
	}

	public function describeCredentials() {
		return [ 'provider' => wfMessage( 'discordauth-provider' ),
			'account' => new RawMessage( '$1', [ $this->label ] ) ];
	}

	public function isFresh(): bool {
		return $this->verifiedAt <= time() && $this->verifiedAt >= time() - OAuthFlow::LIFETIME;
	}
}
