<?php

namespace MediaWiki\Skins\Example;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\SiteStats\SiteStats;
use MediaWiki\Skin\Components\SkinComponentTableOfContents;
use MediaWiki\Skin\SkinMustache;
use MediaWiki\SpecialPage\SpecialPage;

class SkinExample extends SkinMustache {
	/** @inheritDoc */
	public function initPage( OutputPage $out ) {
		parent::initPage( $out );
		// Run before the body is parsed so a saved light preference appears on the first paint.
		$out->addHeadItem( 'example-theme', Html::inlineScript( <<<'JS'
			(function () {
				var theme = 'dark';
				try {
					if ( localStorage.getItem( 'example-theme' ) === 'light' ) {
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
		$resources = $this->getConfig()->get( 'StylePath' ) . '/Example/resources/assets';
		$categories = $this->getOutput()->getCategoryLinks();
		$showHiddenCategories = $this->getTitle()->inNamespace( NS_CATEGORY ) ||
			MediaWikiServices::getInstance()->getUserOptionsLookup()
				->getBoolOption( $this->getUser(), 'showhiddencats' );
		$copyright = $this->getCopyright();
		$title = $this->getTitle();
		$signedIn = $this->getUser()->isRegistered() && !$this->getUser()->isTemp();
		$articleLayout = $signedIn && $data['is-article'] && $title->exists() &&
			!$title->isTalkPage() && !$title->isMainPage() &&
			$this->getRequest()->getVal( 'action', 'view' ) === 'view' &&
			!$this->getRequest()->getCheck( 'printable' );

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

		$railItem = function ( string $name, string $icon, ?string $url = null, string $class = '' ): array {
			return [
				'label' => $this->msg( "example-$name" )->text(),
				'class' => 'global-explore-navigation__' . ( $class ?: $name ),
				'link-class' => 'global-explore-navigation__' . $name . '-link',
				'icon' => $icon,
				'url' => $url,
			];
		};

		return $data + [
			'signed-in-article' => $articleLayout,
			'page-tools' => array_values( $pageTools ),
			'page-sections' => $sections,
			'has-page-sections' => $sections !== [],
			'normal-category-count' => !empty( $categories['normal'] ),
			'category-count-label' => $this->msg( 'example-in-categories' )
				->numParams( count( $categories['normal'] ?? [] ) )->text(),
			'talk-url' => $articleLayout ? $title->getTalkPage()->getLocalURL() : null,
			'watch-action' => $watchAction,
			'watchlist-url' => SpecialPage::getTitleFor( 'Watchlist' )->getLocalURL(),
			'recent-changes-url' => SpecialPage::getTitleFor( 'Recentchanges' )->getLocalURL(),
			'preferences-url' => SpecialPage::getTitleFor( 'Preferences' )->getLocalURL(),
			'has-page-categories' => !empty( $categories['normal'] ) ||
				( $showHiddenCategories && !empty( $categories['hidden'] ) ),
			'html-page-license' => $copyright,
			'has-page-license' => $copyright !== '',
			'article-count' => $this->getLanguage()->formatNum( SiteStats::articles() ),
			'html-wiki-navigation' => ( new WikiNavigation() )->render(
				$this->msg( 'wiki-navigation' )->inContentLanguage()->plain()
			),
			'community-logo' => $data['data-logos']['icon'] ?? $resources . '/earferana-logo.webp',
			'all-pages-url' => SpecialPage::getTitleFor( 'AllPages' )->getLocalURL(),
			'login-url' => SpecialPage::getTitleFor( 'Userlogin' )->getLocalURL( $returnTo ),
			'register-url' => $this->getAuthority()->isAllowed( 'createaccount' )
				? SpecialPage::getTitleFor( 'CreateAccount' )->getLocalURL( $returnTo )
				: null,
			'edit-url' => $views['ca-ve-edit'] ?? $views['ca-edit'] ?? $views['ca-viewsource'] ?? null,
			'edit-label' => $this->msg( isset( $views['ca-viewsource'] ) ? 'viewsource' : 'edit' )->text(),
			'rail-top' => [
				$railItem( 'home', 'wds-icons-home-small', $data['link-mainpage'] ),
				$railItem( 'explore', 'example-compass', SpecialPage::getTitleFor( 'AllPages' )->getLocalURL() ),
				$railItem( 'saved', 'wds-icons-ribbon-small' ),
				...( $articleLayout ? [ $railItem( 'ranks', 'example-ranks' ) ] : [] ),
				$railItem( 'progress', 'wds-icons-checkbox-circle-small' ),
				$railItem( 'history', 'wds-icons-history-small', $views['ca-history'] ?? null ),
				$railItem( 'utilities', 'wds-icons-grid-utils',
					SpecialPage::getTitleFor( 'Specialpages' )->getLocalURL(), 'utilities-hub' ),
			],
			'rail-utility' => [ $railItem( 'network', 'wds-icons-grid-small', null, 'utility' ) ],
			'rail-bottom' => [
				$railItem( 'settings', 'wds-icons-gear-small', $data['is-anon'] ? null :
					SpecialPage::getTitleFor( 'Preferences' )->getLocalURL(), 'settings-menu' ),
				$railItem( 'more', 'wds-icons-more-small' ),
			],
		];
	}
}
