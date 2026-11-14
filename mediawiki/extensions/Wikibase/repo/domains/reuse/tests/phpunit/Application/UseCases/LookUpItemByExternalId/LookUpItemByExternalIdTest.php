<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\Domains\Reuse\Application\UseCases\LookUpItemByExternalId;

use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemByExternalId\LookUpItemByExternalId;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemByExternalId\LookUpItemByExternalIdRequest;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemByExternalId\LookUpItemByExternalIdValidator;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\UseCaseError;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\UseCaseErrorType;
use Wikibase\Repo\Domains\Reuse\Domain\Services\ItemByExternalIdLookup;

/**
 * @covers \Wikibase\Repo\Domains\Reuse\Application\UseCases\LookUpItemByExternalId\LookUpItemByExternalId
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class LookUpItemByExternalIdTest extends TestCase {

	public function testSuccess(): void {
		$propertyId = new NumericPropertyId( 'P31' );
		$externalId = 'some-external-id';
		$itemIds = [ 'Q42' ];

		$request = new LookUpItemByExternalIdRequest( $propertyId, $externalId );
		$validator = $this->createMock( LookUpItemByExternalIdValidator::class );
		$validator->expects( $this->once() )
			->method( 'validate' )
			->with( $request );

		$lookup = $this->createMock( ItemByExternalIdLookup::class );
		$lookup->expects( $this->once() )
			->method( 'lookupByExternalId' )
			->with( $propertyId, $externalId )
			->willReturn( $itemIds );

		$response = ( new LookUpItemByExternalId( $validator, $lookup ) )
			->execute( $request );

		$this->assertSame( $itemIds, $response->itemIds );
	}

	public function testGivenInvalidProperty_throwsUseCaseErrorAndDoesNotCallLookup(): void {
		$request = new LookUpItemByExternalIdRequest( new NumericPropertyId( 'P99' ), 'some-id' );
		$error = new UseCaseError( UseCaseErrorType::INVALID_EXTERNAL_ID_PROPERTY, 'some error' );

		$validator = $this->createMock( LookUpItemByExternalIdValidator::class );
		$validator->expects( $this->once() )
			->method( 'validate' )
			->with( $request )
			->willThrowException( $error );

		$lookup = $this->createMock( ItemByExternalIdLookup::class );
		$lookup->expects( $this->never() )->method( 'lookupByExternalId' );

		$this->expectExceptionObject( $error );
		( new LookUpItemByExternalId( $validator, $lookup ) )->execute( $request );
	}
}
