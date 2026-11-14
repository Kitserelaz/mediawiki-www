<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL;

use GraphQL\Error\Error;
use Psr\Log\LoggerInterface;

/**
 * @license GPL-2.0-or-later
 */
class GraphQLErrorLogger {

	public function __construct( private readonly LoggerInterface $logger ) {
	}

	/**
	 * Exceptions thrown in the query execution process get caught within {@link GraphQL::executeQuery} and rethrown as {@link Error}
	 * wrapping the original exception. Expected exceptions thrown within our code extend {@link GraphQLError}, and get unwrapped,
	 * so anything with a previous exception is an unexpected error.
	 *
	 * @param Error[] $errors
	 */
	public function logUnexpectedErrors( array $errors ): void {
		foreach ( $errors as $error ) {
			$previousError = $error->getPrevious();
			if ( $previousError ) {
				$this->logger->error( $previousError->getMessage(), [
					'trace' => $previousError->getTraceAsString(),
				] );
			}
		}
	}
}
