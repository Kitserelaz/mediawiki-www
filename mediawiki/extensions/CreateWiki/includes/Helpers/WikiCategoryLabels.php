<?php

namespace Miraheze\CreateWiki\Helpers;

use MediaWiki\Context\IContextSource;

class WikiCategoryLabels {

	public static function getLabel( IContextSource $context, string $category, string $fallback ): string {
		$message = $context->msg( 'createwiki-category-' . $category );
		return $message->isDisabled() ? $fallback : $message->text();
	}

	/**
	 * @param IContextSource $context Interface language context
	 * @param array $categories Configured labels mapped to category IDs
	 * @param array $options Additional options to show before the categories
	 * @return array Localized labels mapped to category IDs
	 */
	public static function getOptions( IContextSource $context, array $categories, array $options = [] ): array {
		foreach ( $categories as $fallback => $category ) {
			$label = self::getLabel( $context, $category, $fallback );
			// HTMLForm uses labels as array keys. Keep each ID selectable if labels collide.
			while ( isset( $options[$label] ) ) {
				$label .= " ($category)";
			}
			$options[$label] = $category;
		}

		return $options;
	}
}
