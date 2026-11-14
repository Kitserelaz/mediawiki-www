<?php declare( strict_types = 1 );

namespace Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetItemLabelsWithLanguageFallback;

use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\Domains\Reuse\Domain\Services\ItemLabelsWithLanguageFallbackBatchRetriever;

/**
 * @license GPL-2.0-or-later
 */
class BatchGetItemLabelsWithLanguageFallback {

	public function __construct( private readonly ItemLabelsWithLanguageFallbackBatchRetriever $labelsRetriever ) {
	}

	/**
	 * This use case does not validate its request object.
	 * Validation must be added before it can be used in a context where the request is created from user input.
	 */
	public function execute(
		BatchGetItemLabelsWithLanguageFallbackRequest $request
	): BatchGetItemLabelsWithLanguageFallbackResponse {
		$itemIds = array_map(
			fn( string $id ) => new ItemId( $id ),
			$request->itemIds
		);

		return new BatchGetItemLabelsWithLanguageFallbackResponse(
			$this->labelsRetriever->getItemLabelsWithLanguageFallback( $itemIds, $request->languageCodes )
		);
	}

}
