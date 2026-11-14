<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemByExternalId;

use Wikibase\DataModel\Entity\ItemId;

/**
 * @license GPL-2.0-or-later
 */
class LookUpItemByExternalIdResponse {

	/** @param ItemId[] $itemIds */
	public function __construct( public readonly array $itemIds ) {
	}

}
