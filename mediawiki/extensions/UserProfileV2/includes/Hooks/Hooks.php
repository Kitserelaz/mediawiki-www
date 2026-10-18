<?php

namespace Telepedia\UserProfileV2\Hooks;

use ExtensionRegistry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\ArticleFromTitleHook;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use Telepedia\UserProfileV2\UserPage;

class Hooks implements
	ArticleFromTitleHook,
	GetPreferencesHook
{

	/**
	 * @inheritDoc
	 */
	public function onArticleFromTitle( $title, &$article, $context ) {
		$userNameUtils = MediaWikiServices::getInstance()->getUserNameUtils();
		$pageTitle = $title->getText();

		if ( $title->inNamespaces( [ NS_USER, NS_USER_TALK ] ) && !$title->isSubpage() &&
			$userNameUtils->isUsable( $pageTitle ) ) {
			$article = new UserPage( $title );
		}
	}

	/**
	 * Add our preferences to Special:Preferences
	 * @param $user
	 * @param &$preferences
	 * @return void
	 */
	public function onGetPreferences( $user, &$preferences ): void {
		// add the about me preference
		$preferences['profile-aboutme'] = [
			'type' => 'textarea',
			'label-message' => 'userprofilev2-about-me',
			'section' => 'personal/profile',
			'rows' => 6,
			'maxlength' => 200,
			'placeholder' => wfMessage( 'userprofilev2-about-me-placeholder' )->plain(),
			'help-message' => 'userprofilev2-about-me-help',
		];

		$preferences['profile-discord'] = [
			'type' => 'text',
			'label-message' => 'userprofilev2-discord',
			'section' => 'personal/profile',
			'placeholder' => wfMessage( 'userprofilev2-discord-placeholder' )->plain(),
			'help-message' => 'userprofilev2-discord-help',
		];

		$preferences['profile-twitter'] = [
			'type' => 'text',
			'label-message' => 'userprofilev2-twitter',
			'section' => 'personal/profile',
			'placeholder' => wfMessage( 'userprofilev2-twitter-placeholder' )->plain(),
			'help-message' => 'userprofilev2-twitter-help',
		];

		$preferences['profile-mastodon'] = [
			'type' => 'text',
			'label-message' => 'userprofilev2-mastodon',
			'section' => 'personal/profile',
			'placeholder' => wfMessage( 'userprofilev2-mastodon-placeholder' )->plain(),
			'help-message' => 'userprofilev2-mastodon-help',
		];

		if ( ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {

			$preferences['profile-show-globaledits'] = [
				'type' => 'check',
				'label' => "Show my global edit count on my userpage",
				'section' => 'personal/profile'
			];

			$preferences['profile-show-globalgroups'] = [
				'type' => 'check',
				'label' => "Show my global user groups on my userpage",
				'section' => 'personal/profile'
			];

		}
	}
}