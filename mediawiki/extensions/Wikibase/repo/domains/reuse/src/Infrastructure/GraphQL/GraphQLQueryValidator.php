<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL;

use GraphQL\Error\SyntaxError;
use GraphQL\Language\Parser;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Errors\GraphQLError;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Validation\ValidResult;

/**
 * @license GPL-2.0-or-later
 */
class GraphQLQueryValidator {

	public static function validate( string $query ): ValidResult|GraphQLError {
		if ( trim( $query ) === '' ) {
			return GraphQLError::missingQuery();
		}

		try {
			$documentNode = Parser::parse( $query );
		} catch ( SyntaxError $e ) {
			return GraphQLError::invalidQuery( $e->getMessage() );
		}

		return new ValidResult( $documentNode );
	}

}
