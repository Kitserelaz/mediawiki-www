<?php

namespace Telepedia\UserProfileV2\Api;

use ApiQuery;
use ApiQueryBase;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserOptionsLookup;
use Telepedia\UserProfileV2\Avatar\UserProfileV2Avatar;
use Telepedia\UserProfileV2\ProfileFields;
use Wikimedia\ParamValidator\ParamValidator;

class QueryUserProfileV2 extends ApiQueryBase {

	/** @var UserOptionsLookup */
	private UserOptionsLookup $userOptionsLookup;

	/** @var UserFactory */
	private UserFactory $userFactory;

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

	/**
	 * Main entrypoint; handles all the logic
	 */
	public function execute() {
		$params = $this->extractRequestParams();

		$userName = $params['user_name'] ?? null;
		$userProfile = [];

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

		$userPreferences = [];

		// The list of fields is configurable through $wgUserProfileV2ProfileFields.
		foreach ( ProfileFields::getKeys() as $preference ) {
			$userPreferences[$preference] = $this->userOptionsLookup->getOption( $user, $preference );
		}

		$avatar = new UserProfileV2Avatar( $user->getId() );
		$userPreferences['profile-avatar'] = $avatar->getAvatarUrl( [ 'raw' => true ] );

		return $userPreferences;
	}

	/**
	 * Get a user object from their name
	 * @param string $userName the user's name
	 * @return User
	 */
	private function getUserFromName( string $userName ): User {
		$user = $this->userFactory->newFromName( $userName );

		// replicate what core does and treat a suppressed/hidden user as non-existent unless the
		// performer has the ability to hide user profiles
		if (
			!$user ||
			!$user->isRegistered() ||
			( $user->isHidden() && !$this->getAuthority()->isAllowed( 'hideuser' ) )
		) {
			$this->dieWithError( [ 'userprofilev2-apierror-invalidusername', wfEscapeWikiText( $userName ) ] );
		}

		return $user;
	}

	/**
	 * Get the allowed parameters that can be passed to this API
	 * @return array[]
	 */
	public function getAllowedParams() {
		return [
			'user_name' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			]
		];
	}
}
