<?php

namespace WikiOasis\WikiOasisMagic;

class SpecialCreateNewWikiLegacy extends CreateNewWikiForm {

	protected const PAGE_NAME = 'CreateNewWikiLegacy';

	public function isListed(): bool {
		return false;
	}
}
