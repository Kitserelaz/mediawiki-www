<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Domain\Services;

use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\Domains\Reuse\Domain\Model\ItemLabelsWithFallbackBatch;

/**
 * @license GPL-2.0-or-later
 */
class ItemLabelsWithLanguageFallbackBatchRetriever {

	public function __construct(
		private readonly BatchItemLabelsRetriever $labelsRetriever,
		private readonly LanguageFallbackChainProvider $languageFallbackChainProvider,
		private readonly LanguageFallbackLabelSelector $languageFallbackLabelSelector
	) {
	}

	/**
	 * @param ItemId[] $itemIds
	 * @param string[] $languageCodes
	 */
	public function getItemLabelsWithLanguageFallback(
		array $itemIds,
		array $languageCodes
	): ItemLabelsWithFallbackBatch {
		$languagesToFetch = array_unique( array_merge( ...array_map(
			fn( string $langCode ) => $this->languageFallbackChainProvider->getFallbackLanguages( $langCode ),
			$languageCodes
		) ) );

		$fetchedLabels = $this->labelsRetriever->getItemLabels( $itemIds, $languagesToFetch );
		$result = [];
		foreach ( $itemIds as $itemId ) {
			$itemLabels = $fetchedLabels->getItemLabels( $itemId );
			foreach ( $languageCodes as $requestedLang ) {
				$result[$itemId->getSerialization()][$requestedLang] = $this->languageFallbackLabelSelector->selectLabel(
					$requestedLang,
					$itemLabels
				);
			}
		}

		return new ItemLabelsWithFallbackBatch( $result );
	}

}
