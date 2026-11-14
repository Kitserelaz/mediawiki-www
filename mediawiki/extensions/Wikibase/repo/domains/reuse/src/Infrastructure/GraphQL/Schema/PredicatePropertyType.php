<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Schema;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Wikibase\Repo\Domains\Reuse\Domain\Model\PredicateProperty;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\PropertyLabelsResolver;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\PropertyLabelsWithLanguageFallbackResolver;

/**
 * @license GPL-2.0-or-later
 */
class PredicatePropertyType extends ObjectType {

	public function __construct(
		PropertyLabelsResolver $labelsResolver,
		PropertyLabelsWithLanguageFallbackResolver $labelsWithFallbackResolver,
		Types $types,

	) {
		$labelProviderType = $types->getLabelProviderType();
		$labelField = Types::copyFieldDefinition(
			$labelProviderType->getField( 'label' ),
			fn( PredicateProperty $property, array $args ) => $labelsResolver->resolve(
				$property->id,
				$args['languageCode']
			),
		);

		$labelWithLanguageFallbackField = Types::copyFieldDefinition(
			$labelProviderType->getField( 'labelWithLanguageFallback' ),
			fn( PredicateProperty $property, array $args ) => $labelsWithFallbackResolver->resolve(
				$property->id,
				$args['languageCode']
			),
		);

		parent::__construct( [
			'fields' => [
				'id' => [
					'type' => Type::nonNull( Type::string() ),
					'resolve' => fn( PredicateProperty $rootValue ) => $rootValue->id,
				],
				'dataType' => [
					'type' => Type::string(),
					'resolve' => fn( PredicateProperty $rootValue ) => $rootValue->dataType,
				],
				$labelField,
				$labelWithLanguageFallbackField,
			],
			'interfaces' => [ $labelProviderType ],
		] );
	}

}
