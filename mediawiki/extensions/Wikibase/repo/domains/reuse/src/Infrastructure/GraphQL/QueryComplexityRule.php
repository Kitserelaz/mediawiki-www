<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL;

use GraphQL\Validator\QueryValidationContext;
use GraphQL\Validator\Rules\QueryComplexity;

/**
 * @license GPL-2.0-or-later
 */
class QueryComplexityRule extends QueryComplexity {
	private bool $wasChecked = false;

	public function getVisitor( QueryValidationContext $context ): array {
		$this->wasChecked = true;
		return parent::getVisitor( $context );
	}

	/**
	 * This method is used in order to check whether getQueryComplexity() can be called,
	 * since getQueryComplexity() errors when it is called and the rule was not used.
	 */
	public function wasViolated(): bool {
		return $this->wasChecked && $this->getQueryComplexity() > $this->getMaxQueryComplexity();
	}
}
