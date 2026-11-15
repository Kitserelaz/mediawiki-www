<?php

namespace WikiOasis\WikiOasisMagic;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\CreateWikiValidator;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\StringDef;

class ApiSubdomain extends ApiBase {

	public function __construct(
		ApiMain $main,
		string $action,
		private readonly CreateWikiValidator $validator,
		private readonly CreateWikiDatabaseUtils $databaseUtils,
	) {
		parent::__construct( $main, $action );
	}

	public function execute(): void {
		if ( $this->getUser()->isRegistered() ) {
			$this->checkUserRightsAny( 'createnewwiki' );
		}
		if ( !$this->databaseUtils->isCurrentWikiCentral() ) {
			$this->dieWithError( 'createwiki-wikinotcentralwiki', 'notcentralwiki' );
		}
		$this->getMain()->setCacheMode( 'private' );
		$params = $this->extractRequestParams();
		if ( $params['subdomain'] !== '' ) {
			$subdomain = $params['subdomain'];
			$check = $this->validator->validateSubdomain( $subdomain, [] );
			$available = $check === true && strlen( $subdomain ) <= 60
				&& preg_match( '/^[a-z0-9]+$/D', $subdomain );
			$result = [
				'subdomain' => $subdomain,
				'available' => (bool)$available,
				'message' => $available ? '' : ( $check === true
					? $this->msg( 'createnewwiki-subdomain-invalid' )->text() : $check->text() ),
			];
		} else {
			$name = trim( $params['name'] );
			$lang = trim( $params['lang'] );
			$suggester = new SubdomainSuggester( $this->validator );
			$subdomain = $name !== '' ? $suggester->suggest( $name, $lang !== '' ? $lang : null ) : null;
			$result = [
				'subdomain' => $subdomain ?? '',
				'available' => $subdomain !== null,
				'message' => $subdomain === null ? $this->msg( 'createnewwiki-subdomain-unavailable' )->text() : '',
			];
		}
		$this->getResult()->addValue( null, $this->getModuleName(), $result );
	}

	protected function getAllowedParams(): array {
		return [
			'name' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
				StringDef::PARAM_MAX_BYTES => 1024,
			],
			'subdomain' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
				StringDef::PARAM_MAX_BYTES => 255,
			],
			'lang' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
				StringDef::PARAM_MAX_BYTES => 32,
			],
		];
	}
}
