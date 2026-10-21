<?php

namespace MediaWiki\Extension\EventStreamConfig;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiResult;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Enables requesting allowed MediaWiki configs via the API.
 * Usage:
 *
 * Get stream config settings
 *   GET /w/api.php?format=json&action=streamconfigs
 *
 * Get stream config settings for specified streams
 *   GET /w/api.php?format=json&action=streamconfigs&streams=my-stream1|my-stream2
 */
class ApiStreamConfigs extends ApiBase {
	// 10 minutes
	private const CACHE_MAX_AGE = 600;

	/**
	 * API query param used to specify target streams to get from Config
	 */
	private const API_PARAM_STREAMS = 'streams';

	/**
	 * API query param used to restrict stream config entry results to
	 * those that have settings that match these constraints.
	 * This is expected to be given as a multi array of key=val pairs.  E.g.
	 *   constraints=event_service_name=eventgate-main|other_setting=other_value
	 * This would be parsed into
	 * @code
	 *   [
	 *       'event_service_name' => 'eventgate-main',
	 *       'other_setting' => 'other_value'
	 *   ]
	 * @endcode
	 *
	 * And be used to filter for stream config entries that have these settings.
	 */
	private const API_PARAM_CONSTRAINTS = 'constraints';

	/**
	 * @deprecated since 1.41
	 */
	private const API_PARAM_ALL_SETTINGS = 'all_settings';

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->getMain()->setCacheMode( 'public' );
		$this->getMain()->setCacheMaxAge( self::CACHE_MAX_AGE );

		$targetStreams = $this->getParameter( self::API_PARAM_STREAMS );
		$constraints = $this->getParameter( self::API_PARAM_CONSTRAINTS );

		$settingsConstraints = $constraints ? self::multiParamToAssocArray( $constraints ) : null;

		$streamConfigs = MediaWikiServices::getInstance()->getService(
			'EventStreamConfig.StreamConfigs'
		);

		$result = $streamConfigs->get(
			$targetStreams,
			$settingsConstraints
		);

		// Ensure that empty stream configs are serialized as objects ({}) and not as lists ([]).
		//
		// See https://phabricator.wikimedia.org/T259917 and
		// https://phabricator.wikimedia.org/T323032 for additional context.
		foreach ( $result as &$value ) {
			ApiResult::setArrayType( $value, 'assoc' );
		}

		ApiResult::setArrayType( $result, 'assoc' );

		$this->getResult()->addValue( null, "streams", $result );
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams() {
		return [
			self::API_PARAM_STREAMS => [
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_ISMULTI_LIMIT1 => 250,
			],
			self::API_PARAM_CONSTRAINTS => [
				ParamValidator::PARAM_ISMULTI => true,
			],
			self::API_PARAM_ALL_SETTINGS => [
				ParamValidator::PARAM_DEPRECATED => true,
				ParamValidator::PARAM_TYPE => 'boolean',
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages() {
		return [
			'action=streamconfigs&' . self::API_PARAM_STREAMS .
			'=mediawiki.page-view|mediawiki.button-click' =>
					'apihelp-streamconfigs-example-1',

			'action=streamconfigs&' . self::API_PARAM_STREAMS .
			'=mediawiki.button-click' =>
					'apihelp-streamconfigs-example-2',

			'action=streamconfigs&' . self::API_PARAM_STREAMS .
			'=mediawiki.button-click' .
			'&constraints=event_service_name=eventgate-main' =>
					'apihelp-streamconfigs-example-3',
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Extension:EventStreamConfig';
	}

	/**
	 * @inheritDoc
	 */
	public function getCustomPrinter() {
		return new ApiFormatJsonVersion2( $this->getMain(), 'json' );
	}

	/**
	 * Parses a MULTI PARAM value into an assoc array.
	 *
	 * For example, the query string parameter "my_param=key1=val1|key2[key3]=val2" will be parsed into the following:
	 *
	 * ```
	 * [
	 *     'key1' => 'val1',
	 *     'key2' => [
	 *         'key3' => 'val2',
	 *     ],
	 * ]
	 * ```
	 *
	 * @param array $multiParamArray List of key=val string pairs
	 * @return array
	 */
	private static function multiParamToAssocArray( array $multiParamArray ) {
		return array_reduce(
			$multiParamArray,
			static function ( $carry, $elementString ) {
				return array_merge_recursive( $carry, wfCgiToArray( $elementString ) );
			},
			[]
		);
	}
}
