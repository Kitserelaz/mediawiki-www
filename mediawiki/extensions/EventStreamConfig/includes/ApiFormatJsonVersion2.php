<?php
namespace MediaWiki\Extension\EventStreamConfig;

use MediaWiki\Api\ApiFormatJson;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Overrides ApiFormatJson::getAllowedParams to disable formatversion=1
 * and make formatversion=2 the default.
 * This is needed to e.g. make sure output booleans are serialized properly,
 * instead of as true => "" and false removed.
 */
class ApiFormatJsonVersion2 extends ApiFormatJson {

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams() {
		$allowedParams = parent::getAllowedParams();
		$allowedParams['formatversion'][ParamValidator::PARAM_TYPE] = [ '2', 'latest' ];
		$allowedParams['formatversion'][ParamValidator::PARAM_DEFAULT] = '2';
		return $allowedParams;
	}
}
