<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Schema;

use GraphQL\Type\Definition\ObjectType;
use Wikibase\Repo\Domains\Reuse\Domain\Model\PropertyValuePair;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Statement;

/**
 * @license GPL-2.0-or-later
 */
class StringValueType extends ObjectType {
	public function __construct( Types $types ) {
		$stringContentProviderType = $types->getStringContentProviderType();
		$stringContentField = Types::copyFieldDefinition(
			$stringContentProviderType->getField( 'content' ),
			fn( Statement|PropertyValuePair $valueProvider ) => $valueProvider->value->getValue(),
		);
		parent::__construct( [
			'interfaces' => [ $stringContentProviderType ],
			'fields' => [ $stringContentField ],
		] );
	}
}
