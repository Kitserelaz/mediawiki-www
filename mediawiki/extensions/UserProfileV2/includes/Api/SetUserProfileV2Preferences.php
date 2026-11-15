<?php

namespace Telepedia\UserProfileV2\Api;

use ApiBase;
use ApiMain;
use ManualLogEntry;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserOptionsManager;
use Telepedia\UserProfileV2\ProfileFields;
use Telepedia\UserProfileV2\UserInformation;
use Wikimedia\ParamValidator\ParamValidator;

class SetUserProfileV2Preferences extends ApiBase {

	/** @var UserOptionsManager */
	private UserOptionsManager $userOptionsManager;

	/** @var UserFactory */
	private UserFactory $userFactory;

	/** @var PermissionManager */
	private PermissionManager $permissionManager;

	public function __construct(
		ApiMain $apiMain,
		$moduleName,
		UserOptionsManager $userOptionsManager,
		UserFactory $userFactory,
		PermissionManager $permissionManager
	) {
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

		if ( !$actionUser->isNamed() ) {
			$this->dieWithError(
				[ 'apierror-mustbeloggedin', $this->msg( 'action-editmyoptions' ) ],
				'notloggedin'
			);
		}

		$user = $this->getTargetUser( $userName );

		// if the user is not trying to change their own preferences, and isn't a profile manager, die.
		if ( !$this->checkPermissions( $actionUser, $user ) ) {
			$this->dieWithError( [ 'userprofilev2-apierror-invalidpermission', wfEscapeWikiText( $userName ) ] );
		}

		if ( $actionUser->pingLimiter( 'userprofilev2-edit' ) ) {
			$this->dieWithError( 'apierror-ratelimited' );
		}

		$userInformation = new UserInformation( $user );

		$data = $userInformation->parseProfileData( $body );

		foreach ( ProfileFields::getEditableFields() as $field ) {
			$key = (string)$field['key'];
			if ( !array_key_exists( $key, $data ) ) {
				continue;
			}

			$error = ProfileFields::validateValue( $field, $data[$key] );
			if ( $error !== null ) {
				$this->dieWithError( [
					$error,
					ProfileFields::getLabel( $field ),
					ProfileFields::getMaxLength( $field )
				] );
			}

			$data[$key] = ProfileFields::normaliseValue( $field, $data[$key] );
		}

		$userInformation->setPreferences( $this->userOptionsManager, $data );

		// if the editor is a profile manager editing someone elses profile, log it
		if ( $actionUser->getId() !== $user->getId() ) {
			$logEntry = new ManualLogEntry( 'userprofile', 'edit' );
			$logEntry->setPerformer( $actionUser );
			$logEntry->setTarget( $user->getUserPage() );
			$logId = $logEntry->insert();
			$logEntry->publish( $logId );
		}

		$this->getResult()->addValue( null, 'result', 'success' );
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

		// The user can only edit the target's preferences if they are the target,
		// and are allowed to change their own preferences
		return $user->getId() === $targetUser->getId() &&
			$this->permissionManager->userHasRight( $user, 'editmyoptions' );
	}

	/**
	 * @param string $userName
	 * @return User
	 * @throws ApiUsageException if the user does not exist
	 */
	private function getTargetUser( string $userName ): User {
		$user = $this->userFactory->newFromName( $userName );

		if (
			!$user ||
			!$user->isNamed() ||
			( $user->isHidden() && !$this->getAuthority()->isAllowed( 'hideuser' ) )
		) {
			$this->dieWithError( [ 'userprofilev2-apierror-invalidusername', wfEscapeWikiText( $userName ) ] );
		}

		return $user;
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
				ParamValidator::PARAM_REQUIRED => true,
			],
			'profile_data' => [
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_REQUIRED => true,
			]
		];
	}
}
