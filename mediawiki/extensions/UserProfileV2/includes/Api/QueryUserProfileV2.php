<?php

namespace Telepedia\UserProfileV2\Api;

use ApiQuery;
use ApiQueryBase;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserOptionsLookup;
use Telepedia\UserProfileV2\Avatar\UserProfileV2Avatar;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

class QueryUserProfileV2 extends ApiQueryBase {

	/** @var UserOptionsLookup */
	private UserOptionsLookup $userOptionsLookup;

	/** @var UserFactory */
	private UserFactory $userFactory;

	/** @var string[] */
	private static $preferences = [
		'profile-aboutme',
		'profile-show-globalgroups',
		'profile-show-globaledits',
		'profile-discord',
		'profile-twitter',
		'profile-mastodon'
	];

	/**
	 * Main Constructor
	 * @param ApiQuery $query
	 * @param string $moduleName
	 * @param UserOptionsLookup $userOptionsLookup
	 * @param UserFactory $userFactory
	 */
	public function __construct( ApiQuery $query, string $moduleName,
								UserOptionsLookup $userOptionsLookup, UserFactory $userFactory ) {
		parent::__construct( $query, $moduleName );
		$this->userOptionsLookup = $userOptionsLookup;
		$this->userFactory = $userFactory;
	}

	#[\Override]

	/**
	 * Main entrypoint; handles all the logic
	 */
	public function execute() {
		$params = $this->extractRequestParams();

		$userName = $params['user_name'] ?? null;

		if ( $userName !== null ) {
			$userProfile = $this->getUserProfileFromName( $userName );
		}

		$this->getResult()->addValue( 'query', false, $userProfile );
	}

	/**
	 * Get the users profile from their name
	 * @param string $userName
	 * @return array
	 * @throws \ApiUsageException
	 */
	private function getUserProfileFromName( string $userName ): array {
		$user = $this->getUserFromName( $userName );

		if ( !$user->isRegistered() ) {
			$this->dieWithError( [ 'userprofilev2-apierror-invalidusername', wfEscapeWikiText( $userName ) ] );
		}

		$userPreferences = [];

		foreach ( self::$preferences as $preference ) {
			$pref = $this->userOptionsLookup->getOption( $user, $preference );
			$userPreferences[$preference] = $pref;
		}

		$avatar = new UserProfileV2Avatar( $user->getId() );
		$avatarUrl = $avatar->getAvatarUrl( [ 'raw' => true ] );

		$userPreferences['profile-avatar'] = $avatarUrl;

		return $userPreferences;
	}

	/**
	 * Get a user object from their name
	 * @param string $userName the user's name
	 * @return User
	 */
	private function getUserFromName( string $userName ): User {
		return $this->userFactory->newFromName( $userName );
	}

	#[\Override]

	/**
	 * Get the allowed parameters that can be passed to this API
	 * @return array[]
	 */
	public function getAllowedParams() {
		return [
			'user_name' => [
				ParamValidator::PARAM_TYPE => 'string',
				IntegerDef::PARAM_MIN => 1,
				ParamValidator::PARAM_REQUIRED => true,
			]
		];
	}
}