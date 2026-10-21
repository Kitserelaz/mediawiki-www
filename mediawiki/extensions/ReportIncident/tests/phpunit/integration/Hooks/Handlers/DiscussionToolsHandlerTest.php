<?php

namespace MediaWiki\Extension\ReportIncident\Tests\Integration\Hooks\Handlers;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReportIncident\Hooks\Handlers\DiscussionToolsHandler;
use MediaWiki\Extension\ReportIncident\Services\ReportIncidentController;
use MediaWikiIntegrationTestCase;

/**
 * @group ReportIncident
 *
 * @covers \MediaWiki\Extension\ReportIncident\Hooks\Handlers\DiscussionToolsHandler
 */
class DiscussionToolsHandlerTest extends MediaWikiIntegrationTestCase {
	/**
	 * @dataProvider provideOnDiscussionToolsAddOverflowMenuItems
	 */
	public function testOnDiscussionToolsAddOverflowMenuItems(
		bool $expectOverflowMenuItemAdded,
		bool $expectResourceLoaderModuleAdded,
		array $threadItemData,
		bool $shouldAddMenuItemMockResult
	) {
		// Skip the test if DiscussionTools is not loaded.
		$this->markTestSkippedIfExtensionNotLoaded( 'DiscussionTools' );
		$overflowMenuItems = [];
		$resourceLoaderModules = [];

		$contextSource = $this->createMock( IContextSource::class );
		$contextSource->method( 'msg' )->willReturn( 'Report' );

		$mockReportIncidentController = $this->createMock( ReportIncidentController::class );
		// Mock the return value of ::shouldAddMenuItem to be $shouldAddMenuItemMockResult
		$mockReportIncidentController->expects( $this->once() )
			->method( 'shouldAddMenuItem' )
			->with( $contextSource )
			->willReturn( $shouldAddMenuItemMockResult );

		( new DiscussionToolsHandler( $mockReportIncidentController ) )->onDiscussionToolsAddOverflowMenuItems(
			$overflowMenuItems,
			$resourceLoaderModules,
			$threadItemData,
			$contextSource
		);
		$found = false;
		foreach ( $overflowMenuItems as $overflowMenuItem ) {
			if ( $overflowMenuItem->getId() === 'reportincident' ) {
				$found = true;
			}
		}
		if ( $expectOverflowMenuItemAdded ) {
			$this->assertTrue( $found );
		} else {
			$this->assertFalse( $found );
		}
		if ( $expectResourceLoaderModuleAdded ) {
			$this->assertContains( 'ext.reportIncident', $resourceLoaderModules );
		} else {
			$this->assertNotContains( 'ext.reportIncident', $resourceLoaderModules );
		}
	}

	public static function provideOnDiscussionToolsAddOverflowMenuItems(): array {
		return [
			'Shows when ReportIncidentController::shouldAddMenuItem returns true' => [
				true,
				true,
				[ 'id' => 'foo' ],
				true,
			],
			'Does not show when ReportIncidentController::shouldAddMenuItem returns false' => [
				false,
				false,
				[],
				false,
			]
		];
	}
}
