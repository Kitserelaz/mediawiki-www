<?php

namespace MediaWiki\Extension\CustomSearchProfiles\Tests;

use MediaWiki\Extension\CustomSearchProfiles\Hooks;

/**
 * @coversDefaultClass \MediaWiki\Extension\CustomSearchProfiles\Hooks
 */
class HooksTest extends \MediaWikiUnitTestCase {

	/**
	 * @covers ::onSpecialSearchProfiles
	 */
	public function testOnSpecialSearchProfiles() {
		$config = new \HashConfig( [
			'CustomSearchProfilesProfiles' => [
				'templates' => [
					'namespaces' => [ NS_TEMPLATE ],
				],
				'nonmainspace' => [
					'message' => 'nonmainspace-text',
					'tooltip' => 'nonmainspace-tooltip',
					'namespaces' => [ NS_MEDIAWIKI, NS_USER, NS_TEMPLATE, NS_HELP, NS_FILE, NS_CATEGORY ],
				],
			],
		] );
		MediaWikiTestCase::installMockMwServices( $config );

		$profiles = [
			'default' => [
				'message' => 'searchprofile-articles',
				'tooltip' => 'searchprofile-articles-tooltip',
				'namespaces' => [ NS_MAIN ],
			],
			'all' => [
				'message' => 'searchprofile-advanced',
				'tooltip' => 'searchprofile-advanced-tooltip',
				'namespaces' => 'sense',
			],
		];
		( new Hooks )->onSpecialSearchProfiles( $profiles );

		$this->assertSame( [
			'default' => [
				'message' => 'searchprofile-articles',
				'tooltip' => 'searchprofile-articles-tooltip',
				'namespaces' => [ NS_MAIN ],
			],
			'templates' => [
				'message' => 'searchprofile-templates',
				'tooltip' => 'searchprofile-templates-tooltip',
				'namespaces' => [ NS_TEMPLATE ],
			],
			'nonmainspace' => [
				'message' => 'nonmainspace-text',
				'tooltip' => 'nonmainspace-tooltip',
				'namespaces' => [ NS_MEDIAWIKI, NS_USER, NS_TEMPLATE, NS_HELP, NS_FILE, NS_CATEGORY ],
			],
			'all' => [
				'message' => 'searchprofile-advanced',
				'tooltip' => 'searchprofile-advanced-tooltip',
				'namespaces' => 'sense',
			],
		], $profiles );
	}

}
