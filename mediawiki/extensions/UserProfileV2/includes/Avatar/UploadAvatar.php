<?php

namespace Telepedia\UserProfileV2\Avatar;

use Exception;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use ObjectCache;
use UploadFromFile;

class UploadAvatar extends UploadFromFile {

	public $mExtension;

	public function performUpload( $comment, $pageText, $watch, $user, $tags = [], ?string $watchlistExpiry = null ) {
		$wgAvatarKey = 'avatar';

		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'UserProfileV2' );

		$cacheType = $config->get( 'UserProfileV2CacheType' );

		$cache = $cacheType ? ObjectCache::getInstance( $cacheType ) : ObjectCache::getLocalClusterInstance();

		$useImageMagick = $config->get( 'UseImageMagick' );
		$imageMagickConvertCommand = $config->get( 'ImageMagickConvertCommand' );
		/**
		 * The file is empty, return a fatal
		 */
		$imageInfo = getimagesize( $this->mTempPath );
		if ( empty( $imageInfo[2] ) ) {
			return Status::newFatal( 'empty-file' );
		}

		switch ( $imageInfo[2] ) {
			case 1:
				$ext = 'gif';
				break;
			case 2:
				$ext = 'jpg';
				break;
			case 3:
				$ext = 'png';
				break;
			default:
				return Status::newFatal( 'filetype-banned' );
		}

		if ( $config->get( 'UserProfileV2UseGlobalAvatars' ) ) {
			// This is a mess, kind of. If we are using global avatars, here we look up the centralid
			// which will be provided by CentralAuth if CentralAuth is enabled, or else it will be the local id
			// if not. This is the same as the else branch so is redundant really, but keep it here to support
			// CentralAuth, whilst still allowing the UserProfileV2UseGlobalAvatars flag
			$lookup = MediaWikiServices::getInstance()->getCentralIdLookup();
			$userId = $lookup->centralIdFromLocalUser( $user );
		} else {
			$userId = $user->getId();
		}

		$userProfileAvatar = new UserProfileV2Avatar( $userId );

		$this->createThumbnail( $this->mTempPath, $imageInfo, $wgAvatarKey . '_' . $userId, 138, $useImageMagick, $imageMagickConvertCommand );

		$extensions = [ 'jpg', 'gif', 'png', 'jpeg', 'webp' ];

		foreach ( $extensions as $fileExt ) {
			if ( $ext != $fileExt ) {
				$filePath = wfTempDir() . "/{$wgAvatarKey}_{$userId}.{$fileExt}";
				if ( is_file( $filePath ) ) {
					unlink( $filePath );
				}
			}
		}

		$avatarBackend = new UserProfileV2AvatarBackend( 'upv2avatars' );

		foreach ( [ 'gif', 'jpg', 'jpeg', 'png' ] as $fileExtension ) {
			if ( $fileExtension === $ext ) {
				// Our brand new avatar; skip over it in order to _not_ delete it, obviously
			} else {
				if ( $avatarBackend->fileExists( $wgAvatarKey . '_', $userId, $fileExtension ) ) {
					$avatarBackend->getFileBackend()->quickDelete( [
						'src' => $avatarBackend->getPath( $wgAvatarKey . '_', $userId, $fileExtension )
					] );
				}
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

	public function checkWarnings( $user = null ) {
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

		/**
		 * Shamefully copied from SocialProfile with overhaul to use Shellbox if available.
		 * if it's not available it will just run on the local machine which is probably not any
		 * better than using exec but its there for people who care about that stuff.
		 */

		if ( $useImageMagick ) {
			[ $origWidth, $origHeight, $typeCode ] = $imageInfo;

			if ( $origWidth < $thumbWidth ) {
				$thumbWidth = $origWidth;
			}
			$thumbHeight = ( $thumbWidth * $origHeight / $origWidth );
			$border = '0x0';
			if ( $thumbHeight < $thumbWidth ) {
				$border = '0x' . (int)( ( $thumbWidth - $thumbHeight ) / 2 );
			}

			$outputFormats = [
				1 => 'gif',
				2 => 'jpg',
				3 => 'png'
			];

			if ( !isset( $outputFormats[$typeCode] ) ) {
				throw new Exception( "Unsupported image type: $typeCode" );
			}

			$outputFormat = $outputFormats[$typeCode];
			$tempInputName = 'input_' . $imgDest . '.' . $outputFormat;
			$tempOutputName = 'output_' . $imgDest . '.' . $outputFormat;

			try {
				$command = MediaWikiServices::getInstance()->getShellCommandFactory()
					->createBoxed( 'userprofilev2' )
					->disableNetwork()
					->routeName( 'userprofilev2-generatethumb' );

				// Read the input file content
				$inputContent = file_get_contents( $imageSrc );
				if ( $inputContent === false ) {
					throw new Exception( "Failed to read input file" );
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
					throw new Exception( "ImageMagick command failed: " . $result->getStderr() );
				}

				// we need to get the output from the file because shellbox will delete the container after
				// the command is finished
				$outputContent = $result->getFileContents( $tempOutputName );
				if ( $outputContent === null ) {
					throw new Exception( "Failed to get output file contents" );
				}

				// Write the output to a temporary file since
				$tempOutputPath = wfTempDir() . '/' . $tempOutputName;
				if ( file_put_contents( $tempOutputPath, $outputContent ) === false ) {
					throw new Exception( "Failed to write output file" );
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
			} catch ( Exception $e ) {
				wfDebug( 'Image conversion failed: ' . $e->getMessage() );
			}
		} else {
			// ImageMagick is not enabled, so fall back to PHP's GD library
			// Get the image size, used in calculations later.
			// not sure this can be converted to shellbox but ah
			[ $origWidth, $origHeight, $typeCode ] = getimagesize( $imageSrc );

			$fullImage = '';
			$ext = '';

			switch ( $typeCode ) {
				case '1':
					$fullImage = imagecreatefromgif( $imageSrc );
					$ext = 'gif';
					break;
				case '2':
					$fullImage = imagecreatefromjpeg( $imageSrc );
					$ext = 'jpg';
					break;
				case '3':
					$fullImage = imagecreatefrompng( $imageSrc );
					$ext = 'png';
					break;
			}

			$scale = ( $thumbWidth / $origWidth );

			// Create our thumbnail size, so we can resize to this, and save it.
			$tnImage = imagecreatetruecolor(
				$origWidth * $scale,
				$origHeight * $scale
			);

			// Resize the image.
			imagecopyresampled(
				$tnImage,
				$fullImage,
				0, 0, 0, 0,
				$origWidth * $scale,
				$origHeight * $scale,
				$origWidth,
				$origHeight
			);

			// Create a new image thumbnail.
			if ( $typeCode == 1 ) {
				imagegif( $tnImage, $imageSrc );
			} elseif ( $typeCode == 2 ) {
				imagejpeg( $tnImage, $imageSrc );
			} elseif ( $typeCode == 3 ) {
				imagepng( $tnImage, $imageSrc );
			}

			// Clean up.
			imagedestroy( $fullImage );
			imagedestroy( $tnImage );

			// Copy the thumb
			copy(
				$imageSrc,
				wfTempDir() . '/' . $imgDest . '.' . $ext
			);

			$status = $fileBackend->quickStore( [
				'src' => wfTempDir() . '/' . $imgDest . '.' . $ext,
				'dst' => $fname . '/' . $imgDest . '.' . $ext
			] );

			if ( !$status->isOK() ) {
				throw new Exception(
					wfMessage( 'backend-fail-internal', Status::wrap( $status )->getWikitext() )
				);
			}
		}
	}
}