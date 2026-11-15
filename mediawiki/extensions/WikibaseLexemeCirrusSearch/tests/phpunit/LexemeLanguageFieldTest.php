<?php

namespace Wikibase\Lexeme\Search\Elastic\Tests;

use DataValues\StringValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\DataModel\Statement\StatementList;
use Wikibase\Lexeme\Search\Elastic\LexemeLanguageField;

/**
 * @covers \Wikibase\Lexeme\Search\Elastic\LexemeLanguageField
 */
class LexemeLanguageFieldTest extends LexemeFieldTestBase {

	/**
	 * @param NumericPropertyId $propId
	 * @param string $code
	 * @return StatementList
	 */
	private static function getStatList( NumericPropertyId $propId, $code ) {
		$statList = new StatementList();
		$statList->addStatement( new Statement( new PropertyValueSnak( $propId,
			new StringValue( $code ) ) ) );
		return $statList;
	}

	/**
	 * @return EntityLookup
	 */
	private function getEntityLookup( ?StatementList $statList ) {
		$lookup = $this->createMock( EntityLookup::class );
		if ( $statList !== null ) {
			$langEntity = $this->createMock( Item::class );
			$langEntity->method( 'getStatements' )->willReturn( $statList );

			$lookup->method( 'getEntity' )
				->with( new ItemId( self::LANGUAGE_ID ) )
				->willReturn( $langEntity );
		}
		return $lookup;
	}

	/** @dataProvider provideTestData */
	public function testLemmaField( $fieldSpec, $expected ) {
		$field = new LexemeLanguageField( $this->getEntityLookup( $fieldSpec[0] ), $fieldSpec[1] );
		parent::testLemmaField( $field, $expected );
	}

	/**
	 * @return array
	 */
	public static function provideTestData() {
		$propId = new NumericPropertyId( 'P42' );

		return [
			'no property id' => [
				[ null, null ],
				[
					'entity' => self::LANGUAGE_ID,
					'code' => null
				]
			],
			'no entity' => [
				[ null, $propId ],
				[
					'entity' => self::LANGUAGE_ID,
					'code' => null
				]
			],
			'with property id' => [
				[ self::getStatList( $propId, 'fr' ), $propId ],
				[
					'entity' => self::LANGUAGE_ID,
					'code' => 'fr'
				]
			],
			'with property id, no statement' => [
				[ new StatementList(), $propId ],
				[
					'entity' => self::LANGUAGE_ID,
					'code' => null
				]
			],
		];
	}

}
