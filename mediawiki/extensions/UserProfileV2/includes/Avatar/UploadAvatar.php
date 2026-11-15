<?php

namespace Telepedia\UserProfileV2\Avatar;

use Exception;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use ObjectCache;
use UploadFromFile;
use Wikimedia\AtEase\AtEase;

class UploadAvatar extends UploadFromFile {

	/**
	 * width and height of the stored avatar (px)
	 * @var string
	 */
	private const AVATAR_SIZE = 138;

	/**
	 * Accepted image formats
	 * @var array
	 */
	private const IMAGE_TYPES = [
		IMAGETYPE_GIF => 'gif',
		IMAGETYPE_JPEG => 'jpg',
		IMAGETYPE_PNG => 'png',
	];

	/**
	 * Every extension an avatar may have been stored with
	 * @var array
	 */
	private const ALL_EXTENSIONS = [ 'gif', 'jpg', 'jpeg', 'png', 'webp' ];

	/**
	 * @var string
	 */
	public $mExtension;

	public function performUpload( $comment, $pageText, $watch, $user, $tags = [], ?string $watchlistExpiry = null ) {
		$avatarKey = 'avatar';

		$services = MediaWikiServices::getInstance();
		$config = $services->getConfigFactory()->makeConfig( 'UserProfileV2' );

		$cacheType = $config->get( 'UserProfileV2CacheType' );

		$cache = $cacheType ? ObjectCache::getInstance( $cacheType ) : ObjectCache::getLocalClusterInstance();

		if ( $this->isEmptyFile() ) {
			return Status::newFatal( 'empty-file' );
		}

		// we bpyass the check in UploadBase, so check the maximum upload size
		$maxSize = self::getMaxUploadSize( $this->getSourceType() );
		if ( $this->mFileSize > $maxSize ) {
			return Status::newFatal( 'file-too-large' );
		}

		$imageInfo = AtEase::quietCall( 'getimagesize', $this->mTempPath );
		if ( !$imageInfo || empty( $imageInfo[2] ) ) {
			return Status::newFatal( 'empty-file' );
		}

		if ( !isset( self::IMAGE_TYPES[$imageInfo[2]] ) ) {
			return Status::newFatal( 'filetype-banned' );
		}

		$ext = self::IMAGE_TYPES[$imageInfo[2]];

		[ $width, $height ] = $imageInfo;
		if ( $width <= 0 || $height <= 0 || $width * $height > $config->get( 'MaxImageArea' ) ) {
			return Status::newFatal( 'userprofilev2-apierror-imagetoolarge' );
		}

		if ( $config->get( 'UserProfileV2UseGlobalAvatars' ) ) {
			// This is a mess, kind of. If we are using global avatars, here we look up the centralid
			// which will be provided by CentralAuth if CentralAuth is enabled, or else it will be the local id
			// if not. This is the same as the else branch so is redundant really, but keep it here to support
			// CentralAuth, whilst still allowing the UserProfileV2UseGlobalAvatars flag
			$userId = $services->getCentralIdLookup()->centralIdFromLocalUser( $user );
		} else {
			$userId = $user->getId();
		}

		try {
			$this->createThumbnail(
				$this->mTempPath,
				$imageInfo,
				$avatarKey . '_' . $userId,
				self::AVATAR_SIZE,
				$config->get( 'UseImageMagick' ),
				$config->get( 'ImageMagickConvertCommand' )
			);
		} catch ( Exception $e ) {
			wfDebugLog( 'UserProfileV2', 'Avatar conversion failed: ' . $e->getMessage() );
			return Status::newFatal( 'userprofilev2-apierror-conversionfailed' );
		}

		$avatarBackend = new UserProfileV2AvatarBackend( 'upv2avatars' );

		foreach ( self::ALL_EXTENSIONS as $fileExtension ) {
			if ( $fileExtension === $ext ) {
				// Our brand new avatar; skip over it in order to _not_ delete it, obviously
				continue;
			}

			if ( $avatarBackend->fileExists( $avatarKey . '_', $userId, $fileExtension ) ) {
				$avatarBackend->getFileBackend()->quickDelete( [
					'src' => $avatarBackend->getPath( $avatarKey . '_', $userId, $fileExtension )
				] );
			}
		}

		if ( $config->get( 'UserProfileV2UseGlobalAvatars' ) ) {
			$key = $cache->makeGlobalKey( 'user', 'userprofilev2', 'avatar', $userId );
		} else {
			$key = $cache->makeKey( 'user', 'userprofilev2', 'avatar', $userId );
		}

		$cache->delete( $key );

		$this->mExtension = $ext;

		return Status::newGood();
	}

	public function checkWarnings( $user = null ): array {
		return [];
	}

	private function createThumbnail( $imageSrc, $imageInfo, $imgDest, $thumbWidth, $useImageMagick, $imageMagickConvertCommand ) {
		$backend = new UserProfileV2AvatarBackend( 'upv2avatars' );
		$fname = $backend->getContainerStoragePath();

		$fileBackend = $backend->getFileBackend();
		$status = $fileBackend->prepare( [ 'dir' => $fname ] );

		if ( !$status->isOK() ) {
			throw new Exception(
				wfMessage( 'backend-fail-internal', Status::wrap( $status )->getWikitext() )
			);
		}

		[ $origWidth, $origHeight, $typeCode ] = $imageInfo;

		if ( !isset( self::IMAGE_TYPES[$typeCode] ) ) {
			throw new Exception( "Unsupported image type: $typeCode" );
		}

		$outputFormat = self::IMAGE_TYPES[$typeCode];

		$tempOutput = MediaWikiServices::getInstance()->getTempFSFileFactory()
			->newTempFSFile( 'upv2avatar_', $outputFormat );

		if ( !$tempOutput ) {
			throw new Exception( 'Failed to create a temporary file' );
		}

		$tempOutputPath = $tempOutput->getPath();

		/**
		 * Shamefully copied from SocialProfile with overhaul to use Shellbox if available.
		 * if it's not available it will just run on the local machine which is probably not any
		 * better than using exec but its there for people who care about that stuff.
		 */
		if ( $useImageMagick ) {
			if ( $origWidth < $thumbWidth ) {
				$thumbWidth = $origWidth;
			}
			$thumbHeight = ( $thumbWidth * $origHeight / $origWidth );
			$border = '0x0';
			if ( $thumbHeight < $thumbWidth ) {
				$border = '0x' . (int)( ( $thumbWidth - $thumbHeight ) / 2 );
			}

			$tempInputName = 'input.' . $outputFormat;
			$tempOutputName = 'output.' . $outputFormat;

			$command = MediaWikiServices::getInstance()->getShellCommandFactory()
				->createBoxed( 'userprofilev2' )
				->disableNetwork()
				->routeName( 'userprofilev2-generatethumb' );

			$inputContent = file_get_contents( $imageSrc );
			if ( $inputContent === false ) {
				throw new Exception( 'Failed to read input file' );
			}

			$result = $command
				->params( [
					$imageMagickConvertCommand,
					$tempInputName,
					'-size', "{$thumbWidth}x{$thumbWidth}",
					'-resize', $thumbWidth,
					'-crop', "{$thumbWidth}x{$thumbWidth}+0+0",
					'-bordercolor', 'white',
					'-border', $border,
					'-quality', '100',
					$tempOutputName
				] )
				->inputFileFromString( $tempInputName, $inputContent )
				->outputFileToString( $tempOutputName ) // output to a file or it will be lost when shellbox closes
				->execute();

			if ( $result->getExitCode() !== 0 ) {
				throw new Exception( 'ImageMagick command failed: ' . $result->getStderr() );
			}

			// we need to get the output from the file because shellbox will delete the container after
			// the command is finished
			$outputContent = $result->getFileContents( $tempOutputName );
			if ( $outputContent === null ) {
				throw new Exception( 'Failed to get output file contents' );
			}

			if ( file_put_contents( $tempOutputPath, $outputContent ) === false ) {
				throw new Exception( 'Failed to write output file' );
			}
		} else {
			// ImageMagick is not enabled, so fall back to PHP's GD library
			switch ( $typeCode ) {
				case IMAGETYPE_GIF:
					$fullImage = AtEase::quietCall( 'imagecreatefromgif', $imageSrc );
					break;
				case IMAGETYPE_JPEG:
					$fullImage = AtEase::quietCall( 'imagecreatefromjpeg', $imageSrc );
					break;
				default:
					$fullImage = AtEase::quietCall( 'imagecreatefrompng', $imageSrc );
					break;
			}

			if ( !$fullImage ) {
				throw new Exception( 'Failed to decode the uploaded image' );
			}

			$scale = ( $thumbWidth / $origWidth );
			$newWidth = max( 1, (int)round( $origWidth * $scale ) );
			$newHeight = max( 1, (int)round( $origHeight * $scale ) );

			// Create our thumbnail size, so we can resize to this, and save it.
			$tnImage = imagecreatetruecolor( $newWidth, $newHeight );

			if ( $typeCode !== IMAGETYPE_JPEG ) {
				imagealphablending( $tnImage, false );
				imagesavealpha( $tnImage, true );
			}

			// Resize the image.
			imagecopyresampled(
				$tnImage,
				$fullImage,
				0, 0, 0, 0,
				$newWidth,
				$newHeight,
				$origWidth,
				$origHeight
			);

			if ( $typeCode === IMAGETYPE_GIF ) {
				$written = imagegif( $tnImage, $tempOutputPath );
			} elseif ( $typeCode === IMAGETYPE_JPEG ) {
				$written = imagejpeg( $tnImage, $tempOutputPath );
			} else {
				$written = imagepng( $tnImage, $tempOutputPath );
			}

			if ( !$written ) {
				throw new Exception( 'Failed to write the resized image' );
			}
		}

		$status = $fileBackend->quickStore( [
			'src' => $tempOutputPath,
			'dst' => $fname . '/' . $imgDest . '.' . $outputFormat
		] );

		if ( !$status->isOK() ) {
			throw new Exception(
				wfMessage( 'backend-fail-internal', Status::wrap( $status )->getWikitext() )
			);
		}
	}
}
