<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Schema;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * @license GPL-2.0-or-later
 */
class ItemSearchConditionType extends InputObjectType {

	public function __construct( Types $types ) {
		parent::__construct(
			[
				'description' => 'A single property/value condition used in item search.',
				'fields' => [
					'property' => $types->getPropertyIdType(),
					'value' => Type::string(),
				],
			]
		);
	}
}
