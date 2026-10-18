<?php

namespace Telepedia\UserProfileV2\Api;

use ApiBase;
use ApiMain;
use Exception;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserOptionsManager;
use Telepedia\UserProfileV2\UserInformation;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

class SetUserProfileV2Preferences extends ApiBase {

	/** @var UserOptionsManager */
	private UserOptionsManager $userOptionsManager;

	/** @var UserFactory */
	private UserFactory $userFactory;

	/** @var PermissionManager */
	private PermissionManager $permissionManager;

	public function __construct( ApiMain $apiMain, $moduleName, UserOptionsManager $userOptionsManager, UserFactory $userFactory, PermissionManager $permissionManager) {
		parent::__construct( $apiMain, $moduleName );
		$this->userOptionsManager = $userOptionsManager;
		$this->userFactory = $userFactory;
		$this->permissionManager = $permissionManager;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$params = $this->extractRequestParams();

		$userName = $params['user_name'];
		$body = $params['profile_data'];

		$actionUser = $this->getUser();

		$user = $this->getTargetUser( $userName );

		if ( !$user->isRegistered() ) {
			$this->dieWithError( [ 'userprofilev2-apierror-invalidusername', wfEscapeWikiText( $userName ) ] );
		}

		// if the user is not trying to change their own preferences, and isn't a profile manager, die.
		if ( !$this->checkPermissions( $actionUser, $user ) ) {
			$this->dieWithError( [ 'userprofilev2-apierror-invalidpermission', wfEscapeWikiText( $userName ) ] );
		}

		$userInformation = new UserInformation( $user );

		try {
			$userInformation->setPreferences( $this->userOptionsManager, $body );
			$this->getResult()->addValue( null, 'result', 'success' );
			return;
		} catch ( Exception $e ) {
			$this->getResult()->addValue( null, 'result', 'failure' );
			$this->getResult()->addValue( null, 'errormsg', $e->getMessage() );
			return;
		}
	}

	/**
	 * Check whether a user can set another user's UserProfile preferences
	 * @param User $user the user performing the action
	 * @param User $targetUser The user they're trying to perform it on
	 * @return bool
	 */
	private function checkPermissions( User $user, User $targetUser ): bool {
		// If the user is blocked, regardless of their permissions, they are
		// not permitted to edit user profiles
		if ( $user->getBlock() ) {
			return false;
		}

		// If the user has the profilemanager permission, they can edit preferences
		if ( $this->permissionManager->userHasRight( $user, 'profilemanager' ) ) {
			return true;
		}

		// The user can only edit the target's preferences if they are the target
		return $user->getId() === $targetUser->getId();
	}

	/**
	 * @param string $userName
	 * @return User
	 */
	private function getTargetUser( string $userName ): User {
		return $this->userFactory->newFromName( $userName );
	}

	/**
	 * @return bool
	 */
	public function mustBePosted(): bool {
		return true;
	}

	/**
	 * @return string
	 */
	public function needsToken(): string {
		return 'csrf';
	}

	/**
	 * @return bool
	 */
	public function isWriteMode(): bool {
		return true;
	}

	/**
	 * @return array[]
	 */
	public function getAllowedParams() {
		return [
			'user_name' => [
				ParamValidator::PARAM_TYPE => 'string',
				IntegerDef::PARAM_MIN => 1,
				ParamValidator::PARAM_REQUIRED => true,
			],
			'profile_data' => [
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_REQUIRED => true,
			]
		];
	}
}
