<?php

namespace MediaWiki\Skins\Mijovia;

use MediaWiki\Config\Config;
use MediaWiki\Content\JsonContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

final class ThemeDesignerConfig {
	public const RIGHT = 'mijoviathemedesigner';
	public const DRAFT = 'userjs-mijovia-theme-draft';
	public const MODES = [ 'light', 'dark' ];
	public const NO_IMAGE = 'none';

	/** Field names and the existing skin variables they override. */
	public const COLORS = [
		'site' => '--theme-body-background-color',
		'header' => '--theme-sticky-nav-background-color',
		'article' => '--theme-page-background-color',
		'rail' => '--mijovia-theme-rail-color',
		'text' => '--theme-page-text-color',
		'link' => '--theme-link-color',
		'accent' => '--theme-accent-color',
	];

	// Match the bundled palette in resources/fandom-theme.css.
	private const DEFAULT_COLORS = [
		'light' => [
			'site' => '#91cbff', 'header' => '#ffffff', 'article' => '#ffffff',
			'text' => '#3a3a3a', 'link' => '#3366cc', 'accent' => '#795cb2',
		],
		'dark' => [
			'site' => '#00001c', 'header' => '#00001c', 'article' => '#00001c',
			'text' => '#e6e6e6', 'link' => '#699cff', 'accent' => '#795cb2',
		],
	];

	public static function defaultLogo( Config $config, array $logos ): string {
		return $logos['icon'] ?? $logos['1x'] ?? $config->get( 'ScriptPath' ) . '/resources/assets/mediawiki_compact.svg';
	}

	public static function displayDefaults( Config $config, array $logos, array $values ): array {
		$defaults = self::DEFAULT_COLORS;
		$defaults['logo'] = self::defaultLogo( $config, $logos );
		foreach ( self::MODES as $mode ) {
			$defaults[$mode]['banner'] = '';
			$palette = is_array( $values[$mode] ?? null ) ? $values[$mode] : [];
			$site = self::normalizedColor( $palette['site'] ?? '' ) ?? $defaults[$mode]['site'];
			$article = self::normalizedColor( $palette['article'] ?? '' ) ?? $defaults[$mode]['article'];
			// The light rail blends the article and site backgrounds. The dark rail is transparent.
			$defaults[$mode]['rail'] = $mode === 'light' ? sprintf(
				'#%02x%02x%02x',
				...array_map(
					static fn ( int $paper, int $background ): int => (int)round( 0.75 * $paper + 0.25 * $background ),
					self::channels( $article ),
					self::channels( $site )
				)
			) : $site;
		}
		return $defaults;
	}

	public static function title(): Title {
		return Title::makeTitle( NS_MEDIAWIKI, 'Mijovia-theme.json' );
	}

	public static function revision( int $flags = 0 ): ?RevisionRecord {
		return MediaWikiServices::getInstance()->getRevisionStore()->getRevisionByTitle( self::title(), 0, $flags );
	}

	public static function valuesFromRevision( ?RevisionRecord $revision ): array {
		$content = $revision?->getContent( SlotRecord::MAIN );
		return $content instanceof JsonContent ? self::decode( $content->getText() ) : [];
	}

	public static function decode( string $json ): array {
		$value = json_decode( $json, true );
		return is_array( $value ) ? $value : [];
	}

	/**
	 * Produce canonical settings and renderable overrides. Invalid fields are recorded
	 * for the designer and use the bundled defaults when rendering a published page.
	 */
	public static function resolve( array $input ): array {
		$errors = [];
		[ $logo, $logoUrl ] = self::resolveImage( $input['logo'] ?? '', 'logo', $errors );
		$values = [ 'logo' => $logo ];
		$css = '';
		foreach ( self::MODES as $mode ) {
			$palette = is_array( $input[$mode] ?? null ) ? $input[$mode] : [];
			[ $banner, $bannerUrl ] = self::resolveImage( $palette['banner'] ?? '', "$mode-banner", $errors );
			$values[$mode] = [ 'banner' => $banner ];
			$declarations = [];
			foreach ( self::COLORS as $field => $variable ) {
				$inputColor = $palette[$field] ?? '';
				$value = self::normalizedColor( $inputColor );
				if ( $value === null && $inputColor !== '' ) {
					$errors["$mode-$field"] = 'mijovia-theme-error-color';
				}
				$values[$mode][$field] = $value ?? '';
				if ( $value !== null ) {
					self::addColor( $declarations, $field, $variable, $value );
				}
			}
			if ( $bannerUrl !== null ) {
				$declarations['--theme-body-background-image'] = 'url("' . str_replace(
					[ '\\', '"', '<', '>', "\r", "\n", "\f" ],
					[ '%5C', '%22', '%3C', '%3E', '%0D', '%0A', '%0C' ],
					$bannerUrl
				) . '")';
			} else {
				$site = self::normalizedColor( $palette['site'] ?? '' ) ?? self::DEFAULT_COLORS[$mode]['site'];
				$foreground = self::contrastingText( $site );
				$declarations['--theme-body-background-image'] = 'none';
				$declarations['--mijovia-banner-display'] = 'none';
				$declarations['--theme-community-header-color'] = $foreground;
				$declarations['--theme-community-header-color--hover'] = $foreground;
			}
			if ( $declarations ) {
				$selector = $mode === 'light' ? '[data-theme="light"]' : ':not([data-theme="light"])';
				$css .= ":root$selector body.skin-mijovia{";
				foreach ( $declarations as $name => $value ) {
					$css .= "$name:$value;";
				}
				$css .= '}';
			}
		}
		return [
			'values' => $values,
			'logoUrl' => $logoUrl,
			'hideLogo' => $logo === self::NO_IMAGE,
			'css' => $css,
			'errors' => $errors,
		];
	}

	private static function resolveImage( mixed $input, string $field, array &$errors ): array {
		if ( $input === '' || $input === self::NO_IMAGE ) {
			return [ $input, null ];
		}
		$title = is_string( $input ) && strlen( $input ) <= 255 ? Title::newFromText( $input, NS_FILE ) : null;
		$url = null;
		if ( $title && $title->inNamespace( NS_FILE ) && !$title->isExternal() && $title->getFragment() === '' ) {
			$file = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $title );
			if ( $file && $file->isVisible() && in_array( $file->getMimeType(), [
				'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/svg+xml',
			], true ) ) {
				$url = $file->getUrl();
			}
		}
		if ( !is_string( $url ) || !preg_match( '~^(?:https?://|/)~i', $url ) ) {
			$errors[$field] = 'mijovia-theme-error-image';
			return [ '', null ];
		}
		return [ $title->getPrefixedText(), $url ];
	}

	public static function normalizedColor( mixed $value ): ?string {
		return is_string( $value ) && preg_match( '/^#[0-9a-f]{6}$/iD', $value ) ? strtolower( $value ) : null;
	}

	private static function channels( string $value ): array {
		return array_map( 'hexdec', str_split( substr( $value, 1 ), 2 ) );
	}

	private static function contrastingText( string $value ): string {
		[ $r, $g, $b ] = array_map( static function ( int $channel ): float {
			$component = $channel / 255;
			return $component <= 0.04045 ? $component / 12.92 : ( ( $component + 0.055 ) / 1.055 ) ** 2.4;
		}, self::channels( $value ) );
		return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b >= 0.179 ? '#000000' : '#ffffff';
	}

	private static function addColor( array &$css, string $field, string $variable, string $value ): void {
		$css[$variable] = $value;
		$channels = self::channels( $value );
		$css["$variable--rgb"] = implode( ', ', $channels );
		if ( $field === 'header' || $field === 'accent' ) {
			$foreground = self::contrastingText( $value );
			$label = $field === 'header' ? '--theme-sticky-nav-text-color' : '--theme-accent-label-color';
			$css[$label] = $foreground;
			if ( $field === 'header' ) {
				$css["$label--hover"] = "color-mix(in srgb,$foreground 85%,$value)";
			} else {
				$css["$variable--hover"] = "color-mix(in srgb,$value 88%,$foreground)";
			}
		}
		if ( $field === 'link' || $field === 'text' ) {
			$css["$variable--hover"] = "color-mix(in srgb,$value 82%,var(--theme-page-background-color))";
		}
	}
}
