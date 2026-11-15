<?php

namespace Wikibase\Lexeme\Search\Elastic\Tests;

use Wikibase\Lexeme\Search\Elastic\LemmaField;

/**
 * @covers \Wikibase\Lexeme\Search\Elastic\LemmaField
 */
class LemmaFieldTest extends LexemeFieldTestBase {

	/**
	 * @return array
	 */
	public static function provideTestData() {
		return [
			[
				new LemmaField(),
				[ "Test Lemma", "Test lemma" ]
			]
		];
	}

}
