<?php

namespace MediaWiki\Extension\EventStreamConfig;

use MediaWiki\Extension\EventStreamConfig\Hooks\GetStreamConfigsHook;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\EventStreamConfig\StreamConfigs
 * @covers \MediaWiki\Extension\EventStreamConfig\StreamConfigsFactory
 * @group EventStreamConfig
 */
class StreamConfigsIntegrationTest
	extends MediaWikiIntegrationTestCase
	implements GetStreamConfigsHook
{

	private const STREAM_CONFIGS_FIXTURE = [
		'nonya' => [
			'stream' => 'nonya',
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
				'unit' => 'session',
			],
			'destination_event_service' => 'eventgate-analytics',
		],
		'test.event' => [
			'stream' => 'test.event',
			'schema_title' => 'test/event',
			'sample' => [
				'rate' => 1.0,
				'unit' => 'session',
			],
			'destination_event_service' => 'eventgate-main',
			'topic_prefixes' => [ 'eqiad.', 'codfw.' ],
		],
		'/^mediawiki\.job\..+/' => [
			'stream' => '/^mediawiki\.job\..+/',
			'schema_title' => 'mediawiki/job',
			'sample' => [
				'rate' => 0.8,
				'unit' => 'session',
			],
			'destination_event_service' => 'eventgate-main',
		],
	];

	public function onGetStreamConfigs( array &$streamConfigs ): void {
		$streamConfigs[ 'nonya' ] = [
			'stream' => 'foo',
			'schema_title' => 'bar',
			'sample' => [
				'rate' => 0.5,
				'unit' => 'baz',
			],
			'destination_event_service' => 'qux',
		];
		$streamConfigs[ 'test.get_stream_configs' ] = [
			'stream' => 'test.get_stream_configs',
			'schema_title' => 'test/get_stream_configs',
			'sample' => [
				'rate' => 0.75,
				'unit' => 'session',
			],
			'destination_event_service' => 'eventgate-external',
		];
	}

	/**
	 * @covers \MediaWiki\Extension\EventStreamConfig\StreamConfigs::__construct()
	 */
	public function testMediaWikiServiceIntegration() {
		$this->overrideConfigValues( [
			'EventStreams' => self::STREAM_CONFIGS_FIXTURE,
			'EventStreamsDefaultSettings' => [
				'topic_prefixes' => [
					'eqiad.'
				],
			],
		] );

		$services = $this->getServiceContainer();

		$hookContainer = $services->getHookContainer();
		$hookContainer->register( 'GetStreamConfigs', [ $this, 'onGetStreamConfigs' ] );

		$streamConfigs = $services->getService( 'EventStreamConfig.StreamConfigs' );

		$expected = [
			'nonya' => [
				'stream' => 'nonya',
				'schema_title' => 'mediawiki/nonya',
				'sample' => [
					'rate' => 0.5,
					'unit' => 'session',
				],
				'destination_event_service' => 'eventgate-analytics',
				'topic_prefixes' => [
					'eqiad.',
				],
				'topics' => [
					'eqiad.nonya',
				],
			],
			'test.get_stream_configs' => [
				'stream' => 'test.get_stream_configs',
				'schema_title' => 'test/get_stream_configs',
				'sample' => [
					'rate' => 0.75,
					'unit' => 'session',
				],
				'destination_event_service' => 'eventgate-external',
				'topic_prefixes' => [
					'eqiad.',
				],
				'topics' => [
					'eqiad.test.get_stream_configs',
				],
			],
		];
		$result = $streamConfigs->get( [ 'nonya', 'test.get_stream_configs' ] );
		$this->assertEquals(
			$expected,
			$result,
			'The "nonya" stream from $wgEventStreams and the "test.get_stream_configs" stream is present.'
		);
	}
}
