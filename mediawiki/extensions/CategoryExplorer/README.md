# mediawiki-extensions-CategoryExplorer

The CategoryExplorer extension replicates the $wgUseCategoryBrowser configuration variable that [was removed in 1.38](https://phabricator.wikimedia.org/T298553)
for sites that want to continue using the functionality.

The feature was originally [written by Antoine Musso in June 2004](https://www.mediawiki.org/wiki/Special:Code/MediaWiki/3888) in MediaWiki core
with the intent was to ease browsing through parent categories similar to how it was done on Dmoz back in the days.

It may be useful on smaller wikis  when there is just a few categories, making it easier to navigate / browse without
having having to rely on hand crafted navigation box.


# Install

```
wfLoadExtension('CategoryExplorer');
``` 

# Contribute

The extension is currently unmaintained, and will only be updated with Wikimedia best practices. If you are interested in maintaining this extension, and
growing its functionality, please open a Github issue or Phabricator ticket to request transfer of ownership.
