<?php

namespace RatePage\SMW;

use MediaWiki\MediaWikiServices;
use RatePage\Rating;
use RatePage\SMW\PropertyAnnotator\RatingAnnotatorFactory;
use SMW\PropertyRegistry;
use SMW\SemanticData;
use SMW\Store;

class Hooks {
	// property ids
	const PROP_RATING_AVERAGE = '__rp_average';
	const PROP_RATING_COUNT = '__rp_count';
	const PROP_CONTEST_ID = '__rp_contest_id';

	// canonical labels
	const PROP_LABEL_RATING_AVERAGE = 'Average rating';
	const PROP_LABEL_RATING_COUNT = 'Ratings count';
	const PROP_LABEL_CONTEST_ID = 'RatePage contest identifier';

	/**
	 * Register custom SMW properties
	 * @param PropertyRegistry $propertyRegistry
	 * @return bool
	 */
	static function onInitProperties ( PropertyRegistry $propertyRegistry ) : bool {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$definitions = [];

		if ( $config->get( 'RPEnableSMWRatings' ) || $config->get( 'RPEnableSMWContests' ) ) {
			$definitions += self::getRatingPropDefinitions();
		}
		if ( $config->get( 'RPEnableSMWContests' ) ) {
			$definitions += self::getContestPropDefinitions();
		}

		foreach ( $definitions as $propertyId => $definition ) {
			$propertyRegistry->registerProperty(
				$propertyId,
				$definition['type'],
				$definition['label'],
				$definition['viewable'],
				$definition['annotable']
			);

			$propertyRegistry->registerPropertyAlias(
				$propertyId,
				wfMessage( $definition['alias'] )->text()
			);

			$propertyRegistry->registerPropertyAliasByMsgKey(
				$propertyId,
				$definition['alias']
			);

			$propertyRegistry->registerPropertyDescriptionByMsgKey(
				$propertyId,
				$definition['description']
			);
		}

		return true;
	}

	private static function getRatingPropDefinitions() : array {
		return [
			self::PROP_RATING_AVERAGE => [
				'label' => self::PROP_LABEL_RATING_AVERAGE,
				'type'  => '_num',
				'alias' => 'ratePage-property-average-alias',
				'description' => 'ratePage-property-average-description',
				'viewable' => true,
				'annotable' => false
			],
			self::PROP_RATING_COUNT => [
				'label' => self::PROP_LABEL_RATING_COUNT,
				'type'  => '_num',
				'alias' => 'ratePage-property-count-alias',
				'description' => 'ratePage-property-average-description',
				'viewable' => true,
				'annotable' => false
			]
		];
	}

	private static function getContestPropDefinitions() : array {
		return [
			self::PROP_CONTEST_ID => [
				'label' => self::PROP_LABEL_CONTEST_ID,
				'type'  => '_txt',
				'alias' => 'ratePage-property-contest-id-alias',
				'description' => 'ratePage-property-contest-id-description',
				'viewable' => true,
				'annotable' => false
			]
		];
	}

	public static function onBeforeDataUpdateComplete( Store $store, SemanticData $semanticData ) : bool {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$title = $semanticData->getSubject()->getTitle();
		if ( $title === null ) {
			return true;
		}

		$annotators = [];
		if ( $config->get( 'RPEnableSMWRatings' ) && Rating::canPageBeRated( $title ) ) {
			$annotators = array_merge(
				$annotators,
				RatingAnnotatorFactory::newGeneralPropAnnotators( $title )
			);
		}
		if ( $config->get( 'RPEnableSMWContests' ) ) {
			$annotators = array_merge(
				$annotators,
				RatingAnnotatorFactory::newContestPropAnnotators( $title )
			);
		}

		foreach ( $annotators as $annotator ) {
			$annotator->addAnnotation( $semanticData );
		}

		return true;
	}
}
