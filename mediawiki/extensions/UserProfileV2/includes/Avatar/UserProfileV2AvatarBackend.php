<?php
/**
 * Backend for UserProfile images (for avatars, etc).
 * This is mostly copied from SocialProfile for now, with slight amendments.
 * @link https://github.com/wikimedia/mediawiki-extensions-SocialProfile/blob/master/SocialProfileFileBackend.php
 */

namespace Telepedia\UserProfileV2\Avatar;

use FSFileBackend;
use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;
use NullLockManager;

class UserProfileV2AvatarBackend {

	private string $container;

	/**
	 * @param string|null $container the container we want, will most likely be "avatar". Put this here for future ;)
	 */
	public function __construct( string $container = null ) {
		if ( !$container ) {
			$container = 'upv2avatars'; // change because SocialProfile uses "Avatars"
		}

		$this->container = $container;
	}

	/**
	 * Get our file backend (could be S3 or Swift, define $wgUserProfileV2Backend),
	 * or if we can't, just fall back to using the default FSFileBackend
	 * @return FSFileBackend|mixed
	 */
	public function getFileBackend() {
		$services = MediaWikiServices::getInstance();

		$mainConfig = $services->getConfigFactory()->makeConfig( 'UserProfileV2' );
		$upBackend = $mainConfig->get( 'UserProfileV2Backend' );

		if ( !empty( $upBackend ) ) {
			$backend = $services->getFileBackendGroup()->get( $upBackend );
		} else {
			$backend = new FSFileBackend( [
				'name' => "{$this->container}-backend",
				'wikiId' => WikiMap::getCurrentWikiId(),
				'lockManager' => new NullLockManager( [] ),
				'containerPaths' => [ $this->container => "{$mainConfig->get( 'UploadDirectory' )}/{$this->container}" ],
				'fileMode' => 0777,
				'obResetFunc' => 'wfResetOutputBuffers',
				'streamMimeFunc' => [ 'StreamFile', 'contentTypeFromPath' ],
				'statusWrapper' => [ 'Status', 'wrap' ],
			] );
		}

		if ( !$backend->directoryExists( [ 'dir' => $backend->getContainerStoragePath( $this->container ) ] ) ) {
			$backend->prepare( [ 'dir' => $backend->getContainerStoragePath( $this->container ) ] );
		}

		return $backend;
	}

	/**
	 * @param $prefix
	 * @param $id
	 * @param $size
	 * @param $ext
	 * @return string|null
	 */
	public function getPath( $prefix, $id, $ext ) {
		return $this->getFileBackend()->normalizeStoragePath(
			$this->getContainerStoragePath() .
			'/' . $this->getFileName( $prefix, $id, $ext )
		);
	}

	/**
	 * @param $prefix
	 * @param $id
	 * @param $size
	 * @param $ext
	 * @return string
	 */
	public function getFileName( $prefix, $id, $ext ) {
		return $prefix . (string)$id . '.' . $ext;
	}

	/**
	 * Get the backend container storage path.
	 *
	 * @return string Storage path
	 */
	public function getContainerStoragePath() {
		return $this->getFileBackend()->getContainerStoragePath( $this->container );
	}

	/**
	 * @param $prefix
	 * @param $id - userid
	 * @param $ext - file extension
	 * @return mixed
	 */
	public function getFileHttpUrl( $prefix, $id, $ext ) {
		return $this->getDefaultUrlPath( $this->getFileName( $prefix, $id, $ext ) );
	}

	/**
	 * @param $fileName
	 * @return mixed
	 */
	public function getFileHttpUrlFromName( $fileName ) {
		return $this->getDefaultUrlPath( $fileName );
	}

	/**
	 * Get the default url path for images. If using global avatars
	 * @param $fileName
	 * @return string
	 */
	public function getDefaultUrlPath( $fileName ): string {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'UserProfileV2' );

		if ( $config->get( 'UserProfileGlobalUploadBaseUrl' ) ) {
			$uploadPath = $config->get( 'UserProfileGlobalUploadBaseUrl' ) ?
				$config->get( 'UserProfileGlobalUploadBaseUrl' ) .
				$config->get( 'UserProfileGlobalUploadDirectory' ) :
				$config->get( 'UserProfileGlobalUploadDirectory' );

			return $uploadPath . '/' . $this->container . '/' . $fileName;
		}

		$uploadPath = $config->get( 'UploadBaseUrl' ) ? $config->get( 'UploadBaseUrl' ) .
			$config->get( 'UploadPath' ) :
			$config->get( 'UploadPath' );

		return $uploadPath . '/' . $this->container . '/' . $fileName;
	}

	/**
	 * Check if the file we're asking for exists.
	 * @param $prefix
	 * @param $id
	 * @param $size
	 * @param $ext
	 * @return bool|null
	 */
	public function fileExists( $prefix, $id, $ext ) {
		return $this->getFileBackend()->fileExists( [
			'src' => $this->getPath(
				$prefix, $id, $ext
			)
		] );
	}
}