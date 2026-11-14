<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse\Domain\Services;

use Wikibase\Repo\Domains\Reuse\Domain\Model\Label;
use Wikibase\Repo\Domains\Reuse\Domain\Model\Labels;

/**
 * @license GPL-2.0-or-later
 */
class LanguageFallbackLabelSelector {

	public function __construct(
		private readonly LanguageFallbackChainProvider $languageFallbackChainProvider
	) {
	}

	public function selectLabel( string $requestedLang, Labels $labels ): ?Label {
		foreach ( $this->languageFallbackChainProvider->getFallbackLanguages( $requestedLang ) as $language ) {
			$label = $labels->getLabelInLanguage( $language );
			if ( $label !== null ) {
				return $label;
			}
		}

		return null;
	}

}
