<?php declare( strict_types = 1 );

namespace Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetPropertyLabelsWithLanguageFallback;

use Wikibase\Repo\Domains\Reuse\Domain\Model\PropertyLabelsWithFallbackBatch;

/**
 * @license GPL-2.0-or-later
 */
class BatchGetPropertyLabelsWithLanguageFallbackResponse {

	public function __construct( public readonly PropertyLabelsWithFallbackBatch $batch ) {
	}
}
