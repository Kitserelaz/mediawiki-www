<?php

namespace MediaWiki\Extension\EventStreamConfig;

use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiResult;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Api\ApiTestCase;

/**
 * @covers \MediaWiki\Extension\EventStreamConfig\ApiStreamConfigs
 * @group EventStreamConfig
 * @group medium
 */
class ApiStreamConfigsTest extends ApiTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'EventStreams', [
			'test' => [
				StreamConfig::STREAM_SETTING => 'test',
				StreamConfig::TOPICS_SETTING => [ 'topic' ],
				StreamConfig::TOPIC_PREFIXES_SETTING => [ 'prefix.' ],
				'other-setting' => [],
				'true-setting' => true,
				'false-setting' => false,
			],
			'test2' => [
				StreamConfig::STREAM_SETTING => 'test2',
				'foo' => [
					'bar' => 'baz',
					'qux' => [
						'qux',
					],
				],
			],
		] );
	}

	public function testResultArrayFormat(): void {
		$result = $this->doRequest( [ 'action' => 'streamconfigs' ] );
		$this->assertSame( 'assoc', $result['streams'][ApiResult::META_TYPE] );

		$testConfig = $result['streams']['test'];
		$this->assertSame( 'assoc', $testConfig[ApiResult::META_TYPE] );
		$this->assertArrayNotHasKey( ApiResult::META_TYPE, $testConfig[StreamConfig::TOPICS_SETTING] );
		$this->assertArrayNotHasKey( ApiResult::META_TYPE, $testConfig[StreamConfig::TOPIC_PREFIXES_SETTING] );
		$this->assertArrayNotHasKey( ApiResult::META_TYPE, $testConfig['other-setting'] );

		// Make sure true and false are serialized correctly in output using formatversion=2
		$this->assertSame( true, $result['streams']['test']['true-setting'] );
		$this->assertSame( false, $result['streams']['test']['false-setting'] );
	}

	/**
	 * Custom API request function needed because ApiTestCase::doApiRequest unconditionally
	 * strips result metadata, which we need to test.
	 * @param array $params
	 * @return mixed
	 */
	private function doRequest( array $params ) {
		$request = new FauxRequest( $params, true );
		$this->setRequest( $request );
		$context = $this->apiContext->newTestContext( $request );
		$module = new ApiMain( $context );
		$module->execute();
		return $module->getResult()->getResultData();
	}

	public function testConstraints(): void {
		$result = $this->doApiRequest( [
			'action' => 'streamconfigs',
			'constraints' => 'foo[bar]=baz',
		] );
		$streams = $result[0]['streams'];

		$this->assertCount( 1, array_keys( $streams ) );
		$this->assertArrayHasKey( 'test2', $streams );

		// ---

		$result = $this->doApiRequest( [
			'action' => 'streamconfigs',
			'constraints' => 'foo=true',
		] );
		$streams = $result[0]['streams'];

		$this->assertCount( 0, $streams );
	}
}
