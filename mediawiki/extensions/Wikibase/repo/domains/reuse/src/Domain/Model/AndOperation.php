<?php declare( strict_types = 1 );

namespace Wikibase\Repo\Domains\Reuse\Domain\Model;

/**
 * @license GPL-2.0-or-later
 */
class AndOperation implements ItemSearchFilter {

	/**
	 * @param ItemSearchFilter[] $filters
	 */
	public function __construct( public readonly array $filters ) {
	}
}
