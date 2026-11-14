<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemBySitelink;

use Wikibase\Repo\Domains\Reuse\Domain\Services\ItemBySitelinkLookup;

/**
 * @license GPL-2.0-or-later
 */
class LookUpItemBySitelink {

	public function __construct( private readonly ItemBySitelinkLookup $itemLookup ) {
	}

	public function execute( LookUpItemBySitelinkRequest $request ): LookUpItemBySitelinkResponse {
		$itemId = $this->itemLookup->lookUp( $request->title, $request->siteId );

		return new LookUpItemBySitelinkResponse( $itemId );
	}
}
