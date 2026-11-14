<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Infrastructure\DataAccess;

use Wikibase\Lib\LanguageFallbackChainFactory;
use Wikibase\Repo\Domains\Reuse\Domain\Services\LanguageFallbackChainProvider;

/**
 * @license GPL-2.0-or-later
 */
class LanguageFallbackChainFactoryFallbackLanguagesProvider implements LanguageFallbackChainProvider {

	public function __construct( private readonly LanguageFallbackChainFactory $languageFallbackChainFactory ) {
	}

	public function getFallbackLanguages( string $languageCode ): array {
		return $this->languageFallbackChainFactory
			->newFromLanguageCode( $languageCode )
			->getFetchLanguageCodes();
	}

}
