<?php declare( strict_types = 1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Schema;

use GraphQL\Type\Definition\ObjectType;
use Wikibase\Lib\Store\PropertyInfoLookup;
use Wikibase\Repo\Domains\Reuse\Domain\Model\PropertyValuePair;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Statement;

/**
 * @license GPL-2.0-or-later
 */
class ExternalIdValueType extends ObjectType {

	public function __construct( private readonly PropertyInfoLookup $propertyInfoLookup, Types $types ) {
		$stringContentProviderType = $types->getStringContentProviderType();
		$stringContentField = Types::copyFieldDefinition(
			$stringContentProviderType->getField( 'content' ),
			fn( Statement|PropertyValuePair $valueProvider ) => $valueProvider->value->getValue(),
		);

		$urlProviderType = $types->getUrlProviderType();
		$urlField = Types::copyFieldDefinition(
			$urlProviderType->getField( 'url' ),
			function ( Statement|PropertyValuePair $valueProvider ): ?string {
				$propertyInfo = $this->propertyInfoLookup->getPropertyInfo( $valueProvider->property->id );
				$formatterUrl = $propertyInfo[PropertyInfoLookup::KEY_FORMATTER_URL] ?? null;
				return $this->formatUrl( $formatterUrl, (string)$valueProvider->value->getValue() );
			},
		);

		parent::__construct( [
			'interfaces' => [ $stringContentProviderType, $urlProviderType ],
			'fields' => [
				$stringContentField,
				$urlField,
			],
		] );
	}

	private function formatUrl( ?string $formatterUrl, string $value ): ?string {
		if ( $formatterUrl === null ) {
			return null;
		} else {
			return str_replace( '$1', rawurlencode( trim( $value ) ), $formatterUrl );
		}
	}
}
