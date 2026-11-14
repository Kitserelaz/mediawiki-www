<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\Domains\Reuse\Domain\Services;

use PHPUnit\Framework\TestCase;
use Wikibase\DataAccess\Tests\InMemoryPrefetchingTermLookup;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Term\Fingerprint;
use Wikibase\DataModel\Term\Term;
use Wikibase\DataModel\Term\TermList;
use Wikibase\Repo\Domains\Reuse\Domain\Model\ItemLabelsWithFallbackBatch;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Label;
use Wikibase\Repo\Domains\Reuse\Domain\Services\ItemLabelsWithLanguageFallbackBatchRetriever;
use Wikibase\Repo\Domains\Reuse\Domain\Services\LanguageFallbackLabelSelector;
use Wikibase\Repo\Domains\Reuse\Infrastructure\DataAccess\LanguageFallbackChainFactoryFallbackLanguagesProvider;
use Wikibase\Repo\Domains\Reuse\Infrastructure\DataAccess\PrefetchingTermLookupBatchLabelsDescriptionsRetriever;
use Wikibase\Repo\WikibaseRepo;

/**
 * @covers \Wikibase\Repo\Domains\Reuse\Domain\Services\ItemLabelsWithLanguageFallbackBatchRetriever
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class ItemLabelsWithLanguageFallbackBatchRetrieverTest extends TestCase {

	public function testFetchesLabelsForExpandedLanguagesAndAssemblesResultBatch(): void {
		$q1 = new ItemId( 'Q1' );
		$q2 = new ItemId( 'Q2' );
		$enLabel = new Label( 'en', 'coffee' );
		$deLabel = new Label( 'de', 'kaffee' );
		$enLabel2 = new Label( 'en', 'tea' );

		$lookup = new InMemoryPrefetchingTermLookup();
		$lookup->setData( [
			new Item( $q1, new Fingerprint(
				new TermList( [ new Term( 'en', 'coffee' ), new Term( 'de', 'kaffee' ) ] )
			) ),
			new Item( $q2, new Fingerprint( new TermList( [ new Term( 'en', 'tea' ) ] ) ) ),
		] );

		$result = $this->newRetriever( $lookup )
			->getItemLabelsWithLanguageFallback( [ $q1, $q2 ], [ 'de', 'en' ] );

		$this->assertEquals(
			new ItemLabelsWithFallbackBatch( [
				'Q1' => [ 'de' => $deLabel, 'en' => $enLabel ],
				'Q2' => [ 'de' => $enLabel2, 'en' => $enLabel2 ],
			] ),
			$result
		);
	}

	private function newRetriever(
		InMemoryPrefetchingTermLookup $lookup,
	): ItemLabelsWithLanguageFallbackBatchRetriever {
		$languageFallbackChainProvider = new LanguageFallbackChainFactoryFallbackLanguagesProvider(
			WikibaseRepo::getLanguageFallbackChainFactory()
		);

		return new ItemLabelsWithLanguageFallbackBatchRetriever(
			new PrefetchingTermLookupBatchLabelsDescriptionsRetriever( $lookup ),
			$languageFallbackChainProvider,
			new LanguageFallbackLabelSelector( $languageFallbackChainProvider ),
		);
	}

}
