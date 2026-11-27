<?php

use MediaWiki\MediaWikiServices;

class ShareHooks {
	public static function onSidebarBeforeOutput( Skin $skin, &$sidebar ) {
		$services = MediaWikiServices::getInstance();

		$config = $services->getConfigFactory()->makeConfig( 'Share' );
		$extensionAssetsPath = $config->get( 'ExtensionAssetsPath' );
		$shareEmail = $config->get( 'ShareEmail' );
		$shareFacebook = $config->get( 'ShareFacebook' );
		$shareLinkedIn = $config->get( 'ShareLinkedIn' );
		$sharePinterest = $config->get( 'SharePinterest' );
		$shareReddit = $config->get( 'ShareReddit' );
		$shareTelegram = $config->get( 'ShareTelegram' );
		$shareTumblr = $config->get( 'ShareTumblr' );
		$shareTwitter = $config->get( 'ShareTwitter' );
		$shareVK = $config->get( 'ShareVK' );
		$shareWeibo = $config->get( 'ShareWeibo' );
		$shareWhatsApp = $config->get( 'ShareWhatsApp' );
		$shareUseButtons = $config->get( 'ShareUseButtons' );

		// Get title
		$query = $skin->getRequest()->getQueryValues();
		if ( isset( $query['title'] ) ) {
			unset( $query['title'] );
		}

		// Allows us to get the full URL path without having to use $_SERVER and such
		$currenturl = $skin->getTitle()->getFullURL( $query, false, PROTO_CANONICAL );

		// Allow us to store preferences on users so that users can enable or disable the sidebar and to check if user has permission to see sidebar links
		$user = $skin->getUser();
		$permissionManager = $services->getPermissionManager();
		$userOptionsLookup = $services->getUserOptionsLookup();

		// Check if the user has the right to see the links
		if ( $permissionManager->userHasRight( $user, 'viewsharelinks' ) ) {
			// If Share is disabled in the User preferences then don't show (Default is enabled)
			if ( !$userOptionsLookup->getOption( $user, 'sharesidebar', 0 ) ) {
				// 'Plain Sidebar Links' mode (Default) - Displays all "Share" buttons as sidebar links
				if ( !$shareUseButtons ) {
					if ( $shareEmail ) {
						$sidebar['share-header'][] = [
							'text' => $skin->msg( 'share-email' )->escaped(),
							'href' => 'mailto:%20?body=' . urlencode( $currenturl ),
							'title' => $skin->msg( 'share-email' )->escaped(),
							'id' => 'n-share-email',
						];
					}

					if ( $shareFacebook ) {
						$sidebar['share-header'][] = [
							'text' => $skin->msg( 'share-facebook' )->escaped(),
							'href' => 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode( $currenturl ),
							'title' => $skin->msg( 'share-facebook' )->escaped(),
							'id' => 'n-share-facebook',
						];
					}

					if ( $shareLinkedIn ) {
						$sidebar['share-header'][] = [
							'text' => $skin->msg( 'share-linkedin' )->escaped(),
							'href' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . urlencode( $currenturl ),
							'title' => $skin->msg( 'share-linkedin' )->escaped(),
							'id' => 'n-share-linkedin',
						];
					}

					if ( $sharePinterest ) {
						$sidebar['share-header'][] = [
							'text' => $skin->msg( 'share-pinterest' )->escaped(),
							'href' => 'https://www.pinterest.com/pin/create/button/?url=' . urlencode( $currenturl ),
							'title' => $skin->msg( 'share-pinterest' )->escaped(),
							'id' => 'n-share-pinterest',
						];
					}

					if ( $shareReddit ) {
						$sidebar['share-header'][] = [
							'text' => $skin->msg( 'share-reddit' )->escaped(),
							'href' => 'https://www.reddit.com/submit?url=' . urlencode( $currenturl ),
							'title' => $skin->msg( 'share-reddit' )->escaped(),
							'id' => 'n-share-reddit',
						];
					}

					if ( $shareTelegram ) {
						$sidebar['share-header'][] = [
							'text' => $skin->msg( 'share-telegram' )->escaped(),
							'href' => 'https://t.me/share/url?url=' . urlencode( $currenturl ),
							'title' => $skin->msg( 'share-telegram' )->escaped(),
							'id' => 'n-share-telegram',
						];
					}

					if ( $shareTumblr ) {
						$sidebar['share-header'][] = [
							'text' => $skin->msg( 'share-tumblr' )->escaped(),
							'href' => 'https://www.tumblr.com/share/link?url=' . urlencode( $currenturl ),
							'title' => $skin->msg( 'share-tumblr' )->escaped(),
							'id' => 'n-share-tumblr',
						];
					}

					if ( $shareTwitter ) {
						$sidebar['share-header'][] = [
							'text' => $skin->msg( 'share-twitter' )->escaped(),
							'href' => 'https://www.twitter.com/share?url=' . urlencode( $currenturl ),
							'title' => $skin->msg( 'share-twitter' )->escaped(),
							'id' => 'n-share-twitter',
						];
					}

					if ( $shareVK ) {
						$sidebar['share-header'][] = [
							'text' => $skin->msg( 'share-vk' )->escaped(),
							'href' => 'https://vk.com/share.php?url=' . urlencode( $currenturl ),
							'title' => $skin->msg( 'share-vk' )->escaped(),
							'id' => 'n-share-vk',
						];
					}

					if ( $shareWeibo ) {
						$sidebar['share-header'][] = [
							'text' => $skin->msg( 'share-weibo' )->escaped(),
							'href' => 'https://service.weibo.com/share/share.php?url=' . urlencode( $currenturl ),
							'title' => $skin->msg( 'share-weibo' )->escaped(),
							'id' => 'n-share-weibo',
						];
					}

					if ( $shareWhatsApp ) {
						$sidebar['share-header'][] = [
							'text' => $skin->msg( 'share-whatsapp' )->escaped(),
							'href' => 'https://wa.me/?text=' . urlencode( $currenturl ),
							'title' => $skin->msg( 'share-whatsapp' )->escaped(),
							'id' => 'n-share-whatsapp',
						];
					}
				}

				// 'Sidebar images' mode - Display images saying "Share" instead of plain sidebar links
				if ( $shareUseButtons ) {
					if ( $shareEmail ) {
						$sidebar['share-header'][] = [
							'html' => '<a href="mailto:%20?body=' . urlencode( $currenturl ) . '"><img src="' . $extensionAssetsPath . '/Share/resources/images/email.png" alt="' . $skin->msg( 'share-email' )->escaped() . '" width="90" height="30"></a>',
						];
					}

					if ( $shareFacebook ) {
						$sidebar['share-header'][] = [
							'html' => '<a href="https://www.facebook.com/sharer/sharer.php?u=' . urlencode( $currenturl ) . '"><img src="' . $extensionAssetsPath . '/Share/resources/images/facebook.png" alt="' . $skin->msg( 'share-facebook' )->escaped() . '" width="90" height="30"></a>',
						];
					}

					if ( $shareLinkedIn ) {
						$sidebar['share-header'][] = [
							'html' => '<a href="https://www.linkedin.com/sharing/share-offsite/?url=' . urlencode( $currenturl ) . '"><img src="' . $extensionAssetsPath . '/Share/resources/images/linkedin.png" alt="' . $skin->msg( 'share-linkedin' )->escaped() . '" width="90" height="30"></a>',
						];
					}

					if ( $sharePinterest ) {
						$sidebar['share-header'][] = [
							'html' => '<a href="https://www.pinterest.com/pin/create/button/?url=' . urlencode( $currenturl ) . '"><img src="' . $extensionAssetsPath . '/Share/resources/images/pinterest.png" alt="' . $skin->msg( 'share-pinterest' )->escaped() . '" width="90" height="30"></a>',
						];
					}

					if ( $shareReddit ) {
						$sidebar['share-header'][] = [
							'html' => '<a href="https://www.reddit.com/submit?url=' . urlencode( $currenturl ) . '"><img src="' . $extensionAssetsPath . '/Share/resources/images/reddit.png" alt="' . $skin->msg( 'share-reddit' )->escaped() . '" width="90" height="30"></a>',
						];
					}

					if ( $shareTelegram ) {
						$sidebar['share-header'][] = [
							'html' => '<a href="https://t.me/share/url?url=' . urlencode( $currenturl ) . '"><img src="' . $extensionAssetsPath . '/Share/resources/images/telegram.png" alt="' . $skin->msg( 'share-telegram' )->escaped() . '" width="90" height="30"></a>',
						];
					}

					if ( $shareTumblr ) {
						$sidebar['share-header'][] = [
							'html' => '<a href="https://www.tumblr.com/share/link?url=' . urlencode( $currenturl ) . '"><img src="' . $extensionAssetsPath . '/Share/resources/images/tumblr.png" alt="' . $skin->msg( 'share-tumblr' )->escaped() . '" width="90" height="30"></a>',
						];
					}

					if ( $shareTwitter ) {
						$sidebar['share-header'][] = [
							'html' => '<a href="https://www.twitter.com/share?url=' . urlencode( $currenturl ) . '"><img src="' . $extensionAssetsPath . '/Share/resources/images/twitter.png" alt="' . $skin->msg( 'share-twitter' )->escaped() . '" width="90" height="30"></a>',
						];
					}

					if ( $shareVK ) {
						$sidebar['share-header'][] = [
							'html' => '<a href="https://vk.com/share.php?url=' . urlencode( $currenturl ) . '"><img src="' . $extensionAssetsPath . '/Share/resources/images/vk.png" alt="' . $skin->msg( 'share-vk' )->escaped() . '" width="90" height="30"></a>',
						];
					}

					if ( $shareWeibo ) {
						$sidebar['share-header'][] = [
							'html' => '<a href="https://service.weibo.com/share/share.php?url=' . urlencode( $currenturl ) . '"><img src="' . $extensionAssetsPath . '/Share/resources/images/weibo.png" alt="' . $skin->msg( 'share-weibo' )->escaped() . '" width="90" height="30"></a>',
						];
					}

					if ( $shareWhatsApp ) {
						$sidebar['share-header'][] = [
							'html' => '<a href="https://wa.me/?text=' . urlencode( $currenturl ) . '"><img src="' . $extensionAssetsPath . '/Share/resources/images/whatsapp.png" alt="' . $skin->msg( 'share-whatsapp' )->escaped() . '" width="90" height="30"></a>',
						];
					}
				}
			}
		}
	}

	public static function onGetPreferences( User $user, array &$preferences ) {
		$preferences['sharesidebar'] = [
			'type' => 'toggle',
			'label-message' => 'share-preftoggle',
			'section' => 'rendering',
		];
	}
}
