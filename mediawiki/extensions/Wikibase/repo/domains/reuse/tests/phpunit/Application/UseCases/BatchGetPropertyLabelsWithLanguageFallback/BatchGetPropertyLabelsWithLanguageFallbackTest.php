<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\Domains\Reuse\Application\UseCases\BatchGetPropertyLabelsWithLanguageFallback;

use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetPropertyLabelsWithLanguageFallback\BatchGetPropertyLabelsWithLanguageFallback;
// phpcs:ignore Generic.Files.LineLength.TooLong
use Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetPropertyLabelsWithLanguageFallback\BatchGetPropertyLabelsWithLanguageFallbackRequest;
use Wikibase\Repo\Domains\Reuse\Domain\Model\PropertyLabelsWithFallbackBatch;
use Wikibase\Repo\Domains\Reuse\Domain\Services\PropertyLabelsWithLanguageFallbackBatchRetriever;

/**
 * @covers \Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetPropertyLabelsWithLanguageFallback\BatchGetPropertyLabelsWithLanguageFallback
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class BatchGetPropertyLabelsWithLanguageFallbackTest extends TestCase {

	public function testExecute(): void {
		$propertyIds = [ 'P1', 'P2' ];
		$languageCodes = [ 'en', 'de' ];
		$expectedBatch = new PropertyLabelsWithFallbackBatch( [] );

		$retriever = $this->createMock( PropertyLabelsWithLanguageFallbackBatchRetriever::class );
		$retriever->expects( $this->once() )
			->method( 'getPropertyLabelsWithLanguageFallback' )
			->with(
				[ new NumericPropertyId( 'P1' ), new NumericPropertyId( 'P2' ) ],
				$languageCodes
			)
			->willReturn( $expectedBatch );

		$response = ( new BatchGetPropertyLabelsWithLanguageFallback( $retriever ) )
			->execute( new BatchGetPropertyLabelsWithLanguageFallbackRequest( $propertyIds, $languageCodes ) );

		$this->assertSame( $expectedBatch, $response->batch );
	}

}
