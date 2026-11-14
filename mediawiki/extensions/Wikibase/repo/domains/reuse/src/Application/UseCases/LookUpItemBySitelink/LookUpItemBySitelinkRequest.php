<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemBySitelink;

/**
 * @license GPL-2.0-or-later
 */
class LookUpItemBySitelinkRequest {

	public function __construct(
		public readonly string $siteId,
		public readonly string $title
	) {
	}
}
