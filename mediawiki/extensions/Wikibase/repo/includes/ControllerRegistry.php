<?php

declare( strict_types = 1 );

namespace Wikibase\Repo;

use InvalidArgumentException;

/**
 * Registry of controller callbacks per entity type.
 *
 * @license GPL-2.0-or-later
 */
class ControllerRegistry {

	public const WB_SEARCH_ENTITIES_CONTROLLER = 'wbsearchentities-controller';

	/** @param array<string, array<string, callable>> $controllerDefinitions */
	public function __construct( private readonly array $controllerDefinitions ) {
		foreach ( $controllerDefinitions as $entityType => $controllers ) {
			if ( !is_array( $controllers ) ) {
				throw new InvalidArgumentException(
					"Expected an array of controller callbacks for entity type '$entityType'"
				);
			}
			foreach ( $controllers as $controllerName => $callback ) {
				if ( !is_callable( $callback ) ) {
					throw new InvalidArgumentException(
						"Expected a callable for controller '$controllerName' of entity type '$entityType'"
					);
				}
			}
		}
	}

	/**
	 * @param string $field one of the constants declared in this class
	 * @return array<string, callable> map of entity type => callback for that field
	 */
	public function get( string $field ): array {
		$result = [];
		foreach ( $this->controllerDefinitions as $type => $def ) {
			if ( isset( $def[$field] ) ) {
				$result[$type] = $def[$field];
			}
		}

		return $result;
	}

}
