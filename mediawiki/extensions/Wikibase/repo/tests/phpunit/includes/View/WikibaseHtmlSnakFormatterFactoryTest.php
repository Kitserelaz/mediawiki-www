<?php

namespace Wikibase\Repo\Tests\View;

use ValueFormatters\FormatterOptions;
use ValueFormatters\ValueFormatter;
use Wikibase\Lib\ContentLanguages;
use Wikibase\Lib\Formatters\CachingKartographerEmbeddingHandler;
use Wikibase\Lib\Formatters\FormatterLabelDescriptionLookupFactory;
use Wikibase\Lib\Formatters\OutputFormatSnakFormatterFactory;
use Wikibase\Lib\Formatters\SnakFormatter;
use Wikibase\Lib\TermLanguageFallbackChain;
use Wikibase\Repo\View\WikibaseHtmlSnakFormatterFactory;
use Wikibase\View\Wbui2025FeatureFlag;

/**
 * @covers \Wikibase\Repo\View\WikibaseHtmlSnakFormatterFactory
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 * @author Adrian Heine <adrian.heine@wikimedia.de>
 */
class WikibaseHtmlSnakFormatterFactoryTest extends \PHPUnit\Framework\TestCase {

	public function testGetSnakFormatter() {
		$snakFormatter = $this->createMock( SnakFormatter::class );
		$languageFallbackChain = new TermLanguageFallbackChain( [], $this->createStub( ContentLanguages::class ) );

		$outputFormatSnakFormatterFactory = $this->createMock( OutputFormatSnakFormatterFactory::class );

		$outputFormatSnakFormatterFactory->expects( $this->once() )
			->method( 'getSnakFormatter' )
			->with(
				SnakFormatter::FORMAT_HTML_VERBOSE,
				new FormatterOptions( [
					ValueFormatter::OPT_LANG => 'en',
					FormatterLabelDescriptionLookupFactory::OPT_LANGUAGE_FALLBACK_CHAIN => $languageFallbackChain,
				] )
			)
			->willReturn( $snakFormatter );

		$factory = new WikibaseHtmlSnakFormatterFactory( $outputFormatSnakFormatterFactory );

		$snakFormatterReturned = $factory->getSnakFormatter(
			'en',
			$languageFallbackChain,
			[]
		);
		$this->assertEquals( $snakFormatter, $snakFormatterReturned );
	}

	public function testGetSnakFormatter_Wbui2025() {
		$snakFormatter = $this->createMock( SnakFormatter::class );
		$languageFallbackChain = new TermLanguageFallbackChain( [], $this->createStub( ContentLanguages::class ) );

		$outputFormatSnakFormatterFactory = $this->createMock( OutputFormatSnakFormatterFactory::class );

		$outputFormatSnakFormatterFactory->expects( $this->once() )
			->method( 'getSnakFormatter' )
			->with(
				SnakFormatter::FORMAT_HTML_VERBOSE,
				new FormatterOptions( [
					ValueFormatter::OPT_LANG => 'en',
					FormatterLabelDescriptionLookupFactory::OPT_LANGUAGE_FALLBACK_CHAIN => $languageFallbackChain,
					CachingKartographerEmbeddingHandler::OPT_KARTOGRAPHER_VARIABLE_WIDTH => true,
				] )
			)
			->willReturn( $snakFormatter );

		$factory = new WikibaseHtmlSnakFormatterFactory( $outputFormatSnakFormatterFactory );

		$snakFormatterReturned = $factory->getSnakFormatter(
			'en',
			$languageFallbackChain,
			[ Wbui2025FeatureFlag::EXTENSION_DATA_KEY => 'wbui2025' ]
		);
		$this->assertEquals( $snakFormatter, $snakFormatterReturned );
	}

}
