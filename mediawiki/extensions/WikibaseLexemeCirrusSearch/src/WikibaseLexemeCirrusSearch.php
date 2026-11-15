<?php

namespace Wikibase\Lexeme\Search\Elastic;

use MediaWiki\MediaWikiServices;
use Psr\Container\ContainerInterface;
use Wikibase\Repo\Api\EntitySearchHelper;

/**
 * @license GPL-2.0-or-later
 */
class WikibaseLexemeCirrusSearch {

	public static function getFormSearchHelper( ?ContainerInterface $services = null ): EntitySearchHelper {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseLexemeCirrusSearch.FormSearchHelper' );
	}

	public static function getLexemeSearchHelper( ?ContainerInterface $services = null ): EntitySearchHelper {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseLexemeCirrusSearch.LexemeSearchHelper' );
	}

}
