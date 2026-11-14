<?php

namespace MediaWiki\Skins\Example;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/** Render the Fandom-style list stored in MediaWiki:Wiki-navigation. */
class WikiNavigation {
	private int $nextId = 0;

	public function render( string $source ): string {
		$items = [];
		foreach ( explode( "\n", $source ) as $line ) {
			if ( !preg_match( '/^(\*+)\s*(.*?)\s*$/u', trim( $line ), $match ) ) {
				continue;
			}
			[ $target, $label ] = array_pad( explode( '|', $match[2], 2 ), 2, null );
			$target = trim( $target );
			$label = $label === null ? null : trim( $label );
			if ( $target === '#newlychanged#' ) {
				$target = 'Special:RecentChanges';
				$label ??= 'Recently Changed Pages';
			} elseif ( preg_match( '/^#category([12])#$/', $target, $category ) ) {
				$name = $this->getCategories()[(int)$category[1] - 1] ?? null;
				if ( $name === null ) {
					continue;
				}
				$target = 'Category:' . $name;
				$label ??= str_replace( '_', ' ', $name );
			}
			$label ??= str_replace( '_', ' ', $target );
			if ( $label === '' || $target === '' ) {
				continue;
			}
			$url = null;
			if ( preg_match( '~^https?://~i', $target ) ) {
				$url = $target;
			} elseif ( $target !== '#' ) {
				// Title validation excludes unsafe schemes and invalid wiki targets.
				$title = Title::newFromText( $target );
				if ( !$title ) {
					continue;
				}
				$url = $title->getLocalURL();
			}
			$items[] = [ 'depth' => strlen( $match[1] ), 'label' => $label, 'url' => $url ];
		}
		$index = 0;
		$tree = $this->buildTree( $items, $index, 1 );
		array_unshift( $tree, [
			'label' => 'Explore', 'url' => null, 'explore' => true, 'children' => [
				$this->link( 'Main Page', Title::newMainPage()->getPrefixedText() ),
				$this->link( 'All Pages', 'Special:AllPages' ),
				$this->link( 'Recent Changes', 'Special:RecentChanges' ),
				$this->link( 'Random Page', 'Special:Random' ),
				$this->link( 'Categories', 'Special:Categories' ),
			]
		] );
		return Html::rawElement( 'nav', [
			'class' => 'fandom-community-header__local-navigation', 'aria-label' => 'Community navigation'
		], $this->renderList( $tree ) );
	}

	private function link( string $label, string $target ): array {
		return [ 'label' => $label, 'url' => Title::newFromText( $target )->getLocalURL(), 'children' => [] ];
	}

	private function getCategories(): array {
		$services = MediaWikiServices::getInstance();
		$cache = $services->getMainWANObjectCache();
		return $cache->getWithSetCallback( $cache->makeKey( 'example-navigation-categories' ), 300,
			static function () use ( $services ) {
				$db = $services->getConnectionProvider()->getReplicaDatabase();
				return $db->newSelectQueryBuilder()->select( 'cat_title' )->from( 'category' )
					->where( $db->expr( 'cat_pages', '>', 0 ) )
					->orderBy( 'cat_pages', 'DESC' )->orderBy( 'cat_title' )->limit( 2 )
					->caller( __METHOD__ )->fetchFieldValues();
			}
		);
	}

	private function buildTree( array $items, int &$index, int $depth ): array {
		$tree = [];
		while ( isset( $items[$index] ) && $items[$index]['depth'] >= $depth ) {
			$item = $items[$index++];
			$item['children'] = [];
			if ( isset( $items[$index] ) && $items[$index]['depth'] > $item['depth'] ) {
				$item['children'] = $this->buildTree( $items, $index, $items[$index]['depth'] );
			}
			$tree[] = $item;
		}
		return $tree;
	}

	private function icon( string $name, string $class = '' ): string {
		return Html::rawElement( 'svg', [
			'class' => trim( 'wds-icon wds-icon-tiny ' . $class ),
			'aria-hidden' => 'true', 'focusable' => 'false'
		], Html::element( 'use', [ 'xlink:href' => '#wds-icons-' . $name . '-tiny' ] ) );
	}

	private function renderList( array $items, int $level = 1 ): string {
		$html = '';
		foreach ( $items as $item ) {
			$children = $item['children'];
			$submenuId = $children ? 'wiki-navigation-' . ++$this->nextId : null;
			$explore = $item['explore'] ?? false;
			$label = Html::element( 'span', [], $item['label'] );
			$attributes = [ 'data-tracking' => $explore ? 'wiki-tools-menu' : 'custom-level-' . $level ];
			if ( $item['url'] !== null ) {
				$attributes['href'] = $item['url'];
			}
			if ( $children ) {
				$attributes += [ 'aria-expanded' => 'false', 'aria-controls' => $submenuId ];
				if ( $item['url'] === null ) {
					$attributes += [ 'role' => 'button', 'tabindex' => '0' ];
				}
			}
			if ( $level === 1 ) {
				$attributes['class'] = 'wds-tabs__tab-label wds-dropdown__toggle first-level-item';
				$label = Html::rawElement( 'div', [ 'class' => 'wds-dropdown__placeholder' ],
					( $explore ? $this->icon( 'book' ) : '' ) . $label );
				if ( $children ) {
					$label .= $this->icon( 'dropdown', 'wds-dropdown__toggle-chevron' );
				}
			} elseif ( $children ) {
				$attributes['class'] = $item['url'] === null
					? 'wds-dropdown__placeholder' : 'wds-dropdown-level-nested__toggle';
				$label .= $this->icon( 'menu-control', 'wds-dropdown-chevron' );
			}
			$label = Html::rawElement( $item['url'] !== null ? 'a' : 'div', $attributes, $label );
			$content = $children ? Html::rawElement( 'div', [
				'id' => $submenuId,
				'class' => 'wds-is-not-scrollable ' . ( $level === 1
					? 'wds-dropdown__content' : 'wds-dropdown-level-nested__content' )
			], $this->renderList( $children, $level + 1 ) ) : '';
			$html .= Html::rawElement( 'li', [ 'class' => $level === 1
				? 'wds-dropdown' . ( $explore ? ' explore-menu' : '' )
				: ( $children ? 'wds-dropdown-level-nested' : null )
			], $label . $content );
		}
		return Html::rawElement( 'ul', [ 'class' => $level === 1 ? 'wds-tabs' : 'wds-list wds-is-linked' ], $html );
	}
}
