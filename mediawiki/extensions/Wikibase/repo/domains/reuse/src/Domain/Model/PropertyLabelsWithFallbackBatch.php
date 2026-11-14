<?php declare( strict_types = 1 );

namespace Wikibase\Repo\Domains\Reuse\Domain\Model;

use Wikibase\DataModel\Entity\PropertyId;

/**
 * @license GPL-2.0-or-later
 */
class PropertyLabelsWithFallbackBatch {

	/**
	 * @param array<string, array<string, ?Label>> $propertyLabels
	 *   property ID serialization -> requested language code -> Label|null
	 */
	public function __construct( public readonly array $propertyLabels ) {
	}

	public function getLabel( PropertyId $propertyId, string $requestedLanguageCode ): ?Label {
		return $this->propertyLabels[$propertyId->getSerialization()][$requestedLanguageCode] ?? null;
	}
}
