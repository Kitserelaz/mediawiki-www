<?php

namespace WikiOasis\WikiOasisMagic;

use MediaWiki\Message\Message;
use Miraheze\CreateWiki\Services\CreateWikiValidator;

final class SubdomainValidator {

	public function __construct(
		private readonly CreateWikiValidator $validator,
		private readonly string $domain,
	) {
	}

	public function validate( ?string $subdomain ): bool|Message {
		$result = $this->validator->validateSubdomain( $subdomain, [] );
		if ( $result !== true || $subdomain === null ) {
			return $result;
		}

		$host = $this->validator->getValidSubdomain( $subdomain ) . '.' . $this->domain;
		if ( function_exists( 'farmWikiHostId' ) && \farmWikiHostId( $host ) !== null ) {
			return Message::newFromKey( 'createwiki-error-subdomaintaken' );
		}

		return true;
	}
}
