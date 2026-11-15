<?php

namespace Wikibase\Lexeme\Search\Elastic\Tests;

use Wikibase\Lexeme\Search\Elastic\LexemeCategoryField;

/**
 * @covers \Wikibase\Lexeme\Search\Elastic\LexemeCategoryField
 */
class LexemeCategoryFieldTest extends LexemeFieldTestBase {

	/**
	 * @return array
	 */
	public static function provideTestData() {
		return [
			[
				new LexemeCategoryField(),
				self::CATEGORY_ID
			]
		];
	}

}
