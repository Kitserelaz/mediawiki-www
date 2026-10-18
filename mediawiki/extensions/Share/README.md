# Share
Share inserts links or buttons on the sidebar of wiki pages which allows users to easily share wiki articles on a variety of platforms, including:

* Email
* Facebook
* LinkedIn
* Pinterest
* Reddit
* Telegram
* Tumblr
* Twitter
* VK
* Weibo
* WhatsApp

For more information, see the extension's page at: [www.mediawiki.org/wiki/Extension:Share](https://www.mediawiki.org/wiki/Extension:Share).

# Installation
As with most MediaWiki installations, just simply clone this repository into your `extensions` folder:

`git clone https://gerrit.wikimedia.org/r/mediawiki/extensions/Share`

# Configuration
Share allows wiki administrators to configure the look of their sidebar share links/buttons and select what platforms you do or do not want to show up on your sidebar.

Share has two modes:
1. Plain links mode (Default) - Sidebar share links appear as plain sidebar links, no different from sidebar links such as the ones going to the main page or to the recent changes page.
2. Buttons mode (`$wgShareUseButtons`) - Sidebar share links appear as buttons users can click instead of average plain links.

By default, only Facebook and Twitter share links are enabled. These are governed by the following configuration variables:

`$wgShareFacebook` - Show "Share to Facebook" option in sidebar?
`$wgShareTwitter` - Show "Share to Twitter" option in sidebar?

All other share options must be enabled through configuration changes. The following configuration variables all default to false and are boolean:

`$wgShareEmail` - Show "Share via email" option in sidebar?

`$wgShareLinkedIn` - Show "Share to LinkedIn" option in sidebar?

`$wgSharePinterest` - Show "Share to Pinterest" option in sidebar?

`$wgShareReddit` - Show "Share to Reddit" option in sidebar?

`$wgShareTelegram` - Show "Share to Telegram" option in sidebar?

`$wgShareTumblr` - Show "Share to Tumblr" option in sidebar?

`$wgShareVK` - Show "Share to VK" option in sidebar?

`$wgShareWeibo` - Show "Share to Weibo" option in sidebar?

`$wgShareWhatsApp` - Show "Share to WhatsApp" option in sidebar?
