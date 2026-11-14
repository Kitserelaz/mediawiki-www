<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\Domains\Reuse\Infrastructure\GraphQL\Resolvers;

use GraphQL\Executor\Promise\Adapter\SyncPromiseQueue;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetPropertyLabelsWithLanguageFallback\BatchGetPropertyLabelsWithLanguageFallback;
// phpcs:ignore Generic.Files.LineLength.TooLong
use Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetPropertyLabelsWithLanguageFallback\BatchGetPropertyLabelsWithLanguageFallbackRequest;
// phpcs:ignore Generic.Files.LineLength.TooLong
use Wikibase\Repo\Domains\Reuse\Application\UseCases\BatchGetPropertyLabelsWithLanguageFallback\BatchGetPropertyLabelsWithLanguageFallbackResponse;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Label;
use Wikibase\Repo\Domains\Reuse\Domain\Model\PropertyLabelsWithFallbackBatch;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\PropertyLabelsWithLanguageFallbackResolver;

/**
 * @covers \Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\PropertyLabelsWithLanguageFallbackResolver
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class PropertyLabelsWithLanguageFallbackResolverTest extends TestCase {

	public function testResolve(): void {
		$requestedProperties = [ new NumericPropertyId( 'P123' ), new NumericPropertyId( 'P321' ) ];
		$requestedPropertyIdSerializations = array_map( fn( $id ) => (string)$id, $requestedProperties );
		$requestedLanguages = [ 'de', 'en' ];
		$labelsBatch = $this->newLabelsBatch( $requestedProperties, $requestedLanguages );

		$batchGetPropertyLabels = $this->createMock( BatchGetPropertyLabelsWithLanguageFallback::class );
		// expecting the use case to only be called once demonstrates that the resolver aggregates multiple requests into one batch
		$batchGetPropertyLabels->expects( $this->once() )
			->method( 'execute' )
			->with( new BatchGetPropertyLabelsWithLanguageFallbackRequest( $requestedPropertyIdSerializations, $requestedLanguages ) )
			->willReturn( new BatchGetPropertyLabelsWithLanguageFallbackResponse( $labelsBatch ) );

		$resolver = new PropertyLabelsWithLanguageFallbackResolver( $batchGetPropertyLabels );

		$promise1 = $resolver->resolve( $requestedProperties[0], $requestedLanguages[0] );
		$promise2 = $resolver->resolve( $requestedProperties[0], $requestedLanguages[1] );
		$promise3 = $resolver->resolve( $requestedProperties[1], $requestedLanguages[0] );
		$promise4 = $resolver->resolve( $requestedProperties[1], $requestedLanguages[1] );

		SyncPromiseQueue::run(); // resolves the promises above

		$this->assertSame( $labelsBatch->getLabel( $requestedProperties[0], $requestedLanguages[0] ), $promise1->result );
		$this->assertSame( $labelsBatch->getLabel( $requestedProperties[0], $requestedLanguages[1] ), $promise2->result );
		$this->assertSame( $labelsBatch->getLabel( $requestedProperties[1], $requestedLanguages[0] ), $promise3->result );
		$this->assertSame( $labelsBatch->getLabel( $requestedProperties[1], $requestedLanguages[1] ), $promise4->result );
	}

	public function testResolveReturnsNullWhenNoLabelFound(): void {
		$propertyId = new NumericPropertyId( 'P1' );
		$languageCode = 'de';
		$emptyBatch = new PropertyLabelsWithFallbackBatch( [] );

		$batchGetPropertyLabels = $this->createMock( BatchGetPropertyLabelsWithLanguageFallback::class );
		$batchGetPropertyLabels->method( 'execute' )
			->willReturn( new BatchGetPropertyLabelsWithLanguageFallbackResponse( $emptyBatch ) );

		$resolver = new PropertyLabelsWithLanguageFallbackResolver( $batchGetPropertyLabels );
		$promise = $resolver->resolve( $propertyId, $languageCode );

		SyncPromiseQueue::run();

		$this->assertNull( $promise->result );
	}

	private function newLabelsBatch( array $propertyIds, array $languageCodes ): PropertyLabelsWithFallbackBatch {
		$batch = [];
		foreach ( $propertyIds as $id ) {
			foreach ( $languageCodes as $languageCode ) {
				$batch[$id->getSerialization()][$languageCode] = new Label( $languageCode, "$languageCode label " . rand() );
			}
		}

		return new PropertyLabelsWithFallbackBatch( $batch );
	}
}
