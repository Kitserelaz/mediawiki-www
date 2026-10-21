<?php

namespace RatePage;
use LogFormatter;
use Message;

/**
 * Some of this code is based on the AbuseFilter extension.
 * You can find the extension's code and list of authors here:
 * https://github.com/wikimedia/mediawiki-extensions-AbuseFilter
 *
 * AbuseFilter's code is licensed under GPLv2
 */
class ContestLogFormatter extends LogFormatter {

	/**
	 * @return string
	 */
	protected function getMessageKey() : string {
		$subtype = $this->entry->getSubtype();

		return "logentry-ratepage-contest-$subtype";
	}

	/**
	 * @return array
	 */
	protected function extractParameters() : array {
		$parameters = $this->entry->getParameters();
		if ( $this->entry->isLegacy() ) {
			list( $contestId ) = $parameters;
		} else {
			$contestId = $parameters['id'];
		}

		$params = [];
		$params[3] = Message::rawParam(
			$this->makePageLink(
				$this->entry->getTarget(),
				[],
				$this->msg( 'ratePage-log-contest-formatter', $contestId )->escaped()
			)
		);

		return $params;
	}
}
