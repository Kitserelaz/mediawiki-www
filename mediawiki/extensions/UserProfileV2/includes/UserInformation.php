<?php

namespace Telepedia\UserProfileV2;

use ExtensionRegistry;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\User\User;
use MediaWiki\User\UserOptionsManager;
use Telepedia\UserProfileV2\Avatar\UserProfileV2Avatar;

/**
 * Everything the profile masthead needs to know about a user.
 *
 * The list of profile fields/links is no longer hard-coded here: it comes from
 * ProfileFields, which reads $wgUserProfileV2ProfileFields.
 */
class UserInformation {

	/**
	 * Icons from iconoir and licensed under the MIT license.
	 * @var array
	 */
	private const SVG_ICONS = [
		'mastodon' => '<?xml version="1.0" encoding="UTF-8"?><svg width="24px" height="24px" viewBox="0 0 24 24" stroke-width="1.5" fill="none" xmlns="http://www.w3.org/2000/svg" color="#000000"><path d="M7 13.5C7 13.5 7 10.7574 7 9C7 5.99998 12 6 12 9C12 10.1716 12 12 12 12" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M17 13.5C17 13.5 17 10.7574 17 9C17 5.99998 12 6 12 9C12 10.1716 12 12 12 12" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M7.99993 17C15.5 18 20.9999 17 20.9999 13L21 9C21.0003 3.5 17.0003 2.5 15 2.5H9C5.99989 2.5 2.93261 3.5 3.13687 9C3.21079 10.987 3.17311 13.3851 3.5 16C4.50007 24 14 21.5 15.5 21V19.5C15.5 19.5 7.5 21 7.99993 17Z" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>',
		'twitter' => '<?xml version="1.0" encoding="UTF-8"?><svg width="24px" height="24px" viewBox="0 0 24 24" stroke-width="1.5" fill="none" xmlns="http://www.w3.org/2000/svg" color="#000000"><path d="M16.8198 20.7684L3.75317 3.96836C3.44664 3.57425 3.72749 3 4.22678 3H6.70655C6.8917 3 7.06649 3.08548 7.18016 3.23164L20.2468 20.0316C20.5534 20.4258 20.2725 21 19.7732 21H17.2935C17.1083 21 16.9335 20.9145 16.8198 20.7684Z" stroke="#000000" stroke-width="1.5"></path><path d="M20 3L4 21" stroke="#000000" stroke-width="1.5" stroke-linecap="round"></path></svg>',
		'discord' => '<?xml version="1.0" encoding="UTF-8"?><svg width="24px" height="24px" viewBox="0 0 24 24" stroke-width="1.5" fill="none" xmlns="http://www.w3.org/2000/svg" color="#000000"><path d="M5.5 16C10.5 18.5 13.5 18.5 18.5 16" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.5 17.5L16.5 19.5C16.5 19.5 20.6713 18.1717 22 16C22 15 22.5301 7.85339 19 5.5C17.5 4.5 15 4 15 4L14 6H12" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M8.52832 17.5L7.52832 19.5C7.52832 19.5 3.35699 18.1717 2.02832 16C2.02832 15 1.49823 7.85339 5.02832 5.5C6.52832 4.5 9.02832 4 9.02832 4L10.0283 6H12.0283" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M8.5 14C7.67157 14 7 13.1046 7 12C7 10.8954 7.67157 10 8.5 10C9.32843 10 10 10.8954 10 12C10 13.1046 9.32843 14 8.5 14Z" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.5 14C14.6716 14 14 13.1046 14 12C14 10.8954 14.6716 10 15.5 10C16.3284 10 17 10.8954 17 12C17 13.1046 16.3284 14 15.5 14Z" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>',
	];

	/**
	 * Protocols allowed in generated profile links.
	 * @var array
	 */
	private const ALLOWED_URL_PROTOCOLS = [ 'http', 'https', 'mailto' ];

	public function __construct(
		private readonly User $mUser
	) {}

	/**
	 * Grab the groups that a user belongs to.
	 * @param bool $global should we pull groups from CentralAuth?
	 * @return array
	 */
	public function getUserGroups( bool $global = false ): array {
		$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();
		$localGroups = $userGroupManager->getUserGroups( $this->mUser );

		$centralAuthLoaded = self::isCentralAuthLoaded();

		if ( $global && $centralAuthLoaded ) {
			$centralAuthUser = CentralAuthUser::getInstance( $this->mUser );
			$globalGroups = $centralAuthUser->getGlobalGroups();

			if ( count( $globalGroups ) > 0 ) {
				// get the array key for the steward group
				$steward = array_search( 'steward', $localGroups );

				// if it exists, unset it so we don't show duplicate user groups (since the global steward will always take precedence)
				if ( $steward !== false ) {
					unset( $localGroups[$steward] );
				}

				return array_merge( $localGroups, $globalGroups );
			}
		}

		return $localGroups;
	}

	/**
	 * @return string|null
	 */
	public function getUserBiography(): string|null {
		return MediaWikiServices::getInstance()->getUserOptionsLookup()->getOption( $this->mUser, 'profile-aboutme' );
	}

	/**
	 * @param array $profileData
	 * @return array
	 */
	public function parseProfileData( array $profileData ): array {
		$data = [];
		foreach ( $profileData as $pair ) {
			if ( !is_string( $pair ) || strpos( $pair, '=' ) === false ) {
				continue;
			}

			[ $key, $value ] = explode( '=', $pair, 2 );
			$data[$key] = rawurldecode( $value );
		}

		$allowed = array_map(
			static fn ( array $field ): string => (string)$field['key'],
			ProfileFields::getEditableFields()
		);

		return array_filter( $data, static function ( $key ) use ( $allowed ) {
			return in_array( $key, $allowed, true );
		}, ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Save profile fields that have already been parsed and validated.
	 * @param UserOptionsManager $userOptionsManager
	 * @param array $data option name => value, as returned by self::parseProfileData()
	 * @return void
	 */
	public function setPreferences( UserOptionsManager $userOptionsManager, array $data ): void {
		foreach ( $data as $key => $value ) {
			$userOptionsManager->setOption( $this->mUser, $key, $value );
		}

		$userOptionsManager->saveOptions( $this->mUser );
	}

	/**
	 * Return whether or not a user is blocked
	 * There is no longer any separate check for a global block
	 * If someone is blocked we'll just show the same notice irrelevant of local or global
	 * @return bool
	 */
	public function isBlocked(): bool {
		return (bool)$this->mUser->getBlock();
	}

	/**
	 * Check against CentralAuth if this current user is locked or not
	 * @return bool
	 */
	public function isLocked(): bool {
		if ( !self::isCentralAuthLoaded() ) {
			return false;
		}

		return CentralAuthUser::getInstance( $this->mUser )->isLocked();
	}

	/**
	 * Build the external profile links (Twitter, Mastodon, Discord, ...) from
	 * the fields configured with $wgUserProfileV2ProfileFields.
	 * @return array
	 */
	public function getProfileLinks(): array {
		$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();

		$externalLinks = [];

		foreach ( ProfileFields::getEditableFields() as $field ) {
			$icon = $this->getIconMarkup( $field );
			if ( ( $field['type'] ?? 'text' ) !== 'text' || $icon === null ) {
				continue;
			}

			$value = $userOptionsLookup->getOption( $this->mUser, $field['key'] );
			if ( !$value ) {
				continue;
			}

			$link = $this->generateExternalLink( $field, (string)$value, $icon );
			if ( $link !== null ) {
				$externalLinks[$field['key']] = $link;
			}
		}

		return $externalLinks;
	}

	/**
	 * Render a single profile link. Fields with a "url" template become links,
	 * fields without one (or with "tooltip" set) become a tooltip, as Discord
	 * used to be.
	 * @param array $field
	 * @param string $value
	 * @param string $icon
	 * @return string|null
	 */
	private function generateExternalLink( array $field, string $value, string $icon ): ?string {
		$name = ProfileFields::getName( $field );
		$hasUrl = !empty( $field['url'] );
		$useTooltip = !empty( $field['tooltip'] ) || !$hasUrl;

		if ( !$useTooltip ) {
			$template = (string)$field['url'];

			$replacement = str_starts_with( $template, '$1' )
				? $value
				: str_replace( '%40', '@', rawurlencode( $value ) );

			$url = str_replace( '$1', $replacement, $template );

			// Only render a link when the resulting URL starts with an
			// allowed protocol. This also rejects relative and
			// protocol-relative URLs, and prevents a url template of '$1'
			// from turning a value like "javascript:..." into an XSS vector.
			$scheme = parse_url( $url, PHP_URL_SCHEME );
			$validated = [];
			if ( is_string( $scheme ) && in_array( strtolower( $scheme ), self::ALLOWED_URL_PROTOCOLS, true ) ) {
				$validated = Sanitizer::validateAttributes(
					[ 'href' => $url ],
					[ 'href' => true ]
				);
			}

			if ( isset( $validated['href'] ) ) {
				return Html::rawElement(
					'a',
					[
						'href' => $validated['href'],
						'target' => '_blank',
						'rel' => 'nofollow noreferrer noopener',
						'class' => 'external-link',
						'title' => $name,
					],
					$icon
				);
			}
		}

		$accessibleLabel = $name !== '' ? $name . ': ' . $value : $value;

		return Html::rawElement(
			'span',
			[
				'class' => 'external-link tooltip',
				'title' => $name,
				'username' => $value,
				'tabindex' => '0',
				'role' => 'img',
				'aria-label' => $accessibleLabel,
			],
			$icon
		);
	}

	/**
	 * Resolve a field's icon to markup. Named icons use the bundled SVG set and
	 * raw <svg>...</svg> strings are passed through unchanged, so a leading
	 * comment (for example a licence notice) is kept in the output.
	 * @param array $field
	 * @return string|null
	 */
	private function getIconMarkup( array $field ): ?string {
		$icon = isset( $field['icon'] ) ? trim( (string)$field['icon'] ) : '';

		if ( !ProfileFields::isValidIcon( $icon ) ) {
			return null;
		}

		return self::SVG_ICONS[$icon] ?? $icon;
	}

	/**
	 * @return mixed|string
	 */
	public function getAvatarForUserProfile() {
		$avatar = new UserProfileV2Avatar( $this->mUser->getId() );
		return $avatar->getAvatarUrl( [ 'raw' => true ] );
	}

	/**
	 * Get a global users edit count
	 * @return int|null
	 */
	public function getGlobalEditCount(): int|null {
		if ( !self::isCentralAuthLoaded() ) {
			return null;
		}

		$centralAuthUser = CentralAuthUser::getPrimaryInstanceByName( $this->mUser->getName() );

		if ( !$centralAuthUser->exists() ) {
			return null;
		}

		return $centralAuthUser->getGlobalEditCount();
	}

	/**
	 * Should we show the global edit count for this user?
	 * @return bool
	 */
	public function shouldShowGlobalEditCount(): bool {
		if ( !self::isCentralAuthLoaded() ) {
			return false;
		}

		$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();

		$shouldShow = $userOptionsLookup->getOption( $this->mUser, 'profile-show-globaledits' );

		return $shouldShow == 1;
	}

	/**
	 * @return bool
	 */
	public function shouldShowGlobalGroups(): bool {
		if ( !self::isCentralAuthLoaded() ) {
			return false;
		}

		$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();

		$shouldShow = $userOptionsLookup->getOption( $this->mUser, 'profile-show-globalgroups' );

		return $shouldShow == 1;
	}

	/**
	 * Helper function to double check if CA is loaded
	 * @return bool
	 */
	private static function isCentralAuthLoaded(): bool {
		return ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' );
	}
}
