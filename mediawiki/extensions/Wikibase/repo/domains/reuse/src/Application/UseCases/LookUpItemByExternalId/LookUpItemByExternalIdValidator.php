<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemByExternalId;

use Wikibase\DataModel\Services\Lookup\PropertyDataTypeLookup;
use Wikibase\DataModel\Services\Lookup\PropertyDataTypeLookupException;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\UseCaseError;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\UseCaseErrorType;

/**
 * @license GPL-2.0-or-later
 */
class LookUpItemByExternalIdValidator {

	public function __construct( private readonly PropertyDataTypeLookup $dataTypeLookup ) {
	}

	/**
	 * @throws UseCaseError
	 */
	public function validate( LookUpItemByExternalIdRequest $request ): void {
		try {
			$dataType = $this->dataTypeLookup->getDataTypeIdForProperty( $request->property );
		} catch ( PropertyDataTypeLookupException ) {
			throw new UseCaseError(
				UseCaseErrorType::INVALID_EXTERNAL_ID_PROPERTY,
				"Property '{$request->property}' does not exist."
			);
		}

		if ( $dataType !== 'external-id' ) {
			throw new UseCaseError(
				UseCaseErrorType::INVALID_EXTERNAL_ID_PROPERTY,
				"Property '{$request->property}' is not of type 'external-id'."
			);
		}
	}
}
