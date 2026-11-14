<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers;

use GraphQL\Deferred;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetItemLabelsWithLanguageFallback\BatchGetItemLabelsWithLanguageFallback;
// phpcs:ignore Generic.Files.LineLength.TooLong
use Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetItemLabelsWithLanguageFallback\BatchGetItemLabelsWithLanguageFallbackRequest;
use Wikibase\Repo\Domains\Reuse\Domain\Model\ItemLabelsWithFallbackBatch;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Label;

/**
 * @license GPL-2.0-or-later
 */
class ItemLabelsWithLanguageFallbackResolver {
	private array $itemsToFetch = [];
	private array $languageCodesToFetch = [];
	private ?ItemLabelsWithFallbackBatch $labelsBatch = null;

	public function __construct(
		private readonly BatchGetItemLabelsWithLanguageFallback $batchGetItemLabels,
	) {
	}

	public function resolve( ItemId $itemId, string $languageCode ): Deferred {
		$this->itemsToFetch[] = $itemId->getSerialization();
		$this->languageCodesToFetch[] = $languageCode;

		return new Deferred( function() use ( $itemId, $languageCode ): ?Label {
			if ( !$this->labelsBatch ) {
				$this->labelsBatch = $this->batchGetItemLabels
					->execute( new BatchGetItemLabelsWithLanguageFallbackRequest(
						array_values( array_unique( $this->itemsToFetch ) ),
						array_values( array_unique( $this->languageCodesToFetch ) ),
					) )
					->batch;
			}

			return $this->labelsBatch->getLabel( $itemId, $languageCode );
		} );
	}
}
