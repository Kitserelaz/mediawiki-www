<?php

namespace GrowthExperiments\NewcomerTasks\AddLink;

use GrowthExperiments\NewcomerTasks\RecommendationProvider;
use GrowthExperiments\NewcomerTasks\TaskType\LinkRecommendationTaskType;
use GrowthExperiments\NewcomerTasks\TaskType\TaskType;
use MediaWiki\Linker\LinkTarget;
use StatusValue;

/** @inheritDoc */
interface LinkRecommendationProvider extends RecommendationProvider {
	// This is identical to the parent class, it just exists as a placeholder for the type hint.

	/**
	 * @inheritDoc
	 * @param LinkRecommendationTaskType $taskType
	 * @phan-param TaskType $taskType
	 * @return LinkRecommendation|StatusValue
	 */
	public function get( LinkTarget $title, TaskType $taskType );

}
