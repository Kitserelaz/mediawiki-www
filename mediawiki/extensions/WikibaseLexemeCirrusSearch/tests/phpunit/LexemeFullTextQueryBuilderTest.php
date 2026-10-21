<?php

namespace Wikibase\Lexeme\Search\Elastic\Tests;

use CirrusSearch\CirrusDebugOptions;
use CirrusSearch\CirrusSearch;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\SearchConfig;
use MediaWiki\Config\ConfigException;
use MediaWikiIntegrationTestCase;
use Wikibase\Lexeme\Search\Elastic\LexemeFullTextQueryBuilder;
use Wikibase\Lexeme\Tests\MediaWiki\LexemeDescriptionTestCase;

/**
 * @covers \Wikibase\Lexeme\Search\Elastic\LexemeFullTextQueryBuilder
 */
class LexemeFullTextQueryBuilderTest extends MediaWikiIntegrationTestCase {
	use LexemeDescriptionTestCase;

	/**
	 * @var array search settings for the test
	 */
	private static $ENTITY_SEARCH_CONFIG = [
		'defaultFulltextRescoreProfile' => 'lexeme_fulltext',
	];

	public function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'LexemeUseCirrus', true );
	}

	public static function searchDataProvider() {
		return [
			"work" => [
				"duck",
				__DIR__ . '/../data/lexemeFulltextSearch/simple.expected'
					],
			"id" => [
				' L2-F1 ',
				__DIR__ . '/../data/lexemeFulltextSearch/id.expected'
			]
		];
	}

	/**
	 * @dataProvider searchDataProvider
	 * @param string $searchString
	 * @param string $expected
	 * @throws ConfigException
	 */
	public function testSearchElastic( $searchString, $expected ) {
		$this->overrideConfigValues( [
			'LexemeFulltextRescoreProfile' => 'lexeme_fulltext',
		] );

		$config = new SearchConfig();
		$cirrus = new CirrusSearch( $config, CirrusDebugOptions::forDumpingQueriesInUnitTests() );
		$cirrus->setNamespaces( [ 146 ] );
		$result = $cirrus->searchText( $searchString )->getValue();
		$this->assertStringStartsWith(
			LexemeFullTextQueryBuilder::LEXEME_FULL_TEXT_MARKER, $result['__main__']['description']
		);

		$actual = CirrusTestCase::encodeFixture( [
			'query' => $result['__main__']['query']['query'],
			'rescore_query' => $result['__main__']['query']['rescore'],
			'highlight' => $result['__main__']['query']['highlight'],
		] );

		$this->assertFileContains( $expected, $actual, CirrusTestCase::canRebuildFixture() );
	}

}
