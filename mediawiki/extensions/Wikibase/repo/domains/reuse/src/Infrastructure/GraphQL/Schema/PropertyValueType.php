<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Schema;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\Repo\Domains\Reuse\Domain\Model\PropertyValuePair;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Statement;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\PropertyLabelsResolver;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\PropertyLabelsWithLanguageFallbackResolver;

/**
 * @license GPL-2.0-or-later
 */
class PropertyValueType extends ObjectType {

	public function __construct(
		PropertyLabelsResolver $labelsResolver,
		PropertyLabelsWithLanguageFallbackResolver $labelsWithFallbackResolver,
		Types $types,
	) {
		$labelProviderType = $types->getLabelProviderType();
		$labelField = Types::copyFieldDefinition(
			$labelProviderType->getField( 'label' ),
			function( Statement|PropertyValuePair $valueProvider, array $args ) use ( $labelsResolver ) {
				/** @var EntityIdValue $idValue */
				$idValue = $valueProvider->value;
				'@phan-var EntityIdValue $idValue';

				/** @var PropertyId $propertyId */
				$propertyId = $idValue->getEntityId();
				'@phan-var PropertyId $propertyId';

				return $labelsResolver->resolve( $propertyId, $args['languageCode'] );
			},
		);

		$labelWithLanguageFallbackField = Types::copyFieldDefinition(
			$labelProviderType->getField( 'labelWithLanguageFallback' ),
			function(
				Statement|PropertyValuePair $valueProvider,
				array $args
			) use ( $labelsWithFallbackResolver ) {
				/** @var EntityIdValue $idValue */
				$idValue = $valueProvider->value;
				'@phan-var EntityIdValue $idValue';

				/** @var PropertyId $propertyId */
				$propertyId = $idValue->getEntityId();
				'@phan-var PropertyId $propertyId';

				return $labelsWithFallbackResolver->resolve( $propertyId, $args['languageCode'] );
			},
		);

		parent::__construct( [
			'fields' => [
				'id' => [
					'type' => Type::nonNull( Type::string() ),
					'resolve' => function( Statement|PropertyValuePair $valueProvider ) {
						/** @var EntityIdValue $idValue */
						$idValue = $valueProvider->value;
						'@phan-var EntityIdValue $idValue';

						return $idValue->getEntityId()->getSerialization();
					},
				],
				$labelField,
				$labelWithLanguageFallbackField,
			],
			'interfaces' => [ $labelProviderType ],
		] );
	}

}
