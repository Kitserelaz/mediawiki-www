<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\ReportIncident\Services\ReportIncidentController;
use MediaWiki\Extension\ReportIncident\Services\ReportIncidentMailer;
use MediaWiki\Extension\ReportIncident\Services\ReportIncidentManager;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

// PHP unit does not understand code coverage for this file
// as the @covers annotation cannot cover a specific file
// See ReportIncidentServiceWiringTest.php for relevant tests.
// @codeCoverageIgnoreStart

return [
	'ReportIncidentManager' => static function (
		MediaWikiServices $services
	): ReportIncidentManager {
		return new ReportIncidentManager(
			$services->getService( 'ReportIncidentMailer' )
		);
	},
	'ReportIncidentMailer' => static function (
		MediaWikiServices $services
	): ReportIncidentMailer {
		return new ReportIncidentMailer(
			new ServiceOptions( ReportIncidentMailer::CONSTRUCTOR_OPTIONS, $services->getMainConfig() ),
			$services->getUrlUtils(),
			$services->getTitleFactory(),
			$services->getMessageFormatterFactory()->getTextFormatter(
				$services->getContentLanguage()->getCode()
			),
			$services->getEmailer(),
			LoggerFactory::getInstance( 'ReportIncident' )
		);
	},
	'ReportIncidentController' => static function (
		MediaWikiServices $services
	): ReportIncidentController {
		return new ReportIncidentController( $services->getMainConfig() );
	}
];
// @codeCoverageIgnoreEnd
