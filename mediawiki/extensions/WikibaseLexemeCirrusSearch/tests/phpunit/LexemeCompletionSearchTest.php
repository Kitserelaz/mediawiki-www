<?php

namespace Wikibase\Lexeme\Search\Elastic\Tests;

use CirrusSearch\CirrusDebugOptions;
use CirrusSearch\CirrusTestCase;
use MediaWiki\Language\Language;
use MediaWiki\Request\FauxRequest;
use Wikibase\DataModel\Entity\BasicEntityIdParser;
use Wikibase\Lexeme\Search\Elastic\FormSearchEntity;
use Wikibase\Lexeme\Search\Elastic\LexemeSearchEntity;
use Wikibase\Repo\WikibaseRepo;

/**
 * @covers \Wikibase\Lexeme\Search\Elastic\LexemeSearchEntity
 */
class LexemeCompletionSearchTest extends \MediaWikiIntegrationTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'LexemeUseCirrus', true );
	}

	/**
	 * @param Language $userLang
	 * @return LexemeSearchEntity
	 */
	private function newEntitySearch( Language $userLang ) {
		return new LexemeSearchEntity(
			new BasicEntityIdParser(),
			new FauxRequest(),
			$userLang,
			WikibaseRepo::getFallbackLabelDescriptionLookupFactory(),
			CirrusDebugOptions::forDumpingQueriesInUnitTests()
		);
	}

	/**
	 * @param Language $userLang
	 * @return LexemeSearchEntity
	 */
	private function newFormSearch( Language $userLang ) {
		return new FormSearchEntity(
			new BasicEntityIdParser(),
			new FauxRequest(),
			$userLang,
			WikibaseRepo::getFallbackLabelDescriptionLookupFactory(),
			CirrusDebugOptions::forDumpingQueriesInUnitTests()
		);
	}

	public static function searchDataProvider() {
		return [
			"simple" => [
				'Duck',
				'simple'
			],
			"byid" => [
				'(L2)',
				'byid'
			],

		];
	}

	/**
	 * @dataProvider searchDataProvider
	 * @param string $term search term
	 * @param string $expected Expected result filename
	 */
	public function testSearchElastic( $term, $expected ) {
		$search = $this->newEntitySearch( $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' ) );
		$elasticQuery = $search->getRankedSearchResults(
			$term,
			'test', /* not used so far */
			'lexeme',
			10,
			false
		);
		$elasticQuery = $elasticQuery['__main__'] ?? $elasticQuery;
		unset( $elasticQuery['path'] );
		// T206100
		$this->setIniSetting( 'serialize_precision', 10 );
		$encodedData = CirrusTestCase::encodeFixture( $elasticQuery );

		$this->assertFileContains(
			__DIR__ . "/../data/lexemeCompletionSearch/$expected.expected",
			$encodedData,
			CirrusTestCase::canRebuildFixture() );
	}

	/**
	 * @dataProvider searchDataProvider
	 * @param string $term search term
	 * @param string $expected Expected result filename
	 */
	public function testSearchFormElastic( $term, $expected ) {
		$search = $this->newFormSearch( $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' ) );
		$elasticQuery = $search->getRankedSearchResults(
			$term,
			'test', /* not used so far */
			'form',
			10,
			false
		);
		$elasticQuery = $elasticQuery['__main__'] ?? $elasticQuery;
		unset( $elasticQuery['path'] );

		// T206100
		$this->setIniSetting( 'serialize_precision', 10 );
		$encodedData = CirrusTestCase::encodeFixture( $elasticQuery );

		$this->assertFileContains(
			__DIR__ . "/../data/lexemeCompletionSearch/$expected.form.expected",
			$encodedData,
			CirrusTestCase::canRebuildFixture() );
	}

}
