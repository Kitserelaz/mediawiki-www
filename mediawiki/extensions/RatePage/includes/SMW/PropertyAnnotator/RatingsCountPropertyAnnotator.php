<?php

namespace RatePage\SMW\PropertyAnnotator;

use RatePage\SMW\Hooks;
use SMW\DIProperty;
use SMW\SemanticData;
use SMWDINumber;

class RatingsCountPropertyAnnotator extends PropertyAnnotator {

	/** @var int */
	private $ratingsCount;

	/**
	 * RatingsCountPropertyAnnotator constructor.
	 *
	 * @param int $ratingsCount
	 */
	public function __construct( int $ratingsCount ) {
		$this->ratingsCount = $ratingsCount;
	}

	public function addAnnotation( SemanticData $semanticData ) {
		$this->doAddAnnotation(
			$semanticData,
			new DIProperty( Hooks::PROP_RATING_COUNT ),
			new SMWDINumber( $this->ratingsCount )
		);
	}
}