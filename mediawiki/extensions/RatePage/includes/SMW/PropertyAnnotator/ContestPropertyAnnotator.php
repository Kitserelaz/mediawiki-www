<?php

namespace RatePage\SMW\PropertyAnnotator;

use RatePage\SMW\Hooks;
use SMW\SemanticData;
use SMW\Subobject;
use SMWDIBlob;
use SMWDIProperty;
use Title;

class ContestPropertyAnnotator extends PropertyAnnotator {
	/**
	 * @var Title
	 */
	private $title;

	/**
	 * @var string
	 */
	private $contestId;

	/**
	 * @var PropertyAnnotator[]
	 */
	private $annotators;

	/**
	 * ContestPropertyAnnotator constructor.
	 *
	 * @param Title $title
	 * @param string $contestId
	 * @param PropertyAnnotator[] $annotators
	 */
	public function __construct(
		Title $title,
		string $contestId,
		array $annotators
	) {
		$this->title = $title;
		$this->contestId = $contestId;
		$this->annotators = $annotators;
	}

	public function addAnnotation( SemanticData $semanticData ) {
		$subobject = new Subobject( $this->title );
		$subobject->setEmptyContainerForId( '_ratepage_' . $this->contestId );

		$subData = $subobject->getSemanticData();
		// For some crazy reason addPropertyValue glitches out completely here
		$subData->addPropertyObjectValue(
			new SMWDIProperty( Hooks::PROP_CONTEST_ID ),
			new SMWDIBlob( $this->contestId )
		);
		foreach ( $this->annotators as $annotator ) {
			$annotator->addAnnotation( $subData );
		}

		$semanticData->addSubobject( $subobject );
	}
}