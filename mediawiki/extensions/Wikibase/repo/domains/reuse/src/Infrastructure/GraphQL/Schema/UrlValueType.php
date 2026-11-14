<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Schema;

use GraphQL\Type\Definition\ObjectType;
use Wikibase\Repo\Domains\Reuse\Domain\Model\PropertyValuePair;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Statement;

/**
 * @license GPL-2.0-or-later
 */
class UrlValueType extends ObjectType {
	public function __construct( Types $types ) {
		$contentProviderType = $types->getStringContentProviderType();
		$contentField = Types::copyFieldDefinition(
			$contentProviderType->getField( 'content' ),
			fn( Statement|PropertyValuePair $valueProvider ) => $valueProvider->value->getValue(),
		);

		$urlProviderType = $types->getUrlProviderType();
		$urlField = Types::copyFieldDefinition(
			$urlProviderType->getField( 'url' ),
			fn( Statement|PropertyValuePair $valueProvider ) => $valueProvider->value->getValue(),
		);

		parent::__construct( [
			'interfaces' => [ $contentProviderType, $urlProviderType ],
			'fields' => [ $contentField, $urlField ],
		] );
	}
}
