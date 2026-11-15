<?php

namespace Wikibase\Lexeme\Search\Elastic;

use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\Lexeme\Domain\Model\Lexeme;

class LemmaSpellingVariantsField extends LexemeKeywordField {
	public const NAME = 'lemma_spelling_variants';

	/**
	 * @inheritDoc
	 */
	public function getFieldData( EntityDocument $entity ) {
		if ( !$entity instanceof Lexeme ) {
			return [];
		}
		return array_keys( $entity->getLemmas()->toTextArray() );
	}
}
