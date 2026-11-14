<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Domain\Services;

use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;

/**
 * @license GPL-2.0-or-later
 */
interface ItemByExternalIdLookup {

	/**
	 * Should return a single Item ID in most cases, but might return multiple
	 * because external IDs aren't guaranteed to be unique
	 *
	 * @return ItemId[]
	 */
	public function lookupByExternalId( PropertyId $propertyId, string $externalId ): array;

}
