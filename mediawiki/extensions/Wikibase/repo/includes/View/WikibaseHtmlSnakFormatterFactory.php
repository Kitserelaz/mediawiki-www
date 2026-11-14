<?php

namespace Wikibase\Repo\View;

use ValueFormatters\FormatterOptions;
use ValueFormatters\ValueFormatter;
use Wikibase\Lib\Formatters\CachingKartographerEmbeddingHandler;
use Wikibase\Lib\Formatters\FormatterLabelDescriptionLookupFactory;
use Wikibase\Lib\Formatters\OutputFormatSnakFormatterFactory;
use Wikibase\Lib\Formatters\SnakFormatter;
use Wikibase\Lib\TermLanguageFallbackChain;
use Wikibase\View\HtmlSnakFormatterFactory;
use Wikibase\View\Wbui2025FeatureFlag;

/**
 * An HtmlSnakFormatterFactory implementation using an OutputFormatSnakFormatterFactory
 *
 * @license GPL-2.0-or-later
 * @author Adrian Heine <adrian.heine@wikimedia.de>
 */
class WikibaseHtmlSnakFormatterFactory implements HtmlSnakFormatterFactory {

	/**
	 * @var OutputFormatSnakFormatterFactory
	 */
	private $snakFormatterFactory;

	public function __construct( OutputFormatSnakFormatterFactory $snakFormatterFactory ) {
		$this->snakFormatterFactory = $snakFormatterFactory;
	}

	/**
	 * @param string $languageCode
	 * @param TermLanguageFallbackChain $termLanguageFallbackChain
	 * @param array $viewOptions
	 * @return FormatterOptions
	 */
	private function getFormatterOptions(
		$languageCode,
		TermLanguageFallbackChain $termLanguageFallbackChain,
		array $viewOptions = [],
	) {
		$optionsArray = [
			ValueFormatter::OPT_LANG => $languageCode,
			FormatterLabelDescriptionLookupFactory::OPT_LANGUAGE_FALLBACK_CHAIN => $termLanguageFallbackChain,
		];
		if ( Wbui2025FeatureFlag::wbui2025EnabledForViewOptions( $viewOptions ) ) {
			$optionsArray[ CachingKartographerEmbeddingHandler::OPT_KARTOGRAPHER_VARIABLE_WIDTH ] = true;
		}
		return new FormatterOptions( $optionsArray );
	}

	/**
	 * @param string $languageCode
	 * @param TermLanguageFallbackChain $termLanguageFallbackChain
	 * @param array $viewOptions
	 * @return SnakFormatter
	 */
	public function getSnakFormatter(
		$languageCode,
		TermLanguageFallbackChain $termLanguageFallbackChain,
		array $viewOptions,
	) {
		$formatterOptions = $this->getFormatterOptions( $languageCode, $termLanguageFallbackChain, $viewOptions );

		return $this->snakFormatterFactory->getSnakFormatter(
			SnakFormatter::FORMAT_HTML_VERBOSE,
			$formatterOptions,
			$viewOptions,
		);
	}

}
