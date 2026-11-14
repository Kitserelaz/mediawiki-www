<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\Domains\Reuse\Application\UseCases\LookUpItemByExternalId;

use Generator;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Services\Lookup\InMemoryDataTypeLookup;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemByExternalId\LookUpItemByExternalIdRequest;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemByExternalId\LookUpItemByExternalIdValidator;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\UseCaseError;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\UseCaseErrorType;

/**
 * @covers \Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemByExternalId\LookUpItemByExternalIdValidator
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class LookUpItemByExternalIdValidatorTest extends TestCase {

	public function testGivenExternalIdProperty_doesNotThrow(): void {
		$dataTypeLookup = new InMemoryDataTypeLookup();
		$dataTypeLookup->setDataTypeForProperty( new NumericPropertyId( 'P31' ), 'external-id' );

		$this->expectNotToPerformAssertions();
		( new LookUpItemByExternalIdValidator( $dataTypeLookup ) )
			->validate( new LookUpItemByExternalIdRequest( new NumericPropertyId( 'P31' ), 'some value' ) );
	}

	/**
	 * @dataProvider invalidPropertyProvider
	 */
	public function testGivenInvalidProperty_throwsUseCaseError( InMemoryDataTypeLookup $dataTypeLookup, string $propertyId ): void {
		try {
			( new LookUpItemByExternalIdValidator( $dataTypeLookup ) )
				->validate( new LookUpItemByExternalIdRequest( new NumericPropertyId( $propertyId ), 'some-value' ) );
			$this->fail( 'Expected UseCaseError was not thrown' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( UseCaseErrorType::INVALID_EXTERNAL_ID_PROPERTY, $e->type );
			$this->assertStringContainsString( $propertyId, $e->getMessage() );
		}
	}

	public static function invalidPropertyProvider(): Generator {
		$lookupWithWrongType = new InMemoryDataTypeLookup();
		$lookupWithWrongType->setDataTypeForProperty( new NumericPropertyId( 'P31' ), 'string' );
		yield 'non-external-id property type' => [ $lookupWithWrongType, 'P31' ];

		yield 'non-existent property' => [ new InMemoryDataTypeLookup(), 'P99' ];
	}

}
