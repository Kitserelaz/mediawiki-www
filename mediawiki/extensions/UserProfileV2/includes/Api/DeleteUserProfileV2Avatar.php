<?php

namespace Telepedia\UserProfileV2\Api;

use ApiMain;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use ObjectCache;
use Telepedia\UserProfileV2\Avatar\UserProfileV2AvatarBackend;
use Wikimedia\ParamValidator\ParamValidator;

class DeleteUserProfileV2Avatar extends \ApiBase {

	/** @var UserFactory */
	private UserFactory $userFactory;

	/** @var PermissionManager */
	private PermissionManager $permissionManager;

	public function __construct( ApiMain $apiMain, $moduleName, UserFactory $userFactory, PermissionManager $permissionManager ) {
		parent::__construct( $apiMain, $moduleName );
		$this->userFactory = $userFactory;
		$this->permissionManager = $permissionManager;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$params = $this->extractRequestParams();

		$targetUser = $this->getTargetUser( $params['username'] );

		$user = $this->getUser();

		if ( !$user->isRegistered() ) {
			$this->dieWithError( [ 'userprofilev2-apierror-notregistered' ] );
		}

		$canRemoveAvatar = $this->checkPermissions( $user, $targetUser );

		if ( !$canRemoveAvatar ) {
			$this->dieWithError( [ 'userprofilev2-apierror-cannotremoveavatar', wfEscapeWikiText( $targetUser->getName() ) ] );
		}

		$avatarKey = 'avatar';

		$backend = new UserProfileV2AvatarBackend( 'upv2avatars' );

		$extensions = [ 'png', 'gif', 'jpg', 'jpeg', 'webp' ];

		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'UserProfileV2' );

		if ( $config->get( 'UserProfileV2UseGlobalAvatars' ) ) {
			$lookup = MediaWikiServices::getInstance()->getCentralIdLookup();
			$userId = $lookup->centralIdFromLocalUser( $targetUser );
		} else {
			$userId = $user->getId();
		}

		foreach ( $extensions as $ext ) {
			if ( $backend->fileExists( $avatarKey . '_', $userId, $ext ) ) {
				$backend->getFileBackend()->quickDelete( [
					'src' => $backend->getPath( $avatarKey . '_', $userId, $ext )
				] );
			}
		}

		// delete all the data from the cache for this user, so that the default avatar is loaded on next profile
		// view
		$cacheType = $config->get( 'UserProfileV2CacheType' );

		$cache = $cacheType ? ObjectCache::getInstance( $cacheType ) : ObjectCache::getLocalClusterInstance();

		if ( $config->get( 'UserProfileV2UseGlobalAvatars' ) ) {
			$key = $cache->makeGlobalKey( 'user', 'userprofilev2', 'avatar', $userId );
		} else {
			$key = $cache->makeKey( 'user', 'userprofilev2', 'avatar', $userId );
		}

		$cache->delete( $key );

		$this->getResult()->addValue( null, $this->getModuleName(), [ 'status' => 'OK' ] );
	}

	/**
	 * Check whether a user can remove an avatar
	 * This should work but my head is going to explode thinking about it so
	 * @param User $user the user performing the action
	 * @param User $targetUser the user they're trying to perform it on
	 * @return bool
	 */
	private function checkPermissions( User $user, User $targetUser ): bool {
		// If the user is blocked, regardless of their permissions, they are
		// not permitted to edit user profiles
		if ( $user->getBlock() ) {
			return false;
		}

		$userIsSame = $user->getId() == $targetUser->getId();

		// if the user has the profilemanager permission, they can remove an avatar
		if ( $this->permissionManager->userHasRight( $user, 'profilemanager' ) ) {
			return true;
		}

		// if the user is trying to remove their own avatar, that is fine.
		if ( $userIsSame ) {
			return true;
		}

		return false;
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'username' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string'
			],
		];
	}

	private function getTargetUser( string $username ): User {
		$user = $this->userFactory->newFromName( $username );

		if ( !$user->isRegistered() ) {
			$this->dieWithError( [ 'userprofilev2-apierror-invalidusername', wfEscapeWikiText( $username ) ] );
		}

		return $user;
	}

	/** @inheritDoc */
	public function needsToken() {
		return 'csrf';
	}

	/** @inheritDoc */
	public function isWriteMode() {
		return true;
	}
}
