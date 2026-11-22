<?php

namespace WikiOasis\WikiOasisMagic;

use MediaWiki\Message\Message;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use Miraheze\CreateWiki\Services\CreateWikiValidator;

final class SubdomainValidator {

	public function __construct(
		private readonly CreateWikiValidator $validator,
		private readonly string $domain,
		private readonly array $disallowedSubdomains,
	) {
	}

	public function allowsHyphens(): bool {
		return function_exists( 'farmNumericIdsEnabled' ) && \farmNumericIdsEnabled();
	}

	public function validate( ?string $subdomain ): bool|Message {
		$label = $this->validator->getValidSubdomain( $subdomain ?? '' );
		if ( ctype_digit( $label ) ) {
			return Message::newFromKey( 'createnewwiki-subdomain-numeric' );
		}
		if ( strlen( $label ) > 60 ) {
			return Message::newFromKey( $this->allowsHyphens()
				? 'createnewwiki-subdomain-invalid-hyphen'
				: 'createnewwiki-subdomain-invalid' );
		}

		if ( $this->allowsHyphens() && str_contains( $label, '-' ) ) {
			if ( !preg_match( '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/D', $label ) ) {
				return Message::newFromKey( 'createnewwiki-subdomain-invalid-hyphen' );
			}
			$disallowed = CreateWikiRegexConstraint::regexFromArray(
				$this->disallowedSubdomains, '/^(', ')+$/', ConfigNames::DisallowedSubdomains
			);
			if ( $disallowed !== '' && preg_match( $disallowed, $label ) ) {
				return Message::newFromKey( 'createwiki-error-disallowed' );
			}
		} else {
			$result = $this->validator->validateSubdomain( $subdomain, [] );
			if ( $result !== true ) {
				return $result;
			}
		}

		$host = $label . '.' . $this->domain;
		if ( function_exists( 'farmWikiHostId' ) && \farmWikiHostId( $host ) !== null ) {
			return Message::newFromKey( 'createwiki-error-subdomaintaken' );
		}

		return true;
	}
}
