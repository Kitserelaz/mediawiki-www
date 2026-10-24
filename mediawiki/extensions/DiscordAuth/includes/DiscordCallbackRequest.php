<?php
/** @license GPL-2.0-or-later */

namespace MediaWiki\Extension\DiscordAuth;

use MediaWiki\Auth\AuthenticationRequest;

class DiscordCallbackRequest extends AuthenticationRequest {
	public $code = '';
	public $state = '';
	public $error = '';

	public function getFieldInfo() {
		$fields = [];
		foreach ( [ 'code', 'state', 'error' ] as $name ) {
			$fields[$name] = [
				'type' => 'string', 'optional' => true, 'sensitive' => true,
				'label' => wfMessage( 'discordauth-callback-field' ),
				'help' => wfMessage( 'discordauth-callback-field' ),
			];
		}
		return $fields;
	}
}
