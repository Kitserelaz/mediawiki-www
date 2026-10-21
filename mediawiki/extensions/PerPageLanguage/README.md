# PerPageLanguage

The extension automatically switches the interface
language when it detects that the page being visited
has a language code set in the database (via Special:PageLanguage).
It also adds a helper link (in the page tools) to set this page's
content language via the Special page.

The extension requires the `$wgPageLanguageUseDB` global to be set to `true`.

# Requirements

* MediaWiki 1.42+
* The extension requires the `$wgPageLanguageUseDB` global to be set to `true`

# Setup

* Clone to the `extensions` folder
* Add `wfLoadExtension('PerPageLanguage')` to the settings file

# Usage

* Change some page language to the desired one via `Special:PageLanguage`
* Visit the page and ensure your interface language is switched to the configured page language

# Settings

* `$wgPerPageLanguageIgnoreUserSetting` (default is `false`) - if to ignore the user's language
set in their preferences. By default, the extension will only switch the interface language to
the page language if it detects that the user language set matches the `$wgLanguageCode` setting.
Setting this to `true` will make the extension ignore the user setting and *always* switch the
interface language to the page language

