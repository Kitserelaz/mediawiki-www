<?php

namespace Telepedia\UserProfileV2;

use ExtensionRegistry;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Message;

/**
 * Central registry for the profile fields shown by UserProfileV2.
 *
 * The default fields (about me, Discord, Twitter, Mastodon and the two
 * CentralAuth checkboxes) can be reordered, relabelled, extended or hidden
 * from LocalSettings.php via $wgUserProfileV2ProfileFields. When that list is
 * non-empty it replaces the built-in list completely: list every field you want
 * to show, in order, and omit a field to hide it.
 *
 * A field definition accepts:
 *   key                  (string, required) the user option name; must start with "profile-"
 *   type                 (string) text|textarea|check, defaults to text
 *   label                (string) message key or literal text
 *   name                 (string) message key or literal text, used as the link/tooltip title
 *   placeholder          (string) message key or literal text
 *   help                 (string) message key or literal text
 *   icon                 (string) twitter|mastodon|discord, or a raw <svg></svg> string
 *   url                  (string) URL pattern containing $1, turns the value into a link
 *   tooltip              (bool) render the value as a tooltip span instead of a link
 *   requiresCentralAuth  (bool) hide the field when CentralAuth is not installed
 *   enabled              (bool) set to false to hide a default field
 *   maxlength, rows      (int) forwarded to the input widget / preference form
 */
class ProfileFields {

	/**
	 * @var string
	 */
	public const SECTION = 'personal/profile';

	/**
	 * @var array
	 */
	private const FIELD_TYPES = [ 'text', 'textarea', 'check' ];

	/**
	 * Prefix that all preferences need to start with so that UPV2 cannot override a preference
	 * provided by Core.
	 * @var string
	 */
	public const KEY_PREFIX = 'profile-';

	/**
	 * Maximum length of a value when the field does not set maxlength
	 * @var array
	 */
	private const DEFAULT_MAX_LENGTHS = [
		'text' => 255,
		'textarea' => 1000,
	];

	/**
	 * @var array
	 */
	public const BUNDLED_ICONS = [ 'discord', 'mastodon', 'twitter' ];

	/**
	 * Definition of every field shipped with the extension.
	 * @var array
	 */
	public const DEFAULT_FIELDS = [
		[
			'key' => 'profile-aboutme',
			'type' => 'textarea',
			'label' => 'userprofilev2-about-me',
			'placeholder' => 'userprofilev2-about-me-placeholder',
			'help' => 'userprofilev2-about-me-help',
			'maxlength' => 200,
			'rows' => 6,
		],
		[
			'key' => 'profile-discord',
			'type' => 'text',
			'label' => 'userprofilev2-discord',
			'name' => 'userprofilev2-discord-name',
			'placeholder' => 'userprofilev2-discord-placeholder',
			'help' => 'userprofilev2-discord-help',
			'icon' => 'discord',
			'tooltip' => true,
		],
		[
			'key' => 'profile-twitter',
			'type' => 'text',
			'label' => 'userprofilev2-twitter',
			'name' => 'userprofilev2-twitter-name',
			'placeholder' => 'userprofilev2-twitter-placeholder',
			'help' => 'userprofilev2-twitter-help',
			'icon' => 'twitter',
			'url' => 'https://x.com/$1',
		],
		[
			'key' => 'profile-mastodon',
			'type' => 'text',
			'label' => 'userprofilev2-mastodon',
			'name' => 'userprofilev2-mastodon-name',
			'placeholder' => 'userprofilev2-mastodon-placeholder',
			'help' => 'userprofilev2-mastodon-help',
			'icon' => 'mastodon',
			'url' => 'https://mastodon.social/$1',
		],
		[
			'key' => 'profile-show-globalgroups',
			'type' => 'check',
			'label' => 'userprofilev2-showglobalgroups',
			'help' => 'userprofilev2-showglobalgroupshelp',
			'requiresCentralAuth' => true,
		],
		[
			'key' => 'profile-show-globaledits',
			'type' => 'check',
			'label' => 'userprofilev2-showglobaleditcount',
			'help' => 'userprofilev2-showglobaleditcounthelp',
			'requiresCentralAuth' => true,
		],
	];

	/**
	 * Every enabled field, in the order configured by the wiki.
	 * @return array
	 */
	public static function getFields(): array {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'UserProfileV2' );
		$configured = $config->get( 'UserProfileV2ProfileFields' );

		return self::buildFields( is_array( $configured ) ? $configured : [] );
	}

	/**
	 * Fields that can actually be edited on this wiki (CentralAuth only fields
	 * are skipped when CentralAuth is not installed).
	 * @return array
	 */
	public static function getEditableFields(): array {
		$centralAuthLoaded = ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' );

		$fields = [];
		foreach ( self::getFields() as $field ) {
			if ( !empty( $field['requiresCentralAuth'] ) && !$centralAuthLoaded ) {
				continue;
			}
			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * The user option names managed by this extension.
	 * @return array
	 */
	public static function getKeys(): array {
		$keys = [];
		foreach ( self::getFields() as $field ) {
			$keys[] = (string)$field['key'];
		}

		return $keys;
	}

	/**
	 * Build the message key for one of a field's display strings.
	 * The extension name is prepended and the leading "profile-" is stripped
	 * from the option key, matching the messages shipped for the built-in
	 * social fields. For example profile-github becomes:
	 *
	 *   userprofilev2-github
	 *   userprofilev2-github-placeholder
	 *   userprofilev2-github-help
	 *
	 * @param array $field
	 * @param string $suffix '' for the label, '-placeholder' or '-help'
	 * @return string
	 */
	public static function getMessageKey( array $field, string $suffix = '' ): string {
		$key = preg_replace( '/^profile-/', '', (string)( $field['key'] ?? '' ) );
		return 'userprofilev2-' . $key . $suffix;
	}

	/**
	 * Whether a value is a valid icon for a profile field
	 * @param string $icon either a bundled icon name, or a raw SVG
	 * @return bool
	 */
	public static function isValidIcon( string $icon ): bool {
		$icon = trim( $icon );

		if ( $icon === '' ) {
			return false;
		}

		return in_array( $icon, self::BUNDLED_ICONS, true ) || self::isRawSvg( $icon );
	}

	/**
	 * Whether a string is a complete raw SVG document.
	 * @param string $icon
	 * @return bool
	 */
	public static function isRawSvg( string $icon ): bool {
		$icon = trim( $icon );

		return preg_match( '/^(?:<\?xml[^>]*\?>\s*)?(?:<!--.*?-->\s*)*<svg(?:\s|>).*<\/svg>\s*$/is', $icon ) === 1;
	}

	/**
	 * The label for a field, falling back to a message when it is not set.
	 * @param array $field
	 * @return string
	 */
	public static function getLabel( array $field ): string {
		return self::getDisplayText( $field, 'label', '' );
	}

	/**
	 * The placeholder for a field, falling back to a message when not set.
	 * @param array $field
	 * @return string
	 */
	public static function getPlaceholder( array $field ): string {
		return self::getDisplayText( $field, 'placeholder', '-placeholder' );
	}

	/**
	 * The help text for a field, falling back to a message when not set.
	 * @param array $field
	 * @return string
	 */
	public static function getHelp( array $field ): string {
		return self::getDisplayText( $field, 'help', '-help' );
	}

	/**
	 * The service name for a field, used as the title of its link or tooltip.
	 * When the field does not configure a name, the generated
	 * userprofilev2-<key>-name message is used if it exists, falling back to
	 * the field label otherwise.
	 * @param array $field
	 * @return string
	 */
	public static function getName( array $field ): string {
		if ( isset( $field['name'] ) && $field['name'] !== '' ) {
			return self::messageText( $field['name'] );
		}

		$message = wfMessage( self::getMessageKey( $field, '-name' ) );
		if ( $message->exists() ) {
			return $message->text();
		}

		return self::getLabel( $field );
	}

	/**
	 * The resolved definition passed to the profile edit dialog. Display
	 * strings are resolved on the server so the dialog follows the same
	 * configuration (and the same messages) as Special:Preferences.
	 * @return array
	 */
	public static function getClientFields(): array {
		$fields = [];

		foreach ( self::getEditableFields() as $field ) {
			$definition = [
				'key' => (string)$field['key'],
				'type' => $field['type'] ?? 'text',
				'label' => self::getLabel( $field ),
				'placeholder' => self::getPlaceholder( $field ),
				'help' => self::getHelp( $field ),
			];

			$maxLength = self::getMaxLength( $field );
			if ( $maxLength !== null ) {
				$definition['maxlength'] = $maxLength;
			}

			if ( isset( $field['rows'] ) ) {
				$definition['rows'] = (int)$field['rows'];
			}

			$fields[] = $definition;
		}

		return $fields;
	}

	/**
	 * The maximum length of a field's value or null for checkboxes.
	 * @param array $field
	 * @return int|null
	 */
	public static function getMaxLength( array $field ): ?int {
		$type = $field['type'] ?? 'text';

		if ( !isset( self::DEFAULT_MAX_LENGTHS[$type] ) ) {
			return null;
		}

		if ( isset( $field['maxlength'] ) && (int)$field['maxlength'] > 0 ) {
			return (int)$field['maxlength'];
		}

		return self::DEFAULT_MAX_LENGTHS[$type];
	}

	/**
	 * Check a submitted value against a field's definition.
	 * @param array $field
	 * @param string $value
	 * @return string|null the key of an error message, or null when the value is valid
	 */
	public static function validateValue( array $field, string $value ): ?string {
		if ( !mb_check_encoding( $value, 'UTF-8' ) ) {
			return 'userprofilev2-apierror-invalidvalue';
		}

		$type = $field['type'] ?? 'text';

		if ( $type === 'check' ) {
			return in_array( $value, [ '', '0', '1' ], true ) ? null : 'userprofilev2-apierror-invalidvalue';
		}

		// Single line fields cannot contain line breaks or other control characters
		// thank you StackOverflow
		if ( $type === 'text' && preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return 'userprofilev2-apierror-invalidvalue';
		}

		$maxLength = self::getMaxLength( $field );
		if ( $maxLength !== null && mb_strlen( $value ) > $maxLength ) {
			return 'userprofilev2-apierror-toolong';
		}

		return null;
	}

	/**
	 * Normalise a validated value before it is saved.
	 * @param array $field
	 * @param string $value
	 * @return string
	 */
	public static function normaliseValue( array $field, string $value ): string {
		if ( ( $field['type'] ?? 'text' ) === 'check' ) {
			return $value === '1' ? '1' : '';
		}

		return trim( $value );
	}

	/**
	 * Resolve an explicitly configured display string, or the auto-generated
	 * message key when the field did not specify one.
	 * @param array $field
	 * @param string $property label|placeholder|help
	 * @param string $suffix message key suffix for this property
	 * @return string
	 */
	private static function getDisplayText( array $field, string $property, string $suffix ): string {
		if ( isset( $field[$property] ) && $field[$property] !== '' ) {
			return self::messageText( $field[$property] );
		}

		return self::messageText( self::getMessageKey( $field, $suffix ) );
	}

	/**
	 * Resolve a message key to text, falling back to the value itself when it
	 * is not a known message (so admins can use literal labels too).
	 * @param Message|string|null $value
	 */
	public static function messageText( $value ): string {
		if ( $value === null ) {
			return '';
		}
		if ( $value instanceof Message ) {
			return $value->text();
		}

		$value = (string)$value;
		if ( $value === '' ) {
			return '';
		}

		$message = wfMessage( $value );
		return $message->exists() ? $message->text() : $value;
	}

	/**
	 * Build the field list from the configured replacement list. An empty
	 * configuration falls back to the shipped defaults; a per-field definition
	 * reuses the shipped default with the same key unless it overrides it.
	 * @param array $configured
	 * @return array
	 */
	private static function buildFields( array $configured ): array {
		if ( $configured === [] ) {
			return self::DEFAULT_FIELDS;
		}

		$defaults = [];
		foreach ( self::DEFAULT_FIELDS as $field ) {
			$defaults[$field['key']] = $field;
		}

		$fields = [];
		foreach ( $configured as $configKey => $entry ) {
			if ( is_string( $entry ) ) {
				$entry = [ 'key' => $entry ];
			}
			if ( !is_array( $entry ) ) {
				continue;
			}
			if ( !isset( $entry['key'] ) && is_string( $configKey ) ) {
				$entry['key'] = $configKey;
			}
			if ( !isset( $entry['key'] ) ) {
				continue;
			}

			$key = (string)$entry['key'];

			if ( !str_starts_with( $key, self::KEY_PREFIX ) || $key === self::KEY_PREFIX ) {
				LoggerFactory::getInstance( 'UserProfileV2' )->warning(
					'Ignoring profile field "{key}"; keys must start with "{prefix}"',
					[ 'key' => $key, 'prefix' => self::KEY_PREFIX ]
				);
				continue;
			}

			$field = array_merge( $defaults[$key] ?? [ 'key' => $key, 'type' => 'text' ], $entry );

			if ( array_key_exists( 'enabled', $field ) && !$field['enabled'] ) {
				continue;
			}

			$type = $field['type'] ?? 'text';
			$field['type'] = in_array( $type, self::FIELD_TYPES, true ) ? $type : 'text';

			$fields[] = $field;
		}

		return $fields;
	}
}
