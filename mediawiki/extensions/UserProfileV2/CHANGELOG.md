## Changelog

All notable changes to this project will be documented in this file.

## v1.1.0

### Security

* Profiles of users who have been suppressed no longer have their profile shown, or return data from the API, to anyone without the `hideuser` right.
* Profile field values are now validated by MediaWiki.
* Saving a profile through the API now requires the `editmyoptions` right when editing your own profile, and is rate limited (`userprofilev2-edit`).
* Edits to other users' profiles and avatar removals by profile managers are now logged to the wiki.
* Profile field keys must now start with `profile-`, to prevent a field overriding something from Core.
* Values substituted into a field's `url` template are now URL-encoded.
* Uploaded avatars are now checked against `$wgMaxUploadSize` and `$wgMaxImageArea` before being decoded to prevent memory exhaustion.
* Avatar files are now created with more locked down permissions, and temporary files are now uniquely named and cleaned up.

### Bug Fixes

* A profile manager removing another user's avatar no longer deletes their own avatar instead.
* A failed avatar conversion is now reported as an error and no longer removes the user's existing avatar.
* Invalid usernames passed to the API return an error instead of a fatal error.
* API errors are now shown in the profile and avatar dialogs.
* The avatar upload control is only shown on profiles if the owner is the viewer.
* The edit dialogs are only loaded for users who can edit the profile.

## v1.0.5

### Bug Fixes

* Replaced instances of CentralAuth lookup with `CentralIdLookup` to ensure that avatars work correctly when using non-CentralAuth global avatars (such as with `$wgSharedDB`)

## v1.0.4

### Bug Fixes

* `mw.hook('telepedia.upv2.profileSubmission')`: remove the hook added in v1.0.3 as that seems like a security issue. Potentially to be reintroduced in the future and restricted to those with the `profilemanager` right.

## v1.0.3

### New Features

* `mw.hook('telepedia.upv2.profileSubmission')`: added a hook that fires before the submission to the api for the profile contents. This receives the `formData` and can be used to modify the data sent to the API.

## [v1.0.2](https://gitlab.com/telepedia/extensions/userprofilev2/-/releases/v1.0.2)

### Bug Fixes

* CSS: fixed the CSS for the header, ensuring the contents are always centered vertically in relation to the avatar.
