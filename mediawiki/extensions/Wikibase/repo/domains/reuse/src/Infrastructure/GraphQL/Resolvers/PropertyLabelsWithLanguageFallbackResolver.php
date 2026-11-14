<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers;

use GraphQL\Deferred;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetPropertyLabelsWithLanguageFallback\BatchGetPropertyLabelsWithLanguageFallback;
// phpcs:ignore Generic.Files.LineLength.TooLong
use Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetPropertyLabelsWithLanguageFallback\BatchGetPropertyLabelsWithLanguageFallbackRequest;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Label;
use Wikibase\Repo\Domains\Reuse\Domain\Model\PropertyLabelsWithFallbackBatch;

/**
 * @license GPL-2.0-or-later
 */
class PropertyLabelsWithLanguageFallbackResolver {
	private array $propertiesToFetch = [];
	private array $languageCodesToFetch = [];
	private ?PropertyLabelsWithFallbackBatch $labelsBatch = null;

	public function __construct(
		private readonly BatchGetPropertyLabelsWithLanguageFallback $batchGetPropertyLabels,
	) {
	}

	public function resolve( PropertyId $propertyId, string $languageCode ): Deferred {
		$this->propertiesToFetch[] = $propertyId->getSerialization();
		$this->languageCodesToFetch[] = $languageCode;

		return new Deferred( function() use ( $propertyId, $languageCode ): ?Label {
			if ( !$this->labelsBatch ) {
				$this->labelsBatch = $this->batchGetPropertyLabels
					->execute( new BatchGetPropertyLabelsWithLanguageFallbackRequest(
						array_values( array_unique( $this->propertiesToFetch ) ),
						array_values( array_unique( $this->languageCodesToFetch ) ),
					) )
					->batch;
			}

			return $this->labelsBatch->getLabel( $propertyId, $languageCode );
		} );
	}
}
