<?php
namespace Wikibase\Lexeme\Search\Elastic\Tests;

use Elastica\Result;
use Elastica\ResultSet;
use Wikibase\Lexeme\Search\Elastic\LexemeTermResult;
use Wikibase\Lexeme\Tests\MediaWiki\LexemeDescriptionTestCase;

/**
 * @covers \Wikibase\Lexeme\Search\Elastic\LexemeTermResult
 */
class LexemeTermResultTest extends \MediaWikiIntegrationTestCase {
	use LexemeDescriptionTestCase;

	/**
	 * Labels for language & categories
	 * Used by LexemeDescriptionTest
	 * @var array
	 */
	private $labels = [
		'Q1' => [
			'en' => 'unit_test_en_english',
			'de' => 'unit_test_de_english',
			'fr' => 'unit_test_fr_english',
		],
		'Q2' => [
			'en' => 'unit_test_en_noun',
			'de' => 'unit_test_de_substantive',
			'fr' => 'unit_test_fr_noun',
		],
		'Q3' => [
			'ru' => 'unit_test_ru_unused'
		]
	];

	public static function termResultsProvider() {
		return [
			"by lemma" => [
				'en',
				[ 'Q1', 'Q2' ],
				[
					'_source' => [
						'title' => 'L1',
						'lexeme_language' => [ 'code' => 'en', 'entity' => 'Q1' ],
						'lexical_category' => 'Q2',
						'lemma' => [ 'duck', 'goose' ],
					],
					'highlight' => [ 'lemma' => [ 'duck' ] ],
				],
				[
					'id' => 'L1',
					'label' => [ 'en', 'duck' ],
					// e.g. English, noun
					'description' => [ 'en', [ 'unit_test_en_english', 'unit_test_en_noun' ] ],
					'matched' => [ 'en', 'duck' ],
					'matchedType' => 'label'
				]
			],
			"by id" => [
				'en',
				[ 'Q1', 'Q2' ],
				[
					'_source' => [
						'title' => 'L2',
						'lexeme_language' => [ 'code' => 'en', 'entity' => 'Q1' ],
						'lexical_category' => 'Q2',
						'lemma' => [ 'duck', 'goose' ],
					],
					'highlight' => [ 'title' => [ 'L2' ] ],
				],
				[
					'id' => 'L2',
					'label' => [ 'en', 'duck' ],
					// e.g. English, noun
					'description' => [ 'en', [ 'unit_test_en_english', 'unit_test_en_noun' ] ],
					'matched' => [ 'qid', 'L2' ],
					'matchedType' => 'entityId'
				]
			],
			"by id, no lang code" => [
				'en',
				[ 'Q1', 'Q2' ],
				[
					'_source' => [
						'title' => 'L2',
						'lexeme_language' => [ 'code' => null, 'entity' => 'Q1' ],
						'lexical_category' => 'Q2',
						'lemma' => [ 'duck', 'goose' ],
					],
					'highlight' => [ 'title' => [ 'L2' ] ],
				],
				[
					'id' => 'L2',
					'label' => [ 'und', 'duck' ],
					// e.g. English, noun
					'description' => [ 'en', [ 'unit_test_en_english', 'unit_test_en_noun' ] ],
					'matched' => [ 'qid', 'L2' ],
					'matchedType' => 'entityId'
				]
			],
			"by form" => [
				'en',
				[ 'Q1', 'Q2' ],
				[
					'_source' => [
						'title' => 'L2',
						'lexeme_language' => [ 'code' => 'en', 'entity' => 'Q1' ],
						'lexical_category' => 'Q2',
						'lemma' => [ 'duck', 'goose' ],
					],
					'highlight' => [ 'lexeme_forms.representation' => [ 'geese' ] ],
				],
				[
					'id' => 'L2',
					'label' => [ 'en', 'duck' ],
					// e.g. English, noun
					'description' => [ 'en', [ 'unit_test_en_english', 'unit_test_en_noun' ] ],
					'matched' => [ 'en', 'geese' ],
					'matchedType' => 'alias'
				]
			],
			"missing language code" => [
				'en',
				[ 'Q1', 'Q2' ],
				[
					'_source' => [
						'title' => 'L2',
						'lexeme_language' => [ 'code' => null, 'entity' => 'Q1' ],
						'lexical_category' => 'Q2',
						'lemma' => [ 'duck', 'goose' ],
					],
					'highlight' => [ 'lemma' => [ 'duck' ] ],
				],
				[
					'id' => 'L2',
					'label' => [ 'und', 'duck' ],
					// e.g. English, noun
					'description' => [ 'en', [ 'unit_test_en_english', 'unit_test_en_noun' ] ],
					'matched' => [ 'und', 'duck' ],
					'matchedType' => 'label'
				]
			],
			"in German" => [
				'de',
				[ 'Q1', 'Q2' ],
				[
					'_source' => [
						'title' => 'L1',
						'lexeme_language' => [ 'code' => 'en', 'entity' => 'Q1' ],
						'lexical_category' => 'Q2',
						'lemma' => [ 'duck', 'goose' ],
					],
					'highlight' => [ 'lemma' => [ 'duck' ] ],
				],
				[
					'id' => 'L1',
					'label' => [ 'en', 'duck' ],
					// e.g. 'Englische, Substantiv'
					'description' => [ 'de', [ 'unit_test_de_english', 'unit_test_de_substantive' ] ],
					'matched' => [ 'en', 'duck' ],
					'matchedType' => 'label'
				]
			],
			"language fallback" => [
				'de-ch',
				[ 'Q1', 'Q2' ],
				[
					'_source' => [
						'title' => 'L1',
						'lexeme_language' => [ 'code' => 'en', 'entity' => 'Q1' ],
						'lexical_category' => 'Q2',
						'lemma' => [ 'duck', 'goose' ],
					],
					'highlight' => [ 'lemma' => [ 'duck' ] ],
				],
				[
					'id' => 'L1',
					'label' => [ 'en', 'duck' ],
					// e.g. 'Englische, Substantiv'
					'description' => [ 'de-ch', [ 'unit_test_de_english', 'unit_test_de_substantive' ] ],
					'matched' => [ 'en', 'duck' ],
					'matchedType' => 'label'
				]
			],
			"category without labels" => [
				'en',
				[ 'Q1', 'Q3' ],
				[
					'_source' => [
						'title' => 'L1',
						'lexeme_language' => [ 'code' => 'en', 'entity' => 'Q1' ],
						'lexical_category' => 'Q3',
						'lemma' => [ 'duck', 'goose' ],
					],
					'highlight' => [ 'lemma' => [ 'duck' ] ],
				],
				[
					'id' => 'L1',
					'label' => [ 'en', 'duck' ],
					// e.g. 'English, Unknown'
					// TODO: find a way to test 'Unknown' without relying on the translations
					'description' => [ 'en', [ 'unit_test_en_english' ] ],
					'matched' => [ 'en', 'duck' ],
					'matchedType' => 'label'
				]
			],
			"language without labels" => [
				'en',
				[ 'Q3', 'Q2' ],
				[
					'_source' => [
						'title' => 'L1',
						'lexeme_language' => [ 'code' => 'en', 'entity' => 'Q3' ],
						'lexical_category' => 'Q2',
						'lemma' => [ 'duck', 'goose' ],
					],
					'highlight' => [ 'lemma' => [ 'duck' ] ],
				],
				[
					'id' => 'L1',
					'label' => [ 'en', 'duck' ],
					// e.g. 'Unknown language, noun'
					// TODO: find a way to test 'Unknown language' without relying on the translations
					'description' => [ 'en', [ 'unit_test_en_noun' ] ],
					'matched' => [ 'en', 'duck' ],
					'matchedType' => 'label'
				]
			],

		];
	}

	/**
	 * @dataProvider termResultsProvider
	 */
	public function testTransformResult(
		$displayLanguage,
		array $fetchIds,
		array $resultData,
		array $expected
	) {
		$termLookupFactory = $this->getTermLookupFactory( $fetchIds, $displayLanguage );

		$res = new LexemeTermResult(
			$this->getIdParser(),
			$this->getServiceContainer()->getLanguageFactory()->getLanguage( $displayLanguage ),
			$termLookupFactory
		);

		$result = new Result( $resultData );
		$resultSet = $this->getMockBuilder( ResultSet::class )
			->disableOriginalConstructor()->getMock();
		$resultSet->expects( $this->once() )->method( 'getResults' )->willReturn( [ $result ] );

		$converted = $res->transformElasticsearchResult( $resultSet );
		if ( empty( $expected ) ) {
			$this->assertCount( 0, $converted );
			return;
		}
		$this->assertCount( 1, $converted );
		$this->assertArrayHasKey( $expected['id'], $converted );
		$converted = $converted[$expected['id']];

		$this->assertSame( $expected['id'], $converted->getEntityId()->getSerialization(),
			'ID is wrong' );

		$this->assertSame( $expected['label'][0],
			$converted->getDisplayLabel()->getLanguageCode(), 'Label language is wrong' );
		$this->assertSame( $expected['label'][1], $converted->getDisplayLabel()->getText(),
			'Label text is wrong' );

		$this->assertSame( $expected['matched'][0],
			$converted->getMatchedTerm()->getLanguageCode(), 'Matched language is wrong' );
		$this->assertSame( $expected['matched'][1], $converted->getMatchedTerm()->getText(),
			'Matched text is wrong' );

		$this->assertSame( $expected['matchedType'], $converted->getMatchedTermType(),
			'Match type is wrong' );

		if ( !empty( $expected['description'] ) ) {
			$this->assertSame( $expected['description'][0],
				$converted->getDisplayDescription()->getLanguageCode(),
				'Description language is wrong' );
			foreach ( $expected['description'][1] as $word ) {
				$this->assertStringContainsString( $word, $converted->getDisplayDescription()->getText(),
					"Description text should contain the word  [$word]" );
			}
		} else {
			$this->assertNull( $converted->getDisplayDescription() );
		}
	}

}
