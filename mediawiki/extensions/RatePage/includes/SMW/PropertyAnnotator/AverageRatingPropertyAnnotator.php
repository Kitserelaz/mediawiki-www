<?php

namespace RatePage\SMW\PropertyAnnotator;

use RatePage\SMW\Hooks;
use SMW\DIProperty;
use SMW\SemanticData;
use SMWDINumber;

class AverageRatingPropertyAnnotator extends PropertyAnnotator {

	/** @var float */
	private $avgRating;

	/**
	 * AverageRatingPropertyAnnotator constructor.
	 *
	 * @param float $avgRating
	 */
	public function __construct( float $avgRating ) {
		$this->avgRating = $avgRating;
	}

	public function addAnnotation( SemanticData $semanticData ) {
		$this->doAddAnnotation(
			$semanticData,
			new DIProperty( Hooks::PROP_RATING_AVERAGE ),
			new SMWDINumber( $this->avgRating )
		);
	}
}