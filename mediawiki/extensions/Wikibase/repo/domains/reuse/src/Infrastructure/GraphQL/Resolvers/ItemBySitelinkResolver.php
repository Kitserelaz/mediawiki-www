<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers;

use GraphQL\Deferred;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemBySitelink\LookUpItemBySitelink;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemBySitelink\LookUpItemBySitelinkRequest;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\QueryContext;

/**
 * @license GPL-2.0-or-later
 */
class ItemBySitelinkResolver {

	public function __construct(
		private readonly LookUpItemBySitelink $lookupBySitelinkUseCase,
		private readonly ItemResolver $itemResolver
	) {
	}

	public function resolve( string $siteId, string $title, QueryContext $context ): ?Deferred {
		$itemId = $this->lookupBySitelinkUseCase->execute(
			new LookUpItemBySitelinkRequest( $siteId, $title )
		)->itemId;

		return $itemId ? $this->itemResolver->resolveItem( "$itemId", $context ) : null;
	}

}
