<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Search\Infrastructure\Controllers;

use Wikibase\Lib\Interactors\TermSearchResult;

/**
 * @license GPL-2.0-or-later
 */
interface WbSearchEntitiesController {

	/**
	 * @return TermSearchResult[]
	 */
	public function search( WbSearchEntitiesRequest $request ): array;

}
