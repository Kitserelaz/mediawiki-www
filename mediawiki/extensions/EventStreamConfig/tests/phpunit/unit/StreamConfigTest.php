<?php

namespace MediaWiki\Extension\EventStreamConfig;

use Generator;
use InvalidArgumentException;
use MediaWikiUnitTestCase;
use TypeError;

/**
 * @covers \MediaWiki\Extension\EventStreamConfig\StreamConfig
 * @group EventStreamConfig
 */
class StreamConfigTest extends MediaWikiUnitTestCase {

	/**
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::stream()
	 */
	public function testStream() {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
		];

		$streamConfig = new StreamConfig( 'nonya', $settings );
		$this->assertEquals( 'nonya', $streamConfig->stream() );
	}

	/**
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::toArray()
	 */
	public function testToArray() {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
		];

		$expected = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
			'stream' => 'nonya',
			'topics' => [ 'nonya' ],
		];

		$streamConfig = new StreamConfig( 'nonya', $settings );
		$this->assertEquals( $expected, $streamConfig->toArray() );
	}

	/**
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::toArray()
	 */
	public function testToArrayAllSettingsWithDefaults() {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
			'topics' => [ 'nonya' ],
		];

		$streamSetting = [
			'stream' => 'nonya'
		];

		$defaultSettings = [
			'is_active' => true
		];

		$expected = $settings + $streamSetting + $defaultSettings;

		$streamConfig = new StreamConfig( 'nonya', $settings, $defaultSettings );
		$this->assertEquals( $expected, $streamConfig->toArray() );
	}

	/**
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::toArray()
	 */
	public function testToArrayAllSettingsWithDeeplyMergedDefaults() {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'destination_event_service' => 'eventgate-analytics',
			'topics' => [ 'nonya' ],
			'consumers' => [
				'analytics_hadoop_ingestion' => [
					'job_name' => 'nonya'
				],
			],
		];

		$streamSetting = [
			'stream' => 'nonya'
		];

		$defaultSettings = [
			'consumers' => [
				'analytics_hadoop_ingestion' => [
					'job_name' => 'event_default',
					'enabled' => true,
				],
				'analytics_hive_ingestion' => [
					'enabled' => false,
				],
			],
		];

		$expected = [
			'stream' => 'nonya',
			'schema_title' => 'mediawiki/nonya',
			'destination_event_service' => 'eventgate-analytics',
			'topics' => [ 'nonya' ],
			'consumers' => [
				'analytics_hadoop_ingestion' => [
					'job_name' => 'nonya',
					'enabled' => true,
				],
				'analytics_hive_ingestion' => [
					'enabled' => false,
				],
			],
		];

		$streamConfig = new StreamConfig( 'nonya', $settings, $defaultSettings );
		$this->assertEquals( $expected, $streamConfig->toArray() );
	}

	/**
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::toArray()
	 */
	public function testToArrayWithTopicPrefixesAllSettings() {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
			'topic_prefixes' => [ 'eqiad.', 'codfw.' ],
		];

		$streamSetting = [
			'stream' => 'nonya'
		];

		$expected = $settings + $streamSetting;
		$expected['topics'] = [ 'eqiad.nonya', 'codfw.nonya' ];

		$streamConfig = new StreamConfig( 'nonya', $settings );
		$this->assertEquals( $expected, $streamConfig->toArray() );
	}

	/**
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::matches()
	 */
	public function testMatchesString() {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
		];

		$streamConfig = new StreamConfig( 'nonya', $settings );
		$this->assertTrue( $streamConfig->matches( 'nonya' ) );
	}

	/**
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::matches()
	 */
	public function testMatchesRegex() {
		$settings = [
			'schema_title' => 'mediawiki/job',
			'sample' => [
				'rate' => 0.8,
			],
			'destination_event_service' => 'eventgate-main',
		];

		$streamConfig = new StreamConfig( '/^mediawiki\.job\..+/', $settings );
		$this->assertTrue( (bool)$streamConfig->matches( 'mediawiki.job.workworkwork' ) );
	}

	/**
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::matches()
	 */
	public function testGivenRegexStreamDoesNotMatch() {
		$settings = [
			'schema_title' => 'mediawiki/job',
			'sample' => [
				'rate' => 0.8,
			],
			'destination_event_service' => 'eventgate-main',
		];

		$streamConfig = new StreamConfig( '/^mediawiki\.job\..+/', $settings );
		// Since the stream setting should be recognized as a regex, string equivalence
		// should not be used to match the incoming target stream name, and
		// preg_match( $regex, $regex ) will be false.
		$this->assertFalse( (bool)$streamConfig->matches( '/^mediawiki\.job\..+/' ) );
	}

	/**
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::__construct()
	 */
	public function testMissingStreamName() {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
		];
		$this->expectException( TypeError::class );
		new StreamConfig( null, $settings );
	}

	/**
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::__construct()
	 */
	public function testInvalidStreamNameRegex() {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
		];
		$this->expectException( InvalidArgumentException::class );
		new StreamConfig( '/nonya/BADREGEX', $settings );
	}

	/**
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::matchesSettings()
	 */
	public function testMatchesSettings() {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
		];

		$constraints = [
			'destination_event_service' => 'eventgate-analytics',
		];

		$streamConfig = new StreamConfig( 'nonya', $settings );
		$this->assertTrue( $streamConfig->matchesSettings( $constraints ) );
	}

	/**
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::matchesSettings()
	 */
	public function testMatchesSettingsBoolean() {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
			'canary_events_enabled' => true,
		];

		$constraints = [
			'canary_events_enabled' => true,
		];

		$streamConfig = new StreamConfig( 'nonya', $settings );
		$this->assertTrue( $streamConfig->matchesSettings( $constraints ) );
	}

	/**
	 * PHP's array_intersect_assoc casts true to "1" before comparing.
	 * So, if we want to compare with a string constraint value to a boolean setting,
	 * we must pre-cast the constraint value to "1".
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::matchesSettings()
	 */
	public function testMatchesSettingsBooleanStringTrue() {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
			'canary_events_enabled' => true,
		];

		$constraints = [
			'canary_events_enabled' => "1",
		];

		$streamConfig = new StreamConfig( 'nonya', $settings );
		$this->assertTrue( $streamConfig->matchesSettings( $constraints ) );
	}

	/**
	 * PHP's array_intersect_assoc casts false to "" before comparing.
	 * So, if we want to compare with a string constraint value to a boolean setting,
	 * we must pre-cast the constraint value to "".
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::matchesSettings()
	 */
	public function testMatchesSettingsBooleanStringFalse() {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
			'canary_events_enabled' => false,
		];

		$constraints = [
			'canary_events_enabled' => "",
		];

		$streamConfig = new StreamConfig( 'nonya', $settings );
		$this->assertTrue( $streamConfig->matchesSettings( $constraints ) );
	}

	/**
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::matchesSettings()
	 */
	public function testNotMatchesSettings() {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
		];

		$constraints = [
			'destination_event_service' => 'eventgate-main',
		];

		$streamConfig = new StreamConfig( 'nonya', $settings );
		$this->assertFalse( $streamConfig->matchesSettings( $constraints ) );
	}

	public static function provideMatchesSettingsRecursive(): Generator {
		yield [
			'constraints' => [
				'producers' => [
					'bar_producer' => true,
				],
			],
			'expected' => true,
		];

		yield [
			'constraints' => [
				'unrecognized' => true,
			],
			'expected' => false,
		];

		yield [
			'constraints' => [
				'producers' => [
					'foo_producer' => [
						'foo_setting' => false,
					],
					'bar_producer' => true,
				],
			],
			'expected' => true,
		];

		// Like array_intersect_assoc, StreamConfig::isPartialMatch should cast the values to
		// strings before comparing them.
		//
		// See also the note for ::testMatchesSettingsBooleanStringFalse.
		yield [
			'constraints' => [
				'producers' => [
					'foo_producer' => [
						'foo_setting' => '',
					],
					'bar_producer' => '1',
				],
			],
			'expected' => true,
		];

		yield [
			'constraints' => [
				'producers' => [
					'baz_producer' => 0.1,
				],
			],
			'expected' => true,
		];

		yield [
			'constraints' => [
				'producers' => [
					'qux_producer' => [
						'world',
					],
				],
			],
			'expected' => true,
		];

		yield [
			'constraints' => [
				'producers' => [
					'qux_producer' => [
						'hello',
						'world',
						'quux',
					],
				],
			],
			'expected' => false,
		];

		yield [
			'constraints' => [
				'producers' => [
					'foo_producer' => [
						'hello',
						'world',
					],
				],
			],
			'expected' => false,
		];

		yield [
			'constraints' => [
				'foo_producer' => false,
				'qux_producer' => 'Hello, World!',
			],
			'expected' => false,
		];
	}

	/**
	 * @dataProvider provideMatchesSettingsRecursive
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::matchesSettings()
	 */
	public function testMatchesSettingsRecursive( array $constraints, bool $expected ) {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
			'producers' => [
				'foo_producer' => [
					'foo_setting' => false,
				],
				'bar_producer' => true,
				'baz_producer' => 0.1,
				'qux_producer' => [
					'hello',
					'world',
				],
			],
		];

		$streamConfig = new StreamConfig( 'nonya', $settings );
		$this->assertEquals( $expected, $streamConfig->matchesSettings( $constraints ) );
	}

	/**
	 * @covers MediaWiki\Extension\EventStreamConfig\StreamConfig::matchesSettings()
	 */
	public function testMatchesSettingsStreamRegex() {
		$settings = [
			'schema_title' => 'mediawiki/job',
			'destination_event_service' => 'eventgate-main',
		];

		$constraints = [
			'stream' => 'mediawiki.job.workworkwork',
			'destination_event_service' => 'eventgate-main',
		];

		$streamConfig = new StreamConfig( '/^mediawiki\.job\..+/', $settings );
		$this->assertTrue( $streamConfig->matchesSettings( $constraints ) );
	}

	public function testTopicsWithExplicitTopicsSetting() {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
			'topics' => [ 'eqiad.nonya', 'codfw.nonya' ],
		];

		$streamConfig = new StreamConfig( 'nonya', $settings );
		$this->assertEquals( $streamConfig->topics(), $settings['topics'] );
	}

	public function testTopicsWithoutTopicPrefixes() {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
		];

		$streamConfig = new StreamConfig( 'nonya', $settings );
		$this->assertEquals( [ 'nonya' ], $streamConfig->topics() );
	}

	public function testTopicsWithTopicPrefixes() {
		$settings = [
			'schema_title' => 'mediawiki/nonya',
			'sample' => [
				'rate' => 0.5,
			],
			'destination_event_service' => 'eventgate-analytics',
			'topic_prefixes' => [ 'eqiad.', 'codfw.' ],
		];

		$streamConfig = new StreamConfig( 'nonya', $settings );
		$this->assertEquals( [ 'eqiad.nonya', 'codfw.nonya' ], $streamConfig->topics() );
	}

	public function testTopicsStreamRegexSettingWithoutTopicPrefixes() {
		$settings = [
			'schema_title' => 'mediawiki/job',
			'destination_event_service' => 'eventgate-main',
		];

		$streamConfig = new StreamConfig( '/^mediawiki\.job\..+/', $settings );
		$this->assertEquals( [ '/^mediawiki\.job\..+/' ], $streamConfig->topics() );
	}

	public function testTopicsStreamRegexSettingWithTopicPrefixes() {
		$settings = [
			'schema_title' => 'mediawiki/job',
			'destination_event_service' => 'eventgate-main',
			'topic_prefixes' => [ 'eqiad.', 'codfw.' ],
		];

		$streamConfig = new StreamConfig( '/^mediawiki\.job\..+/', $settings );
		$this->assertEquals( [ '/^(eqiad\.|codfw\.)mediawiki\.job\..+/' ], $streamConfig->topics() );
	}

	public function testTopicsTargetStreamNameWithoutTopicPrefixes() {
		$settings = [
			'schema_title' => 'mediawiki/job',
			'destination_event_service' => 'eventgate-main',
		];

		$streamConfig = new StreamConfig( '/^mediawiki\.job\..+/', $settings );
		$this->assertEquals(
			[ 'mediawiki.job.workworkwork' ],
			$streamConfig->topics( 'mediawiki.job.workworkwork' )
		);
	}

	public function testTopicsTargetStreamNameWithTopicPrefixes() {
		$settings = [
			'schema_title' => 'mediawiki/job',
			'destination_event_service' => 'eventgate-main',
			'topic_prefixes' => [ 'eqiad.', 'codfw.' ],
		];

		$streamConfig = new StreamConfig( '/^mediawiki\.job\..+/', $settings );
		$this->assertEquals(
			[ 'eqiad.mediawiki.job.workworkwork', 'codfw.mediawiki.job.workworkwork' ],
			$streamConfig->topics( 'mediawiki.job.workworkwork' )
		);
	}

	public function testTopicsTargetStreamRegexWithoutTopicPrefixes() {
		$settings = [
			'schema_title' => 'mediawiki/job',
			'destination_event_service' => 'eventgate-main',
		];

		$streamConfig = new StreamConfig( '/^mediawiki\.job\..+/', $settings );
		$this->assertEquals(
			[ '/^mediawiki\.job\..+/' ],
			$streamConfig->topics( '/^mediawiki\.job\..+/' )
		);
	}

	public function testTopicsTargetStreamRegexWithTopicPrefixes() {
		$settings = [
			'schema_title' => 'mediawiki/job',
			'destination_event_service' => 'eventgate-main',
			'topic_prefixes' => [ 'eqiad.', 'codfw.' ],
		];

		$streamConfig = new StreamConfig( '/^mediawiki\.job\..+/', $settings );
		$this->assertEquals(
			[ '/^(eqiad\.|codfw\.)mediawiki\.job\..+/' ],
			$streamConfig->topics( '/^mediawiki\.job\..+/' )
		);
	}

}
