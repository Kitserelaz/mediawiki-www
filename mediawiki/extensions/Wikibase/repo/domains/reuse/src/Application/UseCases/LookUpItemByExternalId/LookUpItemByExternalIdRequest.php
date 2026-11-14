<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemByExternalId;

use Wikibase\DataModel\Entity\PropertyId;

/**
 * @license GPL-2.0-or-later
 */
class LookUpItemByExternalIdRequest {

	public function __construct(
		public readonly PropertyId $property,
		public readonly string $externalId,
	) {
	}

}
