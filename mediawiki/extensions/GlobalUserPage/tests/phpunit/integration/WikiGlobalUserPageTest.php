<?php

namespace MediaWiki\GlobalUserPage\Tests\Integration;

use MediaWiki\GlobalUserPage\WikiGlobalUserPage;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\GlobalUserPage\WikiGlobalUserPage::getWikiDisplayName
 */
class WikiGlobalUserPageTest extends MediaWikiIntegrationTestCase {

	/** @dataProvider provideSourceURLs */
	public function testWikiDisplayName( string $url, string $expected ): void {
		$page = $this->createPartialMock( WikiGlobalUserPage::class, [ 'getSourceURL' ] );
		$page->method( 'getSourceURL' )->willReturn( $url );

		$this->assertSame( $expected, $page->getWikiDisplayName() );
	}

	public static function provideSourceURLs(): array {
		return [
			'https' => [ 'https://meta.cloudwiki.us/wiki/User:Example', 'meta.cloudwiki.us' ],
			'protocol relative' => [ '//meta.cloudwiki.us/wiki/User:Example', 'meta.cloudwiki.us' ],
			'unavailable source' => [ '', '' ],
		];
	}
}
