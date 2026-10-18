[![Gitlab Contributors](https://img.shields.io/gitlab/contributors/telepedia%2Fextensions%2Fuserprofilev2?style=flat-square&logo=gitlab)]() [![License: GPL v2](https://img.shields.io/badge/License-GPL_v2-blue.svg?style=flat-square)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html) [![MediaWiki: >=1.41](https://img.shields.io/badge/MediaWiki-%3E%3D1.41-%2336c?style=flat-square&logo=Wikipedia)](https://www.mediawiki.org)

UserProfileV2 is a MediaWiki extension for displaying User Profiles in MediaWiki. It was built to replace SocialProfile,
which is a big mess, hence the name "V2" — could've just called it SocialProfileV2?

The extension is intended to work on WikiFarms, such as Telepedia and Miraheze, but will work on a stand-alone wiki. It
also integrates with CentralAuth to fetch global edit counts and global user rights. Again, it will work without
CentralAuth and will just fetch local groups.

# Configuration

Firstly load the extension using `wfLoadExtension( 'UserProfileV2' )`. There are some configuration options to make your
life easier:

* `wgUserProfileV2Color`: The secondary colour to use for the background colour on things like group tags and buttons.
* `wgUserProfileV2AvatarBorderRadius`: The border radius of the avatar.
* `wgUserProfileV2Backend`: The backend to use for avatars (see below).

## Backend

UserProfileV2 will use the backend defined in `$wgUserProfileV2Backend` if it is set, which allows you to use a backend
such as S3 or Swift. This must correspond to a backend registered with `$wgFileBackend` such as

```php
$wgFileBackends[] = [
	'class'              => SwiftFileBackend::class,
	'name'               => 'userprofilev2',
	'wikiId'             => $wgDBname,
	// more configuration here
];
```

It is possible to have global avatars (instead of each wiki using its own avatars) if you
set `$wgFileBackends` to something like:

```php
$wgFileBackends[] = [
	'class' => 'AmazonS3FileBackend',
	'name' => 'telepedia-userprofile',
	'region' => 'eu-west-2',
	'wikiId' => 'global',
	'lockManager' => 'nullLockManager',
	'connTimeout' => 10,
	'reqTimeout' => 900,
	'containerPaths' => [
		"global-upv2avatars" => "static-test.telepedia.net/upv2avatars"
	],
];
```

I would recommend using the AWS extension. You will also need to set stuff like `$wgAWSBucketName`. When using global
avatars you must provide the base url for use in constructing the avatars,
ie: `$wgUserProfileGlobalUploadBaseUrl = 'https://s3.eu-west-2.amazonaws.com/static-test.telepedia.net';
`; the path to the file will be appended automatically.

If you do not provide `$wgUserProfileV2Backend` then the extension will construct a `new FSFileBackend([])` with
configuration for individual wiki avatars.

# Security Vulnerabilities

If you believe you have found a security vulnerability in any part of our code, please do not post it publicly by using
our wikis or bug trackers for that.

As a quick overview, please file a
task [here](https://telepedia.atlassian.net/servicedesk/customer/portal/1/group/1/create/4).
