A simple MediaWiki extension for rendering Snap<i>!</i> and Scratch Blocks. Supports MediaWiki 1.35+.

Transforms `<snapblocks>` `<scratchblocks>` tags inside wiki articles into `<pre class="blocks">` in the HTML, which are then rendered to scratch blocks using CSS and JS included in the page. Inline blocks are rendered with `<sb>` tags, and become `<code class="blocks">` tags.

Use `version` attribute to set the version. Valid values are, `snap`, `snap-flat`, `2`, `3`, and `3-hc`.

- Maintained by ego-lay_atman-bay and R4356th.
- Original by tjvr, ErnieParke, apple502j and Kenny2github.

# Installation

```bash
$ cd extensions
$ git clone http://github.com/snap-blocks/mw-snapblocks
```
After cloning, add the line
```php
wfLoadExtension( "mw-snapblocks" );
```
to your LocalSettings.php file. If you need to use languages besides English, add the following line (Japanese and Traditional Chinese used as an example):
```php
$wgScratchBlocks4Langs = ['ja', 'zh_TW'];
```
Note that the TW is preceded by an underscore rather than a hyphen.

This variable is accessible through JavaScript via `mw.config.get("wgSnapBlocksLangs")`.

Use `$wgSnapBlocksBlockVersion` to specify the default version. Valid options are, `snap`, `snap-flat`, `2`, `3`, and `3-hc`.

There are some other options
```php
$wgSnapBlocksWrap = true // Enable block wrapping. This is turned on by default (true).
$wgSnapBlocksWrapSize = 500 // Specify minimum width required for block wrapping. Set this to null for default width.
$wgSnapBlocksZebraColoring = true // Enable zebra coloring. This is turned on by default (true).
```
