<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\EventStreamConfig\Hooks\HookRunner;
use MediaWiki\Extension\EventStreamConfig\StreamConfigs;
use MediaWiki\Extension\EventStreamConfig\StreamConfigsFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'EventStreamConfig.HookRunner' => static function ( MediaWikiServices $services ): HookRunner {
		return new HookRunner( $services->getHookContainer() );
	},
	'EventStreamConfig.StreamConfigs' => static function ( MediaWikiServices $services ): StreamConfigs {
		$options = new ServiceOptions(
			StreamConfigsFactory::CONSTRUCTOR_OPTIONS,
			$services->getMainConfig()
		);
		$logger = LoggerFactory::getInstance( 'EventStreamConfig' );

		$factory = new StreamConfigsFactory(
			$options,
			$services->get( 'EventStreamConfig.HookRunner' ),
			$logger
		);

		return $factory->getInstance();
	}
];
