<?php

namespace MediaWiki\Skins\Example;

use MediaWiki\SiteStats\SiteStats;
use MediaWiki\Skin\SkinMustache;

class SkinExample extends SkinMustache {
	/** @inheritDoc */
	public function getTemplateData() {
		return parent::getTemplateData() + [
			'article-count' => $this->getLanguage()->formatNum( SiteStats::articles() ),
			'html-wiki-navigation' => ( new WikiNavigation() )->render(
				$this->msg( 'wiki-navigation' )->inContentLanguage()->plain()
			),
		];
	}
}
