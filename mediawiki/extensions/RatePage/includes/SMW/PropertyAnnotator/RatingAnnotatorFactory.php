<?php

namespace RatePage\SMW\PropertyAnnotator;

use RatePage\Rating;
use Title;

class RatingAnnotatorFactory {

	/**
	 * @param Title $title
	 *
	 * @return PropertyAnnotator[]
	 */
	public static function newGeneralPropAnnotators( Title $title ) : array {
		$ratings = Rating::getPageRating( $title );
		return self::buildPropAnnotatorsFromData( $ratings );
	}

	/**
	 * @param Title $title
	 *
	 * @return PropertyAnnotator[]
	 */
	public static function newContestPropAnnotators( Title $title ) : array {
		$annotators = [];
		$data = Rating::getPageContestRatings( $title, true );

		foreach ( $data as $contestId => $contestData ) {
			$annotators[] = new ContestPropertyAnnotator(
				$title,
				$contestId,
				self::buildPropAnnotatorsFromData( $contestData )
			);
		}

		return $annotators;
	}

	/**
	 * @param array $data
	 *
	 * @return PropertyAnnotator[]
	 */
	private static function buildPropAnnotatorsFromData( array $data ) : array {
		$total = 0;
		$num = 0;

		foreach ( $data as $answer => $count ) {
			$total += $answer * $count;
			$num += $count;
		}

		$annotators = [ new RatingsCountPropertyAnnotator( $num ) ];
		if ( $num > 0 ) {
			$annotators[] = new AverageRatingPropertyAnnotator( $total / $num );
		}

		return $annotators;
	}
}