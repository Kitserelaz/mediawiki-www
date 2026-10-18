<?php

namespace MediaWiki\Extension\CountDownClock;

use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Html;

class Hooks {

	/**
	 * Register the parser functions.
	 *
	 * @param Parser $parser
	 */
	public static function onParserSetup( $parser ) {
		// Set {{#countDownClock:}}
		$parser->setFunctionHook( 'countDownClock', [ __CLASS__, 'renderTime' ] );
	}

	/**
	 * Render the output of {{#countDownClock:}}
	 *
	 * @param Parser $parser
	 * @param string $param1
	 * @return string HTML string
	 */
	public static function renderTime( $parser, $param1 = '' ) {
		$endTimeForcountDownClock = $param1;

		$formats = [
			'Y-m-d H:i:s',
			'Y-m-d H:i:s e',
			'Y-m-d H:i:s T',
			'Y-m-d H:i:s O',
			'Y-m-d H:i:s P',
			DateTimeInterface::ATOM
		];

		foreach ( $formats as $format ) {
			$d = DateTime::createFromFormat( $format, $endTimeForcountDownClock, new DateTimeZone( 'UTC' ) );
			$isValidTime = $d && $d->format( $format ) == $endTimeForcountDownClock;
			if ( $isValidTime ) {
				break;
			}
		}

		if ( $isValidTime === false ) {

			$output = Html::element(
				'span',
				[ 'class' => 'countDownClockError', 'style' => 'color:red;' ],
				wfMessage( 'countDownClock-invalidtime' )->text()
			);

			return [ $output, 'isHTML' => true ];
		}

		// Add Javascript Module
		$parser->getOutput()->addModules( [ 'ext.countDownClock' ] );

		// Create the time element
		$output = Html::element(
			'span',
			[
				'class' => 'countDownClock',
				// ATOM is equivalent to the ISO 8601 like format used in JavaScript's Date object
				'data' => $d->format( DateTimeInterface::ATOM )
			],
			// Non-breaking space, reduces likelyhood of page jumping when element's text is filled in
			"\xc2\xa0"
		);

		return [ $output, 'isHTML' => true ];
	}

}
