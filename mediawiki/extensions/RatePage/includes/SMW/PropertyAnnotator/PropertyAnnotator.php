<?php

namespace RatePage\SMW\PropertyAnnotator;

use SMW\DIProperty;
use SMW\SemanticData;
use SMWDataItem;

abstract class PropertyAnnotator {

	public abstract function addAnnotation( SemanticData $semanticData );

	/**
	 * @param SemanticData $semanticData
	 * @param DIProperty $property
	 * @param SMWDataItem $dataItem
	 */
	protected function doAddAnnotation(
		SemanticData $semanticData,
		DIProperty $property,
		SMWDataItem $dataItem
	) {
		$semanticData->removeProperty( $property );

		if ( $dataItem ) {
			$semanticData->addPropertyObjectValue( $property, $dataItem );
		}
	}
}