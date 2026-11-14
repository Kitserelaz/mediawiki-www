<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\Domains\Reuse\Application\UseCases\BatchGetItemLabelsWithLanguageFallback;

use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetItemLabelsWithLanguageFallback\BatchGetItemLabelsWithLanguageFallback;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetItemLabelsWithLanguageFallback\BatchGetItemLabelsWithLanguageFallbackRequest;
use Wikibase\Repo\Domains\Reuse\Domain\Model\ItemLabelsWithFallbackBatch;
use Wikibase\Repo\Domains\Reuse\Domain\Services\ItemLabelsWithLanguageFallbackBatchRetriever;

/**
 * @covers \Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetItemLabelsWithLanguageFallback\BatchGetItemLabelsWithLanguageFallback
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class BatchGetItemLabelsWithLanguageFallbackTest extends TestCase {

	public function testExecute(): void {
		$itemIds = [ 'Q1', 'Q2' ];
		$languageCodes = [ 'en', 'de' ];
		$expectedBatch = new ItemLabelsWithFallbackBatch( [] );

		$retriever = $this->createMock( ItemLabelsWithLanguageFallbackBatchRetriever::class );
		$retriever->expects( $this->once() )
			->method( 'getItemLabelsWithLanguageFallback' )
			->with(
				[ new ItemId( 'Q1' ), new ItemId( 'Q2' ) ],
				$languageCodes
			)
			->willReturn( $expectedBatch );

		$response = ( new BatchGetItemLabelsWithLanguageFallback( $retriever ) )
			->execute( new BatchGetItemLabelsWithLanguageFallbackRequest( $itemIds, $languageCodes ) );

		$this->assertSame( $expectedBatch, $response->batch );
	}
}
