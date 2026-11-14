<?php

namespace Wikibase\Client\Tests\Unit\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiPageSet;
use MediaWiki\Api\ApiResult;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Wikibase\Client\Api\Description;
use Wikibase\Client\Store\DescriptionLookup;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \Wikibase\Client\Api\Description
 *
 * @group API
 * @group Wikibase
 * @group WikibaseAPI
 * @group WikibaseClient
 *
 * @license GPL-2.0-or-later
 */
class DescriptionTest extends MediaWikiIntegrationTestCase {

	/**
	 * @var array[] page id => data
	 */
	private $resultData;

	/**
	 * @var int
	 */
	private $continueEnumParameter;

	protected function setUp(): void {
		parent::setUp();
		$this->resultData = [];
		$this->continueEnumParameter = null;
	}

	/**
	 * @dataProvider provideExecute
	 * @param bool $allowLocalShortDesc
	 * @param bool $forceLocalShortDesc
	 * @param array $params
	 * @param array $requestedPageIds
	 * @param int|null $fitLimit
	 * @param array $expectedPageIds
	 * @param array $expectedSources
	 * @param array $descriptions
	 * @param array $actualSources
	 * @param array $expectedResult
	 * @param int $expectedContinue
	 */
	public function testExecute(
		bool $allowLocalShortDesc,
		bool $forceLocalShortDesc,
		array $params,
		array $requestedPageIds,
		?int $fitLimit,
		array $expectedPageIds,
		array $expectedSources,
		array $descriptions,
		array $actualSources,
		array $expectedResult,
		$expectedContinue
	) {
		$descriptionLookup = $this->getDescriptionLookup( $expectedPageIds, $expectedSources,
			$descriptions, $actualSources );
		$module = $this->getModule( $allowLocalShortDesc, $forceLocalShortDesc, $params,
			$requestedPageIds, $fitLimit, $descriptionLookup );
		$module->execute();
		$this->assertSame( $expectedResult, $this->resultData );
		$this->assertSame( $expectedContinue, $this->continueEnumParameter );
	}

	public static function provideExecute() {
		$local = DescriptionLookup::SOURCE_LOCAL;
		$central = DescriptionLookup::SOURCE_CENTRAL;
		return [
			'empty' => [
				'allowLocalShortDesc' => false,
				'forceLocalShortDesc' => false,
				'params' => [],
				'requestedPageIds' => [],
				'fitLimit' => null,
				'expectedPageIds' => [],
				'expectedSources' => [ $central ],
				'descriptions' => [],
				'actualSources' => [],
				'expectedResult' => [],
				'expectedContinue' => null,
			],
			'local disallowed' => [
				'allowLocalShortDesc' => false,
				'forceLocalShortDesc' => false,
				'params' => [],
				'requestedPageIds' => [ 1, 2, 3, 4, 5 ],
				'fitLimit' => null,
				'expectedPageIds' => [ 1, 2, 3, 4, 5 ],
				'expectedSources' => [ $central ],
				'descriptions' => [ 3 => 'C3', 4 => 'C4' ],
				'actualSources' => [ 3 => $central, 4 => $central ],
				'expectedResult' => [
					3 => [ 'description' => 'C3', 'descriptionsource' => 'central' ],
					4 => [ 'description' => 'C4', 'descriptionsource' => 'central' ],
				],
				'expectedContinue' => null,
			],
			'prefer local' => [
				'allowLocalShortDesc' => true,
				'forceLocalShortDesc' => false,
				'params' => [],
				'requestedPageIds' => [ 1, 2, 3, 4, 5, 6 ],
				'fitLimit' => null,
				'expectedPageIds' => [ 1, 2, 3, 4, 5, 6 ],
				'expectedSources' => [ $local, $central ],
				'descriptions' => [ 1 => 'L1', 2 => 'L2', 3 => 'L3', 4 => 'C4' ],
				'actualSources' => [ 1 => $local, 2 => $local, 3 => $local, 4 => $central ],
				'expectedResult' => [
					1 => [ 'description' => 'L1', 'descriptionsource' => 'local' ],
					2 => [ 'description' => 'L2', 'descriptionsource' => 'local' ],
					3 => [ 'description' => 'L3', 'descriptionsource' => 'local' ],
					4 => [ 'description' => 'C4', 'descriptionsource' => 'central' ],
				],
				'expectedContinue' => null,
			],
			'force local' => [
				'allowLocalShortDesc' => true,
				'forceLocalShortDesc' => true,
				'params' => [],
				'requestedPageIds' => [ 1, 2, 3, 4, 5, 6 ],
				'fitLimit' => null,
				'expectedPageIds' => [ 1, 2, 3, 4, 5, 6 ],
				'expectedSources' => [ $local ],
				'descriptions' => [ 1 => 'L1', 2 => 'L2', 3 => 'L3' ],
				'actualSources' => [ 1 => $local, 2 => $local, 3 => $local ],
				'expectedResult' => [
					1 => [ 'description' => 'L1', 'descriptionsource' => 'local' ],
					2 => [ 'description' => 'L2', 'descriptionsource' => 'local' ],
					3 => [ 'description' => 'L3', 'descriptionsource' => 'local' ],
				],
				'expectedContinue' => null,
			],
			'prefer central' => [
				'allowLocalShortDesc' => true,
				'forceLocalShortDesc' => false,
				'params' => [ 'prefersource' => 'central' ],
				'requestedPageIds' => [ 1, 2, 3, 4, 5, 6 ],
				'fitLimit' => null,
				'expectedPageIds' => [ 1, 2, 3, 4, 5, 6 ],
				'expectedSources' => [ $central, $local ],
				'descriptions' => [ 1 => 'L1', 2 => 'L2', 3 => 'C3', 4 => 'C4' ],
				'actualSources' => [ 1 => $local, 2 => $local, 3 => $central, 4 => $central ],
				'expectedResult' => [
					1 => [ 'description' => 'L1', 'descriptionsource' => 'local' ],
					2 => [ 'description' => 'L2', 'descriptionsource' => 'local' ],
					3 => [ 'description' => 'C3', 'descriptionsource' => 'central' ],
					4 => [ 'description' => 'C4', 'descriptionsource' => 'central' ],
				],
				'expectedContinue' => null,
			],
			'continuation #1' => [
				'allowLocalShortDesc' => true,
				'forceLocalShortDesc' => false,
				'params' => [],
				'requestedPageIds' => [ 1, 2, 3, 4, 5 ],
				'fitLimit' => 2,
				'expectedPageIds' => [ 1, 2, 3, 4, 5 ],
				'expectedSources' => [ $local, $central ],
				'descriptions' => [ 1 => 'L1', 2 => 'L2', 3 => 'L3', 4 => 'C4', 5 => 'C5' ],
				'actualSources' => [ 1 => $local, 2 => $local, 3 => $local, 4 => $central,
					5 => $central ],
				'expectedResult' => [
					1 => [ 'description' => 'L1', 'descriptionsource' => 'local' ],
					2 => [ 'description' => 'L2', 'descriptionsource' => 'local' ],
				],
				'expectedContinue' => 2,
			],
			'continuation #2' => [
				'allowLocalShortDesc' => true,
				'forceLocalShortDesc' => false,
				'params' => [ 'continue' => 2 ],
				'requestedPageIds' => [ 1, 2, 3, 4, 5 ],
				'fitLimit' => 2,
				'expectedPageIds' => [ 3, 4, 5 ],
				'expectedSources' => [ $local, $central ],
				'descriptions' => [ 3 => 'L3', 4 => 'C4', 5 => 'C5' ],
				'actualSources' => [ 3 => $local, 4 => $central, 5 => $central ],
				'expectedResult' => [
					3 => [ 'description' => 'L3', 'descriptionsource' => 'local' ],
					4 => [ 'description' => 'C4', 'descriptionsource' => 'central' ],
				],
				'expectedContinue' => 4,
			],
			'continuation #3' => [
				'allowLocalShortDesc' => true,
				'forceLocalShortDesc' => false,
				'params' => [ 'continue' => 4 ],
				'requestedPageIds' => [ 1, 2, 3, 4, 5 ],
				'fitLimit' => 2,
				'expectedPageIds' => [ 5 ],
				'expectedSources' => [ $local, $central ],
				'descriptions' => [ 5 => 'C5' ],
				'actualSources' => [ 5 => $central ],
				'expectedResult' => [
					5 => [ 'description' => 'C5', 'descriptionsource' => 'central' ],
				],
				'expectedContinue' => null,
			],
			'continuation with exact fit' => [
				'allowLocalShortDesc' => true,
				'forceLocalShortDesc' => false,
				'params' => [ 'continue' => 2 ],
				'requestedPageIds' => [ 1, 2, 3, 4 ],
				'fitLimit' => 2,
				'expectedPageIds' => [ 3, 4 ],
				'expectedSources' => [ $local, $central ],
				'descriptions' => [ 3 => 'L3', 4 => 'C4' ],
				'actualSources' => [ 3 => $local, 4 => $central ],
				'expectedResult' => [
					3 => [ 'description' => 'L3', 'descriptionsource' => 'local' ],
					4 => [ 'description' => 'C4', 'descriptionsource' => 'central' ],
				],
				'expectedContinue' => null,
			],
			'limit' => [
				'allowLocalShortDesc' => true,
				'forceLocalShortDesc' => false,
				'params' => [],
				'requestedPageIds' => range( 1, 600 ),
				'fitLimit' => null,
				'expectedPageIds' => range( 1, 500 ),
				'expectedSources' => [ $local, $central ],
				'descriptions' => array_fill( 1, 500, 'LX' ),
				'actualSources' => array_fill( 1, 500, $local ),
				'expectedResult' => array_fill( 1, 500, [ 'description' => 'LX',
					'descriptionsource' => 'local' ] ),
				'expectedContinue' => 500,
			],
		];
	}

	/**
	 * Mock description lookup.
	 * @param int[] $expectedPageIds
	 * @param string[] $expectedSources
	 * @param array $descriptionsToReturn page ID => description text
	 * @param string[] $sourcesToReturn page ID => DescriptionLookup::SOURCE_*
	 * @return MockObject|DescriptionLookup
	 */
	private function getDescriptionLookup(
		$expectedPageIds,
		$expectedSources,
		$descriptionsToReturn,
		$sourcesToReturn
	) {
		$descriptionLookup = $this->createMock( DescriptionLookup::class );
		$descriptionLookup->expects( $this->once() )
			->method( 'getDescriptions' )
			->willReturnCallback( function ( $titles, $sources, &$actualSources )
				use ( $expectedPageIds, $expectedSources, $descriptionsToReturn, $sourcesToReturn ) {
					/** @var Title[] $titles */
					/** @var string|string[] $sourcesToReturn */
					$pageIds = array_values( array_map( function ( Title $title ) {
						return $title->getArticleID();
					}, $titles ) );
					// Should be a sort-insensitive check but everything is sorted anyway.
					$this->assertSame( $expectedPageIds, $pageIds );
					$this->assertSame( $expectedSources, (array)$sources );
					$actualSources = $sourcesToReturn;
					return $descriptionsToReturn;
			} );
		return $descriptionLookup;
	}

	/**
	 * Create the module, mock ApiBase methods and other API dependencies, have the
	 * mock write results and continuation value into member variables of the test for inspection.
	 *
	 * @param bool $allowLocalShortDesc
	 * @param bool $forceLocalShortDesc
	 * @param array $params API parameters for the module (unprefixed)
	 * @param array $requestedPageIds
	 * @param int|null $fitLimit
	 * @param DescriptionLookup $descriptionLookup
	 * @return MockObject
	 */
	private function getModule(
		bool $allowLocalShortDesc,
		bool $forceLocalShortDesc,
		array $params,
		array $requestedPageIds,
		?int $fitLimit,
		DescriptionLookup $descriptionLookup
	) {
		$main = $this->createMock( ApiMain::class );
		$main->method( 'canApiHighLimits' )
			->willReturn( false );

		$pageSet = $this->createMock( ApiPageSet::class );
		$pageSet->method( 'getGoodPages' )
			->willReturn( $this->makeTitles( $requestedPageIds ) );

		$result = $this->createMock( ApiResult::class );
		$result->method( 'addValue' )
			->willReturnCallback( function ( $path, $name, $value ) use ( $fitLimit ) {
				static $fitCount = 0;
				if ( $name === 'description' ) {
					$fitCount++;
				}
				if ( $fitLimit && $fitCount > $fitLimit ) {
					return false;
				}
				$this->assertIsArray( $path );
				$this->assertSame( 'query', $path[0] );
				$this->assertSame( 'pages', $path[1] );
				$this->resultData[$path[2]][$name] = $value;
				return true;
			} );

		$module = $this->getMockBuilder( Description::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getParameter', 'getPageSet', 'getMain',
							'setContinueEnumParameter', 'getResult' ] )
			->getMock();
		$modulePrivate = TestingAccessWrapper::newFromObject( $module );
		$modulePrivate->allowLocalShortDesc = $allowLocalShortDesc;
		$modulePrivate->forceLocalShortDesc = $forceLocalShortDesc;
		$modulePrivate->descriptionLookup = $descriptionLookup;
		$module->method( 'getParameter' )
			->willReturnCallback( function ( $name ) use ( $params ) {
				$finalParams = $params + [
					'continue' => 0,
					'prefersource' => 'local',
				];
				$this->assertArrayHasKey( $name, $finalParams );
				return $finalParams[$name];
			} );
		$module->method( 'getPageSet' )
			->willReturn( $pageSet );
		$module->method( 'getMain' )
			->willReturn( $main );
		$module->method( 'setContinueEnumParameter' )
			->with( 'continue', $this->anything() )
			->willReturnCallback( function ( $_, $continue ) {
				$this->continueEnumParameter = $continue;
			} );
		$module->method( 'getResult' )
			->willReturn( $result );

		return $module;
	}

	/**
	 * @param int[] $requestedPageIds
	 *
	 * @return Title[] page id => Title
	 */
	private function makeTitles( $requestedPageIds ) {
		$en = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' );
		return array_map( function ( $pageId ) use ( $en ) {
			$title = $this->createMock( Title::class );
			$title->method( 'getArticleID' )
				->willReturn( $pageId );
			$title->method( 'getPageLanguage' )
				->willReturn( $en );
			return $title;
		}, array_combine( $requestedPageIds, $requestedPageIds ) );
	}

}
