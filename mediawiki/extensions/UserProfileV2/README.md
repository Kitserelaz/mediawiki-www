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
* `wgUserProfileV2ProfileFields`: Ordered list of the fields shown in the profile editor and `Special:Preferences`. Leave empty to use the built-in defaults.

## Customising the profile fields

The fields handled by the extension come from `$wgUserProfileV2ProfileFields`.
Leave it empty to use the built-in defaults (about me, Discord, Twitter,
Mastodon, global groups and global edit count). When the list is not empty it
*replaces* the built-in list: list every field you want to show, in order, and
omit a field to hide it.

```php
$wgUserProfileV2ProfileFields = [
	'profile-aboutme',
	'profile-discord',
	'profile-twitter',

	[
		'key' => 'profile-github',
		'url' => 'https://github.com/$1',
		'icon' => '<?xml version="1.0" encoding="UTF-8"?><!-- GitHub icon from iconoir, licensed under the MIT license --><svg width="24px" height="24px" viewBox="0 0 24 24" stroke-width="1.5" fill="none" xmlns="http://www.w3.org/2000/svg" color="#000000"><path d="M16 22.0268V19.1568C16.0375 18.68 15.9731 18.2006 15.811 17.7506C15.6489 17.3006 15.3929 16.8902 15.06 16.5468C18.2 16.1968 21.5 15.0068 21.5 9.54679C21.4997 8.15062 20.9627 6.80799 20 5.79679C20.4558 4.5753 20.4236 3.22514 19.91 2.02679C19.91 2.02679 18.73 1.67679 16 3.50679C13.708 2.88561 11.292 2.88561 8.99999 3.50679C6.26999 1.67679 5.08999 2.02679 5.08999 2.02679C4.57636 3.22514 4.54413 4.5753 4.99999 5.79679C4.03011 6.81549 3.49251 8.17026 3.49999 9.57679C3.49999 14.9968 6.79998 16.1868 9.93998 16.5768C9.61098 16.9168 9.35725 17.3222 9.19529 17.7667C9.03334 18.2112 8.96679 18.6849 8.99999 19.1568V22.0268" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M9 20.0267C6 20.9999 3.5 20.0267 2 17.0267" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>',
	],

	'profile-show-globalgroups',
	'profile-show-globaledits',
];
```

This example shows how to remove Mastodon and add a new GitHub field. Every field `key` must start with `profile-`; fields that don't are ignored (and a warning is logged to the `UserProfileV2` channel) so that a profile field can never overwrite a preference from Core. Supported keys per field: `key`, `type` (`text`, `textarea` or `check`), `label`, `name`, `placeholder`, `help`, `icon`, `url` (a template containing `$1`), `tooltip`, `maxlength`, `rows`, `requiresCentralAuth` and `enabled`.

`label`, `name`, `placeholder` and `help` are optional; they fall back to messages built from the field key: the leading `profile-` is removed and the key is prefixed with `userprofilev2-`. For `profile-github` that gives:

* label: `userprofilev2-github`
* name: `userprofilev2-github-name` (falls back to `userprofilev2-github`)
* placeholder: `userprofilev2-github-placeholder`
* help: `userprofilev2-github-help`

`icon` accepts one of the bundled names (`twitter`, `mastodon` or `discord`) or a raw SVG string such as `<svg ...>...</svg>`.

`url` templates are only rendered as links when the result starts with an allowed protocol (`http`, `https` or `mailto`). Otherwise the field falls back to the tooltip rendering, so a template consisting of just `$1` cannot be used to inject a `javascript:` URL.

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

## Rate limits and logging

Saving a profile is rate limited through `$wgRateLimits['userprofilev2-edit']` (the default is 10 saves per minute for users, 5 for
new users).

When a user with the `profilemanager` right edits another user's profile or removes their
avatar, it is recorded in `Special:Log/userprofile`. This is a public log (for now, it may be made private eventually, if you are reading this and have strong thoughts about it, feel free to PR).

# Security Vulnerabilities

If you believe you have found a security vulnerability in any part of our code, please do not post it publicly by using
our wikis or bug trackers for that.

As a quick overview, please file a
task [here](https://telepedia.atlassian.net/servicedesk/customer/portal/1/group/1/create/4).
