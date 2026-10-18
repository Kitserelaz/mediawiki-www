<?php

namespace Telepedia\UserProfileV2\Avatar;

use ExtensionRegistry;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use ObjectCache;
use StatusValue;

class UserProfileV2Avatar {

	private int $userId;

	public function __construct( int $userId ) {
		$this->userId = $userId;
	}

	/**
	 * Upload a default avatar
	 * @return \StatusValue
	 */
	private function uploadDefaultAvatar(): StatusValue {
		$backend = new UserProfileV2AvatarBackend( 'upv2avatars' );
		return $backend->getFileBackend()->quickStore( [
			'src' => __DIR__ . '/../../resources/avatars/default.jpg',
			'dst' => $backend->getContainerStoragePath() . '/default.jpg',
		] );
	}

	/**
	 * @return string
	 */
	public function getAvatarImage(): string {
		$wgAvatarKey = 'avatar';

		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'UserProfileV2' );

		$globalAvatars = $config->get( 'UserProfileV2UseGlobalAvatars' );
		$cacheType = $config->get( 'UserProfileV2CacheType' );

		$cache = $cacheType ? ObjectCache::getInstance( $cacheType ) : ObjectCache::getLocalClusterInstance();

		if ( $globalAvatars && ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {

			$centralAuthUser = new CentralAuthUser( User::newFromId( $this->userId )->getName() );

			$this->userId = $centralAuthUser->getId(); // overwrite the userId if we're using global avatars

			$key = $cache->makeGlobalKey( 'user', 'userprofilev2', 'avatar', $this->userId );
		} elseif ( $globalAvatars ) {
			// slight hack at the moment until this is refactored. If we don't have centralauth and we do have global avatars
			// then make a global cache key and use that
			$key = $cache->makeGlobalKey( 'user', 'userprofilev2', 'avatar', $this->userId );
		} else {
			$key = $cache->makeKey( 'user', 'userprofilev2', 'avatar', $this->userId );
		}
		$data = $cache->get( $key );

		if ( $data ) {
			$avatarFilename = $data;
			return $avatarFilename;
		} else {

			if ( !$this->defaultAvatarExists() ) {
				$this->uploadDefaultAvatar();
			}

			$avatar_filename = 'default.jpg';

			$backend = new UserProfileV2AvatarBackend( 'upv2avatars' );
			$extensions = [ 'png', 'gif', 'jpg', 'jpeg' ];
			foreach ( $extensions as $ext ) {
				if ( $backend->fileExists( $wgAvatarKey . '_', $this->userId, $ext ) ) {
					$avatar_filename = $backend->getFileName(
						$wgAvatarKey . '_', $this->userId, $ext
					);

					$avatar_filename .= '?r=' . $backend->getFileBackend()->getFileStat( [
							'src' => $backend->getContainerStoragePath() . '/' . $avatar_filename
						] )['mtime'];

					break;
				}
			}
			$cache->set( $key, $avatar_filename, 60 * 60 * 24 ); // why doesn't this work? @TODO: fix?
		}

		return $avatar_filename;
	}

	/**
	 * @return mixed|string
	 */
	public function getAvatarUrl( $extraParams = [] ) {
		$backend = new UserProfileV2AvatarBackend( 'upv2avatars' );

		$url = $backend->getFileHttpUrlFromName( $this->getAvatarImage() );

		if ( isset( $extraParams['raw'] ) && $extraParams['raw'] === true ) {
			return $url;
		}

		$defaultParams = [
			'src' => $url,
			'border' => '0',
			'class' => 'mw-userprofilev2-avatar'
		];

		$params = array_merge( $extraParams, $defaultParams );

		return Html::element( 'img', $params, '' );
	}

	/**
	 * Check if there is a default avatar image with the supplied $size.
	 *
	 * @param string $size Avatar image size
	 * @return bool|null Returns null on failure
	 */
	private function defaultAvatarExists(): bool {
		$backend = new UserProfileV2AvatarBackend( 'upv2avatars' );
		return $backend->getFileBackend()->fileExists( [
			'src' => $backend->getContainerStoragePath() . '/default.jpg',
		] );
	}

	/**
	 * Get a string representation of this avatar
	 * @return mixed|string
	 */
	public function __toString() {
		return $this->getAvatarURL();
	}
}