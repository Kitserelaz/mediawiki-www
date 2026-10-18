## Changelog

All notable changes to this project will be documented in this file. 

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