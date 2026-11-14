<?php declare( strict_types = 1 );

namespace Wikibase\Repo\Domains\Reuse\Domain\Model;

use Wikibase\DataModel\Entity\ItemId;

/**
 * @license GPL-2.0-or-later
 */
class ItemLabelsWithFallbackBatch {

	/**
	 * @param array<string, array<string, ?Label>> $itemLabels
	 *   item ID serialization -> requested language code -> Label|null
	 */
	public function __construct( public readonly array $itemLabels ) {
	}

	public function getLabel( ItemId $itemId, string $requestedLanguageCode ): ?Label {
		return $this->itemLabels[$itemId->getSerialization()][$requestedLanguageCode] ?? null;
	}
}
