<?php declare( strict_types = 1 );

namespace Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetItemLabelsWithLanguageFallback;

use Wikibase\Repo\Domains\Reuse\Domain\Model\ItemLabelsWithFallbackBatch;

/**
 * @license GPL-2.0-or-later
 */
class BatchGetItemLabelsWithLanguageFallbackResponse {

	public function __construct( public readonly ItemLabelsWithFallbackBatch $batch ) {
	}
}
