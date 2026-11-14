<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Domain\Services;

/**
 * @license GPL-2.0-or-later
 */
interface LanguageFallbackChainProvider {

	/**
	 * @param string $languageCode
	 * @return string[] list of fallback languages, starting with the requested one
	 */
	public function getFallbackLanguages( string $languageCode ): array;

}
