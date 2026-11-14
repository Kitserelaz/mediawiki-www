<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\DataAccess;

use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Lib\Store\SiteLinkLookup;
use Wikibase\Repo\Domains\Reuse\Domain\Services\ItemBySitelinkLookup;

/**
 * @license GPL-2.0-or-later
 */
class SiteLinkLookupItemBySitelinkLookup implements ItemBySitelinkLookup {

	public function __construct(
		private readonly SiteLinkLookup $sitelinkLookup
	) {
	}

	public function lookUp( string $title, string $siteId ): ?ItemId {
		return $this->sitelinkLookup->getItemIdForLink( $siteId, $title );
	}
}
