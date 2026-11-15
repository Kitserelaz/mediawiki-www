<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\EventStreamConfig\StreamConfigs;
use MediaWiki\Extension\EventStreamConfig\StreamConfigsFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'EventStreamConfig.StreamConfigs' => static function ( MediaWikiServices $services ): StreamConfigs {
		$options = new ServiceOptions(
			StreamConfigsFactory::CONSTRUCTOR_OPTIONS,
			$services->getMainConfig()
		);
		$logger = LoggerFactory::getInstance( 'EventStreamConfig' );

		$factory = new StreamConfigsFactory(
			$options,
			$services->getHookContainer(),
			$logger
		);

		return $factory->getInstance();
	}
];
