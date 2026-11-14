<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemByExternalId;

use Wikibase\Repo\Domains\Reuse\Application\UseCases\UseCaseError;
use Wikibase\Repo\Domains\Reuse\Domain\Services\ItemByExternalIdLookup;

/**
 * @license GPL-2.0-or-later
 */
class LookUpItemByExternalId {

	public function __construct(
		private readonly LookUpItemByExternalIdValidator $validator,
		private readonly ItemByExternalIdLookup $lookup,
	) {
	}

	/**
	 * @throws UseCaseError
	 */
	public function execute( LookUpItemByExternalIdRequest $request ): LookUpItemByExternalIdResponse {
		$this->validator->validate( $request );

		return new LookUpItemByExternalIdResponse(
			$this->lookup->lookupByExternalId( $request->property, $request->externalId )
		);
	}
}
