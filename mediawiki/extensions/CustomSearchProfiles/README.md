# CustomSearchProfile

This is an extension that allows you to add custom search profiles into
Special:Search. All extra search profiles are added before the "Everything"
profile.

## Configuration
* `$wgCustomSearchProfilesProfiles` (array): List of custom search profiles.
  Its keys are the profile's internal ID, and its values are objects. They are
  represented as follows:
  * `message` (string, optional): Localisation string used to display the
    profile in Special:Search. Defaults to `searchprofile-${key}`.
  * `tooltip` (string, optional): Localisation string used when the profile is
    hovered over in Special:Search. Defaults to `searchprofile-${key}-tooltip`.
  * `namespaces` (array of ints): Namespaces that this profile will search

### Example
```php
$wgCustomSearchProfilesProfiles = [
    'code' => [
        'namespaces' => [
            NS_TEMPLATE,
            828, // Module
        ],
    ],
    'mediawiki' => [
        'message' => 'searchprofile-sysmsgs',
        'tooltip' => 'searchprofile-sysmsgs-tooltip',
        'namespaces' => [ NS_MEDIAWIKI ],
    ],
];
```

## Credits
* [BoilerPlate]: Example extension that this extension is based off of

[BoilerPlate]: https://www.mediawiki.org/wiki/Extension:BoilerPlate
