<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Search\RouteHandlers;

use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Response;

/**
 * @license GPL-2.0-or-later
 */
class V0RouteHandler extends Handler {

	public static function factory(): Handler {
		return new self();
	}

	public function execute(): Response {
		$responseFactory = new ResponseFactory();

		return $responseFactory->newErrorResponse(
			404,
			'resource-not-found',
			"v0 has been removed, please modify your routes to v1 such as '/rest.php/wikibase/v1'"
		);
	}

	public function needsWriteAccess(): bool {
		return false;
	}
}
