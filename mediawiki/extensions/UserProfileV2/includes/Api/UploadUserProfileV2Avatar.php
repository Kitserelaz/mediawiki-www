<?php

namespace Telepedia\UserProfileV2\Api;

use ApiBase;
use ApiMain;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use Telepedia\UserProfileV2\Avatar\UploadAvatar;
use Telepedia\UserProfileV2\Avatar\UserProfileV2AvatarBackend;
use UploadBase;
use Wikimedia\ParamValidator\ParamValidator;

class UploadUserProfileV2Avatar extends ApiBase {

	/** @var UserFactory */
	private $userFactory;

	/** @var PermissionManager */
	private $permissionManager;

	public function __construct( ApiMain $apiMain, $moduleName, UserFactory $userFactory, PermissionManager $permissionManager ) {
		parent::__construct( $apiMain, $moduleName );
		$this->userFactory = $userFactory;
		$this->permissionManager = $permissionManager;
	}

	/**
	 * Execute the file upload.
	 * This is abit all over the place, but first lets check if the user in question can even upload a file
	 * if not, then there is no point going through all the other permission checks.
	 *
	 * Eventually this should be split into two and a profile-manager should only be able to delete an avatar, not
	 * upload a different avatar.
	 */
	public function execute() {
		$avatarKey = 'avatar';

		$user = $this->getUser();

		if ( !UploadBase::isAllowed( $user ) ) {
			$this->dieWithError( [ 'userprofilev2-apierror-forbidden' ] ); // forbidden from uploading
		}

		$params = $this->extractRequestParams();

		$targetUser = $this->getTargetUser( $params['username'] );

		$permissions = $this->checkPermissions( $user, $targetUser );

		if ( !$permissions ) {
			$this->dieWithError( [ 'userprofilev2-apierror-forbidden', wfEscapeWikiText( $targetUser->getName() ) ] );
		}

		$params['file'] = $this->getRequest()->getFileName( 'file' );

		if ( isset( $params['file'] ) ) {
			$upload = new UploadAvatar();
			$upload->initialize(
				rand() . microtime( true ) . rand(),
				$this->getRequest()->getUpload( 'file' )
			);
		}

		// if we do not have an upload, lets just die
		if ( !isset( $upload ) ) {
			$this->dieWithError( [ 'userprofilev2-apierror-nofile' ] );
		}

		if ( UploadBase::isThrottled( $user ) ) {
			$this->dieWithError( 'userprofilev2-apierror-ratelimited' );
		}

		$status = $upload->performUpload( '', '', false, $user );

		$result = [];

		if ( $status->isGood() ) {
			$backend = new UserProfileV2AvatarBackend( 'upv2avatars' );

			$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'UserProfileV2' );

			if ( $config->get( 'UserProfileV2UseGlobalAvatars' ) ) {
				$lookup = MediaWikiServices::getInstance()->getCentralIdLookup();
				$userId = $lookup->centralIdFromLocalUser( $targetUser );
			} else {
				$userId = $targetUser->getId();
			}

			$extension = $upload->mExtension;

			$ts = rand();

			$result = [
				'result' => 'Success',
				'url' => $backend->getFileHttpUrl( $avatarKey . '_', $userId, $extension ) . '?ts=' . $ts,
			];
		} else {
			$this->dieStatus( $status );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), $result );

		// do some cleanup
		$upload->cleanupTempFile();
	}

	/**
	 * Check if the user performing the permission has the right to change this avatar.
	 * Returns true IF the $user = $targetUser (changing their own), or $user has the profile-manage permission
	 * @param User $user the user performing the action
	 * @param User $targetUser the target user
	 * @return bool
	 */
	private function checkPermissions( User $user, User $targetUser ): bool {
		if ( !$user->isRegistered() ) {
			return false;
		}

		if ( $user->isBlockedFromUpload() ) {
			return false;
		}

		if ( $user->getId() == $targetUser->getId() ) {
			return true;
		}

		return false;
	}

	/**
	 * Get a user object for the user whose avatar we're trying to change.
	 * @param string $username
	 * @return User
	 * @throws \ApiUsageException
	 */
	private function getTargetUser( string $username ): User {
		$user = $this->userFactory->newFromName( $username );

		if ( !$user->isRegistered() ) {
			$this->dieWithError( [ 'userprofilev2-apierror-invalidusername', wfEscapeWikiText( $username ) ] );
		}

		return $user;
	}

	/** @inheritDoc */
	public function mustBePosted() {
		return true;
	}

	/** @inheritDoc */
	public function isWriteMode() {
		return true;
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'file' => [
				ParamValidator::PARAM_TYPE => 'upload',
			],
			'username' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string'
			],
		];
	}

	/** @inheritDoc */
	public function needsToken() {
		return 'csrf';
	}
}