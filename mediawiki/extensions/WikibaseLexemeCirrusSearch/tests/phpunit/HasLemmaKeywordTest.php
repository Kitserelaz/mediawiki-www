<?php

namespace Wikibase\Lexeme\Search\Elastic\Tests;

use CirrusSearch\CrossSearchStrategy;
use PHPUnit\Framework\TestCase;
use Wikibase\Lexeme\Search\Elastic\HasLemmaKeyword;

/**
 * @covers \Wikibase\Lexeme\Search\Elastic\HasLemmaKeyword
 */
class HasLemmaKeywordTest extends TestCase {
	use \CirrusSearch\Query\SimpleKeywordFeatureTestTrait;

	public static function provideQueries(): array {
		$tooMany = array_map( static function ( $l ) {
			return (string)$l;
		}, range( 1, HasLemmaKeyword::MAX_CONDITIONS + 20 ) );
		$actualVariants = array_slice( $tooMany, 0, HasLemmaKeyword::MAX_CONDITIONS );

		return [
			'simple' => [
				'haslemma:fr',
				[ 'variants' => [ 'fr' ] ],
				[ 'match' => [ 'lemma_spelling_variants' => [ 'query' => 'fr' ] ] ],
				[],
			],
			'multiple' => [
				'haslemma:en,en-gb',
				[ 'variants' => [ 'en', 'en-gb' ] ],
				[
					'bool' => [
						'minimum_should_match' => 1,
						'should' => [
							[ 'match' => [ 'lemma_spelling_variants' => [ 'query' => 'en' ] ] ],
							[ 'match' => [ 'lemma_spelling_variants' => [ 'query' => 'en-gb' ] ] ],
						],
					],
				],
				[],
			],
			'too many' => [
				'haslemma:' . implode( ',', $tooMany ),
				[ 'variants' => $actualVariants ],
				[
					'bool' => [
						'minimum_should_match' => 1,
						'should' => array_map( static function ( $l ) {
							return [
								'match' => [
									'lemma_spelling_variants' => [
										'query' => (string)$l,
									]
								],
							];
						}, range( 1, HasLemmaKeyword::MAX_CONDITIONS ) ),
					],
				],
				[
					[
						'cirrussearch-feature-too-many-conditions',
						'haslemma',
						HasLemmaKeyword::MAX_CONDITIONS,
					],
				],
			],
		];
	}

	/**
	 * @dataProvider provideQueries
	 */
	public function test( $term, $expected, array $filter, $warnings ) {
		$feature = new HasLemmaKeyword();
		$this->assertParsedValue( $feature, $term, $expected, $warnings );
		$this->assertCrossSearchStrategy( $feature, $term,
			CrossSearchStrategy::hostWikiOnlyStrategy() );
		$this->assertExpandedData( $feature, $term, [], [] );
		$this->assertWarnings( $feature, $warnings, $term );
		$this->assertFilter( $feature, $term, $filter, $warnings );
	}
}
