<?php declare( strict_types = 1 );

namespace Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetPropertyLabelsWithLanguageFallback;

use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\Repo\Domains\Reuse\Domain\Services\PropertyLabelsWithLanguageFallbackBatchRetriever;

/**
 * @license GPL-2.0-or-later
 */
class BatchGetPropertyLabelsWithLanguageFallback {

	public function __construct( private readonly PropertyLabelsWithLanguageFallbackBatchRetriever $labelsRetriever ) {
	}

	/**
	 * This use case does not validate its request object.
	 * Validation must be added before it can be used in a context where the request is created from user input.
	 */
	public function execute(
		BatchGetPropertyLabelsWithLanguageFallbackRequest $request
	): BatchGetPropertyLabelsWithLanguageFallbackResponse {
		$propertyIds = array_map(
			fn( string $id ) => new NumericPropertyId( $id ),
			$request->propertyIds
		);

		return new BatchGetPropertyLabelsWithLanguageFallbackResponse(
			$this->labelsRetriever->getPropertyLabelsWithLanguageFallback( $propertyIds, $request->languageCodes )
		);
	}

}
