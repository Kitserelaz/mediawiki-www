<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemBySitelink;

use Wikibase\DataModel\Entity\ItemId;

/**
 * @license GPL-2.0-or-later
 */
class LookUpItemBySitelinkResponse {

	public function __construct( public readonly ?ItemId $itemId ) {
	}

}
