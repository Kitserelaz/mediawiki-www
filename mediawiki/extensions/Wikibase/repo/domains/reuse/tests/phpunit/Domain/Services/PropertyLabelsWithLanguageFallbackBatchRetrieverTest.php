<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\Domains\Reuse\Domain\Services;

use PHPUnit\Framework\TestCase;
use Wikibase\DataAccess\Tests\InMemoryPrefetchingTermLookup;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Entity\Property;
use Wikibase\DataModel\Term\Fingerprint;
use Wikibase\DataModel\Term\Term;
use Wikibase\DataModel\Term\TermList;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Label;
use Wikibase\Repo\Domains\Reuse\Domain\Model\PropertyLabelsWithFallbackBatch;
use Wikibase\Repo\Domains\Reuse\Domain\Services\LanguageFallbackLabelSelector;
use Wikibase\Repo\Domains\Reuse\Domain\Services\PropertyLabelsWithLanguageFallbackBatchRetriever;
use Wikibase\Repo\Domains\Reuse\Infrastructure\DataAccess\LanguageFallbackChainFactoryFallbackLanguagesProvider;
use Wikibase\Repo\Domains\Reuse\Infrastructure\DataAccess\PrefetchingTermLookupBatchLabelsDescriptionsRetriever;
use Wikibase\Repo\WikibaseRepo;

/**
 * @covers \Wikibase\Repo\Domains\Reuse\Domain\Services\PropertyLabelsWithLanguageFallbackBatchRetriever
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class PropertyLabelsWithLanguageFallbackBatchRetrieverTest extends TestCase {

	public function testFetchesLabelsForExpandedLanguagesAndAssemblesResultBatch(): void {
		$p1 = new NumericPropertyId( 'P1' );
		$p2 = new NumericPropertyId( 'P2' );
		$enLabel = new Label( 'en', 'instance of' );
		$deLabel = new Label( 'de', 'ist ein(e)' );
		$enLabel2 = new Label( 'en', 'subclass of' );

		$lookup = new InMemoryPrefetchingTermLookup();
		$lookup->setData( [
			new Property(
				$p1,
				new Fingerprint(
					new TermList( [
						new Term( 'en', 'instance of' ),
						new Term( 'de', 'ist ein(e)' ),
					] )
				),
				'string'
			),
			new Property( $p2, new Fingerprint( new TermList( [ new Term( 'en', 'subclass of' ) ] ) ), 'string' ),
		] );

		$result = $this->newRetriever( $lookup )
			->getPropertyLabelsWithLanguageFallback( [ $p1, $p2 ], [ 'de', 'en', 'ar' ] );

		$this->assertEquals(
			new PropertyLabelsWithFallbackBatch( [
				'P1' => [ 'de' => $deLabel, 'en' => $enLabel, 'ar' => $enLabel ],
				'P2' => [ 'de' => $enLabel2, 'en' => $enLabel2, 'ar' => $enLabel2 ],
			] ),
			$result
		);
	}

	private function newRetriever(
		InMemoryPrefetchingTermLookup $lookup,
	): PropertyLabelsWithLanguageFallbackBatchRetriever {
		$languageFallbackChainProvider = new LanguageFallbackChainFactoryFallbackLanguagesProvider(
			WikibaseRepo::getLanguageFallbackChainFactory()
		);

		return new PropertyLabelsWithLanguageFallbackBatchRetriever(
			new PrefetchingTermLookupBatchLabelsDescriptionsRetriever( $lookup ),
			$languageFallbackChainProvider,
			new LanguageFallbackLabelSelector( $languageFallbackChainProvider ),
		);
	}

}
