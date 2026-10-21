<?php
namespace Wikibase\Lexeme\Search\Elastic\Tests;

use Elastica\Response;
use Elastica\Result;
use Elastica\ResultSet;
use MediaWikiIntegrationTestCase;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Lexeme\Domain\Model\FormId;
use Wikibase\Lexeme\Search\Elastic\LexemeFulltextResult;
use Wikibase\Lexeme\Search\Elastic\LexemeResult;
use Wikibase\Lexeme\Search\Elastic\LexemeResultSet;
use Wikibase\Lexeme\Tests\MediaWiki\LexemeDescriptionTestCase;

// phpcs:disable Generic.Files.LineLength.TooLong
// phpcs:disable Generic.Files.LineLength.MaxExceeded
// We need long template strings here...
/**
 * @covers \Wikibase\Lexeme\Search\Elastic\LexemeFulltextResult
 * @covers \Wikibase\Lexeme\Search\Elastic\LexemeResult
 * @covers \Wikibase\Lexeme\Search\Elastic\LexemeResultSet
 *
 * @group Database
 */
class LexemeFulltextResultTest extends MediaWikiIntegrationTestCase {
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
			'fr' => 'unit_test_fr_singular'
		],
		'Q4' => [
			'fr' => 'unit_test_fr_plural',
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
					'lemma' => 'duck',
					'lang' => 'Q1',
					'langcode' => 'en',
					'category' => 'Q2',
					'title' => 'duck',
					// e.g. '<span class="wb-itemlink-description">English, noun</span>'
					'description_contains' => [ 'unit_test_en_english', 'unit_test_en_noun' ]
				]
			],
			"by lemma de" => [
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
					'lemma' => 'duck',
					'lang' => 'Q1',
					'langcode' => 'en',
					'category' => 'Q2',
					'title' => 'duck',
					// e.g. '<span class="wb-itemlink-description">Englische, Substantiv</span>'
					'description_contains' => [ 'unit_test_de_substantive', 'unit_test_de_english' ]
				]
			],
			'by id' => [
				'en',
				[ 'Q1', 'Q2' ],
				[
					'_source' => [
						'title' => 'L1',
						'lexeme_language' => [ 'code' => 'en', 'entity' => 'Q1' ],
						'lexical_category' => 'Q2',
						'lemma' => [ 'duck', 'goose' ],
					],
					'highlight' => [ 'title' => [ 'L1' ] ],
				],
				[
					'id' => 'L1',
					'lemma' => 'duck',
					'lang' => 'Q1',
					'langcode' => 'en',
					'category' => 'Q2',
					'title' => 'duck',
					// e.g. '<span class="wb-itemlink-description">English, noun</span>'
					'description_contains' => [ 'unit_test_en_english', 'unit_test_en_noun' ]
				]

			],
			'by form id' => [
				'fr',
				[ 'Q1', 'Q2', 'Q3' ],
				[
					'_source' => [
						'title' => 'L1',
						'lexeme_language' => [ 'code' => 'en', 'entity' => 'Q1' ],
						'lexical_category' => 'Q2',
						'lemma' => [ 'duck', 'goose' ],
						'lexeme_forms' => [
							[ 'id' => 'L1-F1', 'representation' => [ 'ducks', 'geese' ] ],
							[
								'id' => 'L1-F2',
								'representation' => [ 'moreducks', 'moregeese' ],
								'features' => [ 'Q3' ],
							],
						],
					],
					'highlight' => [ 'lexeme_forms.id' => [ 'L1-F2' ] ],
				],
				[
					'id' => 'L1',
					'lemma' => 'duck',
					'lang' => 'Q1',
					'langcode' => 'en',
					'category' => 'Q2',
					'formId' => 'L1-F2',
					'representation' => 'moreducks',
					'features' => [ new ItemId( 'Q3' ) ],
					'title' => 'moreducks',
					// e.g. '<span class="wb-itemlink-description">singulier pour : duck (L1) : (Anglais) nom</span>'
					'description_contains' => [ 'unit_test_fr_singular', 'duck', 'unit_test_fr_english' ]
				]
			],
			'by form repr' => [
				'fr',
				[ 'Q1', 'Q2', 'Q4' ],
				[
					'_source' => [
						'title' => 'L1',
						'lexeme_language' => [ 'code' => 'en', 'entity' => 'Q1' ],
						'lexical_category' => 'Q2',
						'lemma' => [ 'duck', 'goose' ],
						'lexeme_forms' => [
							[
								'id' => 'L1-F1',
								'representation' => [ 'ducks', 'geese' ],
								'features' => [ 'Q4' ],
							],
							[
								'id' => 'L1-F2',
								'representation' => [ 'moreducks', 'moregeese' ],
								'features' => [ 'Q3' ],
							],
						],
					],
					'highlight' => [ 'lexeme_forms.representation' => [ 'ducks' ] ],
				],
				[
					'id' => 'L1',
					'lemma' => 'duck',
					'lang' => 'Q1',
					'langcode' => 'en',
					'category' => 'Q2',
					'formId' => 'L1-F1',
					'representation' => 'ducks',
					'features' => [ new ItemId( 'Q4' ) ],
					'title' => 'ducks',
					// e.g. '<span class="wb-itemlink-description">pluriel pour : duck (L1) : (Anglais) nom</span>'
					'description_contains' => [ 'unit_test_fr_plural', 'duck', 'unit_test_fr_noun' ]
				]
			],
			'by another form repr' => [
				'fr',
				[ 'Q1', 'Q2', 'Q3' ],
				[
					'_source' => [
						'title' => 'L1',
						'lexeme_language' => [ 'code' => 'en', 'entity' => 'Q1' ],
						'lexical_category' => 'Q2',
						'lemma' => [ 'duck', 'goose' ],
						'lexeme_forms' => [
							[
								'id' => 'L1-F1',
								'representation' => [ 'ducks', 'geese' ],
								'features' => [ 'Q4' ],
							],
							[
								'id' => 'L1-F2',
								'representation' => [ 'moreducks', 'moregeese' ],
								'features' => [ 'Q3' ],
							],
						],
					],
					'highlight' => [ 'lexeme_forms.representation' => [ 'moregeese' ] ],
				],
				[
					'id' => 'L1',
					'lemma' => 'duck',
					'lang' => 'Q1',
					'langcode' => 'en',
					'category' => 'Q2',
					'formId' => new FormId( 'L1-F2' ),
					'representation' => 'moregeese',
					'features' => [ new ItemId( 'Q3' ) ],
					'title' => 'moregeese',
					// e.g. '<span class="wb-itemlink-description">singulier pour : duck (L1) : (Anglais) nom</span>'
					'description_contains' => [ 'unit_test_fr_singular', 'duck', 'unit_test_fr_english' ]
				]
			],
			'empty results' => [
				'fr',
				[],
				null,
				[]
			],
		];
	}

	/**
	 * @dataProvider termResultsProvider
	 */
	public function testTransformResult(
		$displayLanguage,
		array $fetchIds,
		$resultData,
		array $expected
	) {
		$termLookupFactory = $this->getTermLookupFactory( $fetchIds, $displayLanguage );

		$res = new LexemeFulltextResult(
			$this->getIdParser(),
			$this->getServiceContainer()->getLanguageFactory()->getLanguage( $displayLanguage ),
			$termLookupFactory
		);

		$resultSet = $this->getMockBuilder( ResultSet::class )
			->disableOriginalConstructor()->getMock();
		if ( $resultData === null ) {
			$resultSet->expects( $this->any() )->method( 'getResults' )->willReturn( [] );
		} else {
			$result = new Result( $resultData );
			$resultSet->expects( $this->any() )->method( 'getResults' )->willReturn( [ $result ] );
		}
		$resultSet->expects( $this->any() )
			->method( 'getResponse' )
			->willReturn( new Response( '{}', 200 ) );

		$converted = $res->transformElasticsearchResult( $resultSet );
		if ( empty( $expected ) ) {
			$this->assertCount( 0, $converted );
			return;
		}

		/**
		 * @var LexemeResultSet $converted
		 */
		$this->assertInstanceOf( LexemeResultSet::class, $converted );
		$this->assertCount( 1, $converted );

		$rawResults = $converted->getRawResults();
		$this->assertCount( 1, $rawResults );

		$rawResult = reset( $rawResults );
		// Check raw data
		$this->assertSame( $expected['id'], $rawResult['lexemeId']->getSerialization(),
			'Bad lexeme ID' );
		$this->assertSame( $expected['lemma'], $rawResult['lemma'],
			'Bad lemma match' );
		$this->assertSame( $expected['lang'], $rawResult['lang'],
			'Bad language match' );
		$this->assertSame( $expected['langcode'], $rawResult['langcode'],
			'Bad langcode match' );
		$this->assertSame( $expected['category'], $rawResult['category'],
			'Bad category match' );

		if ( isset( $expected['formId'] ) ) {
			$this->assertEquals( $expected['formId'], $rawResult['formId'],
				'Bad form ID match' );
			$this->assertSame( $expected['representation'], $rawResult['representation'],
				'Bad representation match' );
			$this->assertArrayEquals( $expected['features'], $rawResult['features'],
				'Bad features match' );
		}

		$results = $converted->extractResults();
		$this->assertCount( 1, $results );

		$result = reset( $results );
		$this->assertInstanceOf( LexemeResult::class, $result );
		/**
		 * @var LexemeResult $result
		 */
		$this->assertSame( $expected['title'], $result->getTitleSnippet(), "Bad title" );
		foreach ( $expected['description_contains'] as $expectedDescriptionWord ) {
			$this->assertStringContainsString( $expectedDescriptionWord, $result->getTextSnippet(),
				"Expected the word [$expectedDescriptionWord] to appear in the description" );
		}
	}

}
