<?php

namespace MediaWiki\Skins\Mijovia;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\SiteStats\SiteStats;
use MediaWiki\Skin\Components\SkinComponentTableOfContents;
use MediaWiki\Skin\SkinMustache;
use MediaWiki\SpecialPage\SpecialPage;

class SkinMijovia extends SkinMustache {
	private ?array $mijoviaTheme = null;

	private function getMijoviaTheme(): array {
		return $this->mijoviaTheme ??= ThemeDesignerConfig::resolve(
			ThemeDesignerConfig::valuesFromRevision( ThemeDesignerConfig::revision() )
		);
	}

	/** @inheritDoc */
	public function initPage( OutputPage $out ) {
		parent::initPage( $out );
		$theme = $this->getMijoviaTheme();
		if ( $theme['css'] !== '' ) {
			$out->addHeadItem( 'mijovia-published-theme', Html::inlineStyle(
				$theme['css'], 'all', [ 'id' => 'mijovia-published-theme' ]
			) );
		}
		// Run before the body is parsed so a saved light preference appears on the first paint.
		$out->addHeadItem( 'mijovia-theme', Html::inlineScript( <<<'JS'
			(function () {
				var theme = 'dark';
				try {
					if ( localStorage.getItem( 'mijovia-theme' ) === 'light' ) {
						theme = 'light';
					}
				} catch ( e ) {
					// Storage may be disabled.
				}
				document.documentElement.setAttribute( 'data-theme', theme );
			}());
			JS
		) );
	}

	/** @inheritDoc */
	public function getTemplateData() {
		$data = parent::getTemplateData();
		$portlets = $data['data-portlets'];
		$views = [];
		foreach ( $portlets['data-views']['array-items'] ?? [] as $item ) {
			foreach ( $item['array-links'][0]['array-attributes'] ?? [] as $attribute ) {
				if ( $attribute['key'] === 'href' ) {
					$views[$item['id']] = $attribute['value'];
					break;
				}
			}
		}

		$returnTo = [ 'returnto' => $this->getTitle()->getPrefixedText() ];
		$defaultLogo = ThemeDesignerConfig::defaultLogo( $this->getConfig(), $data['data-logos'] ?? [] );
		$categories = $this->getOutput()->getCategoryLinks();
		$showHiddenCategories = $this->getTitle()->inNamespace( NS_CATEGORY ) ||
			MediaWikiServices::getInstance()->getUserOptionsLookup()
				->getBoolOption( $this->getUser(), 'showhiddencats' );
		$copyright = $this->getCopyright();
		$title = $this->getTitle();
		$signedInPage = $this->getUser()->isNamed();
		$homeLayout = $signedInPage && $data['is-mainpage'];
		$articleLayout = $signedInPage && !$homeLayout;
		$showArticleRail = $articleLayout && !$title->isSpecial( 'MijoviaThemeDesigner' );
		$talkPage = !$title->isTalkPage() ? $title->getTalkPageIfDefined() : null;
		$pageActions = $this->getPageActions( $portlets );
		$wikiNavigation = new WikiNavigation();
		$navigationSource = $this->msg( 'mijovia-wiki-navigation' )->inContentLanguage()->plain();

		$sections = $articleLayout
			? ( new SkinComponentTableOfContents( $this->getOutput() ) )->getTemplateData()['array-sections'] ?? []
			: [];

		$toolbox = [];
		foreach ( array_merge(
			[ $data['data-portlets-sidebar']['data-portlets-first'] ],
			$data['data-portlets-sidebar']['array-portlets-rest']
		) as $portlet ) {
			if ( ( $portlet['id'] ?? null ) === 'p-tb' ) {
				$toolbox = array_column( $portlet['array-items'], null, 'name' );
				break;
			}
		}
		$pageTools = array_intersect_key( array_flip(
			[ 'whatlinkshere', 'recentchangeslinked', 'upload', 'specialpages', 'print', 'permalink', 'info' ]
		), $toolbox + [ 'specialpages' => true ] );
		foreach ( $pageTools as $key => &$tool ) {
			$tool = $toolbox[$key] ?? [
				'html-item' => Html::rawElement( 'li', [], Html::element( 'a', [
					'href' => SpecialPage::getTitleFor( 'Specialpages' )->getLocalURL(),
				], $this->msg( 'specialpages' )->text() ) ),
			];
		}
		unset( $tool );
		$watchAction = null;
		foreach ( $portlets['data-actions']['array-items'] ?? [] as $item ) {
			if ( in_array( $item['name'], [ 'watch', 'unwatch' ], true ) ) {
				foreach ( $item['array-links'][0]['array-attributes'] ?? [] as $attribute ) {
					if ( $attribute['key'] === 'href' ) {
						$watchAction = [
							'url' => $attribute['value'],
							'label' => $item['array-links'][0]['text'],
						];
						break;
					}
				}
			}
		}

		$toolbarTools = [
			'watch' => [ 'watch', $watchAction['url'] ?? null, $watchAction['label'] ?? null ],
			'history' => [ 'mijovia-history', $views['ca-history'] ?? null ],
			'edit' => [ 'edit', $views['ca-ve-edit'] ?? $views['ca-edit'] ?? null ],
			'whatlinkshere' => [ 'whatlinkshere' ],
			'recentchangeslinked' => [ 'recentchangeslinked-toolbox' ],
			'upload' => [ 'upload' ],
			'permalink' => [ 'permalink' ],
			'info' => [ 'pageinfo-toolboxlink' ],
			'recentchanges' => [ 'recentchanges', SpecialPage::getTitleFor( 'Recentchanges' )->getLocalURL() ],
			'specialpages' => [ 'specialpages', SpecialPage::getTitleFor( 'Specialpages' )->getLocalURL() ],
			'watchlist' => [ 'mywatchlist', SpecialPage::getTitleFor( 'Watchlist' )->getLocalURL() ],
		];
		$toolCatalog = [];
		if ( $signedInPage ) {
			foreach ( $toolbarTools as $key => $tool ) {
				$url = $tool[1] ?? null;
				foreach ( $toolbox[$key]['array-links'][0]['array-attributes'] ?? [] as $attribute ) {
					if ( $attribute['key'] === 'href' ) {
						$url ??= $attribute['value'];
						break;
					}
				}
				$toolCatalog[] = [
					'key' => $key,
					'label' => $tool[2] ?? $this->msg( $tool[0] )->text(),
					'url' => $url,
				];
			}
		}

		return $data + [
			'signed-in-page' => $signedInPage,
			'signed-in-home' => $homeLayout,
			'signed-in-article' => $articleLayout,
			'show-article-rail' => $showArticleRail,
			'notifications' => $signedInPage ? $this->getNotificationData( $portlets ) : null,
			'page-action-views' => $pageActions['views'],
			'page-action-links' => $pageActions['actions'],
			'has-page-actions' => $pageActions['views']['html-items'] !== '' ||
				$pageActions['actions']['html-items'] !== '' ||
				!empty( $portlets['data-variants']['html-items'] ) ||
				!empty( $portlets['data-languages']['html-items'] ),
			'page-tools' => array_values( $pageTools ),
			'page-sections' => $sections,
			'has-page-sections' => $sections !== [],
			'has-normal-categories' => !empty( $categories['normal'] ),
			'normal-category-links' => $categories['normal'] ?? [],
			'category-count-label' => $this->msg( 'mijovia-in-categories' )
				->numParams( count( $categories['normal'] ?? [] ) )->text(),
			'talk-url' => $talkPage?->getLocalURL(),
			'watch-action' => $watchAction,
			'toolbar-tools' => $toolCatalog,
			'toolbar-specialpages-url' => SpecialPage::getTitleFor( 'Specialpages' )->getLocalURL(),
			'toolbar-preference' => $signedInPage ? MediaWikiServices::getInstance()->getUserOptionsLookup()
				->getOption( $this->getUser(), 'userjs-mijovia-toolbar' ) : null,
			'watchlist-url' => SpecialPage::getTitleFor( 'Watchlist' )->getLocalURL(),
			'recent-changes-url' => SpecialPage::getTitleFor( 'Recentchanges' )->getLocalURL(),
			'preferences-url' => SpecialPage::getTitleFor( 'Preferences' )->getLocalURL(),
			'has-page-categories' => !empty( $categories['normal'] ) ||
				( $showHiddenCategories && !empty( $categories['hidden'] ) ),
			'html-page-license' => $copyright,
			'has-page-license' => $copyright !== '',
			'article-count' => $this->getLanguage()->formatNum( SiteStats::articles() ),
			'html-wiki-navigation' => $wikiNavigation->render( $navigationSource ),
			'html-header-wiki-navigation' => $wikiNavigation->render( $navigationSource ),
			'community-logo' => $this->getMijoviaTheme()['hideLogo'] ? null :
				( $this->getMijoviaTheme()['logoUrl'] ?? $defaultLogo ),
			'no-community-logo' => $this->getMijoviaTheme()['hideLogo'],
			'default-community-logo' => $defaultLogo,
			'theme-designer-url' => $signedInPage && $this->getAuthority()->isAllowed( ThemeDesignerConfig::RIGHT )
				? SpecialPage::getTitleFor( 'MijoviaThemeDesigner' )->getLocalURL()
				: null,
			'all-pages-url' => SpecialPage::getTitleFor( 'AllPages' )->getLocalURL(),
			'login-url' => SpecialPage::getTitleFor( 'Userlogin' )->getLocalURL( $returnTo ),
			'register-url' => $this->getAuthority()->isAllowed( 'createaccount' )
				? SpecialPage::getTitleFor( 'CreateAccount' )->getLocalURL( $returnTo )
				: null,
			'edit-url' => $views['ca-ve-edit'] ?? $views['ca-edit'] ?? $views['ca-viewsource'] ?? null,
			'edit-label' => $this->msg( isset( $views['ca-viewsource'] ) ? 'viewsource' : 'edit' )->text(),
		];
	}

	private function getNotificationData( array $portlets ): ?array {
		$unread = 0;
		$hasEcho = false;
		foreach ( $portlets['data-notifications']['array-items'] ?? [] as $item ) {
			foreach ( $item['array-links'][0]['array-attributes'] ?? [] as $attribute ) {
				if ( $attribute['key'] === 'data-counter-num' ) {
					$hasEcho = true;
					$unread += (int)$attribute['value'];
				}
			}
		}
		if ( !$hasEcho ) {
			return null;
		}
		return [
			'count' => $unread ? $this->getLanguage()->formatNum( min( $unread, 99 ) ) . ( $unread > 99 ? '+' : '' ) : '',
			'url' => SpecialPage::getTitleFor( 'Notifications' )->getLocalURL(),
			'preferences-url' => SpecialPage::getTitleFor( 'Preferences' )->getLocalURL() . '#mw-prefsection-echo',
		];
	}

	private function getPageActions( array $portlets ): array {
		$views = array_column( $portlets['data-views']['array-items'] ?? [], null, 'id' );
		$actions = array_column( $portlets['data-actions']['array-items'] ?? [], null, 'name' );
		$associated = array_column( $portlets['data-associated-pages']['array-items'] ?? [], null, 'id' );
		$title = $this->getTitle();
		$purge = $actions['purge'] ?? null;
		if ( !$purge && isset( $views['ca-view'] ) && $title->exists() ) {
			$purge = [
				'html-item' => Html::rawElement( 'li', [ 'id' => 'ca-purge' ], Html::element( 'a', [
					'href' => $title->getLocalURL( [ 'action' => 'purge' ] ),
				], $this->msg( 'purge' )->text() ) ),
			];
		}
		$request = $this->getRequest();
		if ( $this->getContext()->getActionName() === 'view' &&
			!$request->getRawVal( 'diff' ) && !$request->getRawVal( 'oldid' )
		) {
			unset( $views['ca-view'] );
		}
		unset( $views['ca-ve-edit'] );

		// On a talk page, show the link back to the subject.
		$associatedPage = $title->isTalkPage()
			? ( $associated['ca-' . $title->getNamespaceKey()] ?? null )
			: ( $associated['ca-talk'] ?? null );
		$primary = array_filter( [
			$actions['move'] ?? null,
			$purge,
			$associatedPage,
			$actions['watch'] ?? $actions['unwatch'] ?? null,
		] );
		unset( $actions['move'], $actions['purge'], $actions['watch'], $actions['unwatch'] );

		$menu = [];
		foreach ( [ 'views' => $views, 'actions' => array_merge( $primary, $actions ) ] as $name => $items ) {
			$portlet = $portlets["data-$name"] ?? [ 'class' => 'mw-portlet' ];
			$portlet['html-items'] = implode( '', array_column( $items, 'html-item' ) );
			$portlet['class'] = str_replace( ' emptyPortlet', '', $portlet['class'] );
			if ( $portlet['html-items'] === '' ) {
				$portlet['class'] .= ' emptyPortlet';
			}
			$menu[$name] = $portlet;
		}
		return $menu;
	}
}
