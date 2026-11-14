<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Language\AST\DocumentNode;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Errors\GraphQLError;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Errors\GraphQLErrorType;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Schema\Schema;
use Wikimedia\Stats\StatsFactory;

/**
 * @license GPL-2.0-or-later
 */
class GraphQLTracking {

	public function __construct(
		private readonly Schema $schema,
		private readonly StatsFactory $stats,
		private readonly GraphQLFieldCollector $graphQLFieldCollector,
	) {
	}

	public function recordQueryMetrics( ExecutionResult $result, ?DocumentNode $doc, ?string $operationName ): void {
		$this->trackErrors( $result->errors );
		if ( !$result->data ) {
			$this->incrementHitMetric( 'error' );
			return;
		}

		'@phan-var DocumentNode $doc'; // guaranteed non-null here because there is data
		$usedFields = $this->graphQLFieldCollector->getRequestedFieldPaths( $doc, $operationName );
		$isIntrospectionQuery = !array_intersect( $this->schema->fieldNames, $usedFields );
		if ( $isIntrospectionQuery ) {
			$this->incrementHitMetric( 'introspection' );
			return;
		}

		// field usage is tracked for (partial) success, but not introspection-only or error-only
		$this->trackFieldUsage( $usedFields );

		if ( $result->errors ) {
			$this->incrementHitMetric( 'partial_success' );
		} else {
			$this->incrementHitMetric( 'success' );
		}
	}

	private function incrementHitMetric( string $status ): void {
		$this->stats->getCounter( 'wikibase_graphql_hit_total' )
			->setLabel( 'status', $status )
			->increment();
	}

	private function trackFieldUsage( array $fields ): void {
		foreach ( $fields as $field ) {
			$this->stats->getCounter( 'wikibase_graphql_field_usage_total' )
				->setLabel( 'field', $field )
				->increment();
		}
	}

	private function trackErrors( array $errors ): void {
		$errorTypes = array_unique( array_map(
			fn( Error $e ) => $this->getErrorType( $e )->name,
			$errors,
		) );

		foreach ( $errorTypes as $type ) {
			$this->stats->getCounter( 'wikibase_graphql_error_total' )
				->setLabel( 'type', $type )
				->increment();
		}
	}

	private function getErrorType( Error $error ): GraphQLErrorType {
		if ( $error instanceof GraphQLError ) {
			return $error->type;
		}

		// If there is no previous error, it means that the GraphQL engine itself rejected the query
		// e.g. because the query was malformed, or an invalid field or operation name.
		if ( $error->getPrevious() === null ) {
			return GraphQLErrorType::INVALID_QUERY;
		}

		return GraphQLErrorType::UNKNOWN;
	}

	public function trackValidationError( string $errorType ): void {
		$this->stats->getCounter( 'wikibase_graphql_error_total' )
			->setLabel( 'type', $errorType )
			->increment();
		$this->incrementHitMetric( 'error' );
	}
}
