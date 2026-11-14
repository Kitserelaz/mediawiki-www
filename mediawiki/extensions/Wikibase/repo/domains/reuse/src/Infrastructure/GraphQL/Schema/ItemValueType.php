<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Schema;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\Domains\Reuse\Domain\Model\PropertyValuePair;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Statement;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\ItemDescriptionsResolver;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\ItemLabelsResolver;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\ItemLabelsWithLanguageFallbackResolver;

/**
 * @license GPL-2.0-or-later
 */
class ItemValueType extends ObjectType {

	public function __construct(
		ItemLabelsResolver $labelsResolver,
		ItemLabelsWithLanguageFallbackResolver $labelsWithFallbackResolver,
		ItemDescriptionsResolver $descriptionsResolver,
		Types $types,
	) {
		$labelProviderType = $types->getLabelProviderType();
		$labelField = Types::copyFieldDefinition(
			$labelProviderType->getField( 'label' ),
			function( Statement|PropertyValuePair $valueProvider, array $args ) use ( $labelsResolver ) {
				/** @var EntityIdValue $idValue */
				$idValue = $valueProvider->value;
				'@phan-var EntityIdValue $idValue';

				/** @var ItemId $itemId */
				$itemId = $idValue->getEntityId();
				'@phan-var ItemId $itemId';

				return $labelsResolver->resolve( $itemId, $args['languageCode'] );
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

				/** @var ItemId $itemId */
				$itemId = $idValue->getEntityId();
				'@phan-var ItemId $itemId';

				return $labelsWithFallbackResolver->resolve( $itemId, $args['languageCode'] );
			},
		);

		$descriptionProviderType = $types->getDescriptionProviderType();
		$descriptionField = Types::copyFieldDefinition(
			$descriptionProviderType->getField( 'description' ),
			function( Statement|PropertyValuePair $valueProvider, array $args ) use ( $descriptionsResolver ) {
				/** @var EntityIdValue $idValue */
				$idValue = $valueProvider->value;
				'@phan-var EntityIdValue $idValue';

				/** @var ItemId $itemId */
				$itemId = $idValue->getEntityId();
				'@phan-var ItemId $itemId';

				return $descriptionsResolver->resolve( $itemId, $args['languageCode'] );
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
				$descriptionField,
			],
			'interfaces' => [ $labelProviderType, $descriptionProviderType ],
		] );
	}

}
