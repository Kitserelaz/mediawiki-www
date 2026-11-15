<?php

namespace Telepedia\UserProfileV2\Hooks;

use MediaWiki\Hook\TitleIsAlwaysKnownHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\ArticleFromTitleHook;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\User\User;
use Telepedia\UserProfileV2\ProfileFields;
use Telepedia\UserProfileV2\UserPage;

class Hooks implements
	ArticleFromTitleHook,
	GetPreferencesHook,
	TitleIsAlwaysKnownHook
{

	/**
	 * @inheritDoc
	 */
	public function onArticleFromTitle( $title, &$article, $context ): void {
		$userNameUtils = MediaWikiServices::getInstance()->getUserNameUtils();
		$pageTitle = $title->getText();

		if ( $title->inNamespaces( [ NS_USER, NS_USER_TALK ] ) && !$title->isSubpage() &&
			$userNameUtils->isUsable( $pageTitle ) && !$userNameUtils->isTemp( $pageTitle ) ) {
			$article = new UserPage( $title );
		}
	}

	/**
	 * Add our preferences to Special:Preferences.
	 * The fields are generated from $wgUserProfileV2ProfileFields, so the
	 * labels, help text and ordering the wiki configures are used here too.
	 * @param User $user
	 * @param array &$preferences
	 * @return void
	 */
	public function onGetPreferences( $user, &$preferences ): void {
		foreach ( ProfileFields::getEditableFields() as $field ) {
			$key = (string)$field['key'];
			$type = $field['type'] ?? 'text';

			$preference = [
				'type' => match ( $type ) {
					'textarea' => 'textarea',
					'check' => 'check',
					default => 'text',
				},
				'section' => ProfileFields::SECTION,
			];

			$label = self::getDisplayValue( $field, 'label', '' );

			if ( isset( $label['message'] ) ) {
				$preference['label-message'] = $label['message'];
			} else {
				$preference['label'] = $label['text'];
			}

			$placeholder = self::getDisplayValue( $field, 'placeholder', '-placeholder' );
			if ( isset( $placeholder['message'] ) ) {
				$preference['placeholder-message'] = $placeholder['message'];
			} else {
				$preference['placeholder'] = $placeholder['text'];
			}

			$help = self::getDisplayValue( $field, 'help', '-help' );
			if ( isset( $help['message'] ) ) {
				$preference['help-message'] = $help['message'];
			} else {
				// help-raw expects HTML; escape literal configuration text.
				$preference['help-raw'] = htmlspecialchars( $help['text'], ENT_QUOTES );
			}

			$maxLength = ProfileFields::getMaxLength( $field );
			if ( $maxLength !== null ) {
				$preference['maxlength'] = $maxLength;
			}

			if ( $type !== 'check' ) {
				// The maxlength attribute is only enforced by the browser, so validate on the server too
				$preference['validation-callback'] = static function ( $value ) use ( $field, $maxLength ) {
					$error = ProfileFields::validateValue( $field, (string)$value );
					return $error === null
						? true
						: wfMessage( $error, ProfileFields::getLabel( $field ), $maxLength );
				};
			}

			if ( isset( $field['rows'] ) ) {
				$preference['rows'] = (int)$field['rows'];
			}

			$preferences[$key] = $preference;
		}
	}

	/**
	 * Resolve a field display option to either a message key or literal text.
	 * Auto-generated keys are always returned as messages; explicit values are
	 * treated as message keys only when the message exists.
	 * @param array $field
	 * @param string $property label|placeholder|help
	 * @param string $suffix Message key suffix for this property
	 * @return array Array with either a "message" or a "text" key
	 */
	private static function getDisplayValue( array $field, string $property, string $suffix ): array {
		if ( isset( $field[$property] ) && $field[$property] !== '' ) {
			$value = $field[$property];
			if ( is_string( $value ) && wfMessage( $value )->exists() ) {
				return [ 'message' => $value ];
			}

			return [ 'text' => ProfileFields::messageText( $value ) ];
		}

		return [ 'message' => ProfileFields::getMessageKey( $field, $suffix ) ];
	}

	/**
	 * @inheritDoc
	 */
	public function onTitleIsAlwaysKnown( $title, &$isKnown ): void {
		$pageTitle = $title->getText();
		$userNameUtils = MediaWikiServices::getInstance()->getUserNameUtils();

		if (
			$title->inNamespace( NS_USER ) &&
			!$title->isSubpage() &&
			$userNameUtils->isUsable( $pageTitle )
		) {
			$isKnown = true;
		}
	}
}
