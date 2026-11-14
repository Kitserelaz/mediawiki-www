<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\Domains\Reuse\Domain\Services;

use PHPUnit\Framework\TestCase;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Label;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Labels;
use Wikibase\Repo\Domains\Reuse\Domain\Services\LanguageFallbackLabelSelector;
use Wikibase\Repo\Domains\Reuse\Infrastructure\DataAccess\LanguageFallbackChainFactoryFallbackLanguagesProvider;
use Wikibase\Repo\WikibaseRepo;

/**
 * @covers \Wikibase\Repo\Domains\Reuse\Domain\Services\LanguageFallbackLabelSelector
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class LanguageFallbackLabelSelectorTest extends TestCase {

	public function testReturnsExactMatchWhenAvailable(): void {
		$labels = new Labels( new Label( 'de', 'Beispiel' ), new Label( 'en', 'example' ) );

		$result = $this->newSelector()->selectLabel( 'de', $labels );

		$this->assertEquals( new Label( 'de', 'Beispiel' ), $result );
	}

	public function testReturnsFallbackLabelWhenNoExactMatchExists(): void {
		$labels = new Labels( new Label( 'en', 'example' ) );

		$result = $this->newSelector()->selectLabel( 'de', $labels );

		$this->assertEquals( new Label( 'en', 'example' ), $result );
	}

	public function testReturnsNullWhenNoLabelInFallbackChain(): void {
		$labels = new Labels( new Label( 'ar', 'بطاطا' ) );

		$result = $this->newSelector()->selectLabel( 'de', $labels );

		$this->assertNull( $result );
	}

	public function testReturnsBestMatchInFallbackOrder(): void {
		// de fallback chain: de, en, fr — both en and fr are present, en should win
		$labels = new Labels( new Label( 'fr', 'exemple' ), new Label( 'en', 'example' ) );

		$result = $this->newSelector()->selectLabel( 'de', $labels );

		$this->assertEquals( new Label( 'en', 'example' ), $result );
	}

	private function newSelector(): LanguageFallbackLabelSelector {
		$fallbackChainProvider = new LanguageFallbackChainFactoryFallbackLanguagesProvider(
			WikibaseRepo::getLanguageFallbackChainFactory()
		);

		return new LanguageFallbackLabelSelector( $fallbackChainProvider );
	}

}
