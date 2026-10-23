<?php
// Opt in only after copying existing uploads and verifying S3 access.
if ( getenv( 'MW_S3_ENABLED' ) !== '1' ) {
    return;
}

require_once __DIR__ . '/extensions/AWS/vendor/autoload.php';
wfLoadExtension( 'AWS' );
$wgAWSBucketName = getenv( 'MW_S3_BUCKET' ) ?: 'cloudwiki01';
$wgAWSRegion = getenv( 'MW_S3_REGION' ) ?: 'us-east-2';
$s3Prefix = trim( getenv( 'MW_S3_PREFIX' ) ?: '', '/' );
if ( $s3Prefix !== '' && !preg_match( '/^[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)*$/D', $s3Prefix ) ) {
    throw new RuntimeException( 'Invalid MW_S3_PREFIX' );
}
$wgAWSBucketTopSubdirectory = '/' . ( $s3Prefix === '' ? '' : "$s3Prefix/" ) . $wgDBname;
unset( $s3Prefix );
$wgAWSRepoHashLevels = 2;
$wgAWSRepoDeletedHashLevels = 3;
$wgAWSUseHTTPS = true;

// The SDK can also use its default credential chain (environment or IAM role).
$s3Key = $farmSecrets['aws_access_key_id'] ?? '';
$s3Secret = $farmSecrets['aws_secret_access_key'] ?? '';
if ( $s3Key !== '' || $s3Secret !== '' ) {
    if ( $s3Key === '' || $s3Secret === '' ) {
        throw new RuntimeException( 'S3 requires both aws_access_key_id and aws_secret_access_key' );
    }
    $wgAWSCredentials = [
        'key' => $s3Key,
        'secret' => $s3Secret,
        'token' => $farmSecrets['aws_session_token'] ?? false,
    ];
}
unset( $s3Key, $s3Secret );

// Keep the bucket private, including public wikis, deleted files and thumbnails.
// img_auth.php applies each wiki's read permissions to requests.
$wgFileBackends['s3']['privateWiki'] = true;
$wgUploadPath = "$wgScriptPath/img_auth.php";
$wgImgAuthPath = $wgUploadPath;

// UserProfileV2 stores avatars outside the normal file repository.
$wgUserProfileV2Backend = 'S3Avatars';
$wgFileBackends['s3-avatars'] = [
    'name' => 'S3Avatars',
    'class' => 'AmazonS3FileBackend',
    'domainId' => $wgDBname,
    'lockManager' => 'nullLockManager',
    'privateWiki' => true,
    'containerPaths' => [
        "$wgDBname-upv2avatars" => $wgAWSBucketName . $wgAWSBucketTopSubdirectory . '/upv2avatars',
    ],
];
$wgImgAuthUrlPathMap['/upv2avatars/'] = 'mwstore://S3Avatars/upv2avatars/';
