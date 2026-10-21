<?php

namespace MediaWiki\Extension\ReportIncident\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReportIncident\Hooks\Handlers\MainHooksHandler;
use MediaWiki\Extension\ReportIncident\Services\ReportIncidentController;
use MediaWiki\Message\Message;
use MediaWiki\Output\OutputPage;
use MediaWikiUnitTestCase;
use Skin;

/**
 * @group ReportIncident
 *
 * @covers \MediaWiki\Extension\ReportIncident\Hooks\Handlers\MainHooksHandler
 */
class MainHooksHandlerTest extends MediaWikiUnitTestCase {

	public function testOnBeforePageDisplayNotShowingButton() {
		// Create a mock IContextSource that will be passed
		// as an argument to the hook and expected to be passed
		// to the mock ReportIncidentController::shouldAddMenuItem.
		$mockContext = $this->createMock( IContextSource::class );
		$outputPageMock = $this->createMock( OutputPage::class );
		$outputPageMock->expects( $this->once() )
			->method( 'getContext' )->willReturn( $mockContext );
		// Expect that no HTML is added to the output.
		$outputPageMock->expects( $this->never() )
			->method( 'addHTML' );

		$mockReportIncidentController = $this->createMock( ReportIncidentController::class );
		// Mock that the ::shouldAddMenuItem method will return false.
		$mockReportIncidentController->expects( $this->once() )
			->method( 'shouldAddMenuItem' )
			->with( $mockContext )
			->willReturn( false );

		( new MainHooksHandler( $mockReportIncidentController ) )
			->onBeforePageDisplay( $outputPageMock, $this->createMock( Skin::class ) );
	}

	public function testOnBeforePageDisplayShowingButton() {
		// Create a mock IContextSource that will be passed
		// as an argument to the hook and expected to be passed
		// to the mock ReportIncidentController::shouldAddMenuItem.
		$mockContext = $this->createMock( IContextSource::class );
		$outputPageMock = $this->createMock( OutputPage::class );
		$outputPageMock->expects( $this->once() )
			->method( 'getContext' )->willReturn( $mockContext );
		// Expect that HTML is added to the output.
		$outputPageMock->expects( $this->once() )
			->method( 'addHTML' );

		$mockReportIncidentController = $this->createMock( ReportIncidentController::class );
		// Mock that the ::shouldAddMenuItem method will return true.
		$mockReportIncidentController->expects( $this->once() )
			->method( 'shouldAddMenuItem' )
			->with( $mockContext )
			->willReturn( true );

		( new MainHooksHandler( $mockReportIncidentController ) )
			->onBeforePageDisplay( $outputPageMock, $this->createMock( Skin::class ) );
	}

	/** @dataProvider provideToolLinksMethodNames */
	public function testToolLinksNotAdded( $method ) {
		// Mock the Skin class to be provided as an argument to the hook handler method
		$mockSkin = $this->createMock( Skin::class );
		// Define the skin name as 'minerva' for ::onSidebarBeforeOutput.
		$mockSkin->method( 'getSkinName' )
			->willReturn( 'minerva' );
		// Mock that the skin returns a mock IContextSource that will be expected
		// to be passed to ReportIncidentController::shouldAddMenuItem
		$mockContext = $this->createMock( IContextSource::class );
		$mockSkin->expects( $this->once() )
			->method( 'getContext' )
			->willReturn( $mockContext );

		$mockReportIncidentController = $this->createMock( ReportIncidentController::class );
		// Mock that the ::shouldAddMenuItem method returns false.
		$mockReportIncidentController->expects( $this->once() )
			->method( 'shouldAddMenuItem' )
			->with( $mockContext )
			->willReturn( false );
		// Expect that the ::addModulesAndConfigVars is never called
		$mockReportIncidentController->expects( $this->never() )
			->method( 'addModulesAndConfigVars' );

		$objectUnderTest = new MainHooksHandler( $mockReportIncidentController );
		$sidebar = [];
		$objectUnderTest->$method( $mockSkin, $sidebar );
		$this->assertArrayEquals(
			[],
			$sidebar,
			true,
			true,
			"The tool links should not be added by MainHooksHandler::$method if the " .
			'ReportIncidentController::shouldAddMenuItem method returns false.'
		);
	}

	public static function provideToolLinksMethodNames() {
		return [
			'Hooks::onSkinTemplateNavigation__Universal' => [ 'onSkinTemplateNavigation__Universal' ],
			'Hooks::onSidebarBeforeOutput' => [ 'onSidebarBeforeOutput' ],
		];
	}

	/** @dataProvider provideNonMinervaSkinNames */
	public function testOnSidebarBeforeOutputOnlyUsedForMinervaSkin( $skinName ) {
		// Mock the Skin class to be provided as an argument to the hook handler method
		$mockSkin = $this->createMock( Skin::class );
		// Define the skin name.
		$mockSkin->method( 'getSkinName' )
			->willReturn( $skinName );

		$mockReportIncidentController = $this->createMock( ReportIncidentController::class );
		// Expect that the ::shouldAddMenuItem is never called as the
		// skin name should be checked before calling this method.
		$mockReportIncidentController->expects( $this->never() )
			->method( 'shouldAddMenuItem' );
		// Expect that the ::addModulesAndConfigVar is never called.
		$mockReportIncidentController->expects( $this->never() )
			->method( 'addModulesAndConfigVars' );

		$objectUnderTest = new MainHooksHandler( $mockReportIncidentController );
		$sidebar = [];
		$objectUnderTest->onSidebarBeforeOutput( $mockSkin, $sidebar );
		$this->assertArrayEquals(
			[],
			$sidebar,
			true,
			true,
			'The MainHooksHandler::onSidebarBeforeOutput method should not modify the sidebar array if ' .
			'the skin is not minerva.'
		);
	}

	public static function provideNonMinervaSkinNames() {
		return [
			'Vector' => [ 'vector' ],
			'Timeless' => [ 'timeless' ],
			'Random string' => [ 'testing1234' ],
		];
	}

	/** @dataProvider provideToolLinksAddedWhenCorrectNamespaceAndSkin */
	public function testToolLinksAddedWhenDisplayingButton( $method, $expectedSidebar ) {
		// Mock the Skin object that will be provided as an argument to the method under test.
		$mockSkin = $this->createMock( Skin::class );
		// Mock the skin name to be 'minerva' as both methods add items for that skin.
		$mockSkin->method( 'getSkinName' )
			->willReturn( 'minerva' );
		// Mock the OutputPage object returned by the Skin::getOutput method
		$mockOutput = $this->createMock( OutputPage::class );
		$mockSkin->method( 'getOutput' )
			->willReturn( $mockOutput );
		// Mock the IContextSource returned by Skin::getContext
		$mockContext = $this->createMock( IContextSource::class );
		$mockSkin->method( 'getContext' )
			->willReturn( $mockContext );
		// Create a mock Message object to always be returned by Skin::msg.
		$mockMessage = $this->createMock( Message::class );
		$mockMessage->method( 'text' )
			->willReturn( 'Report' );
		$mockSkin->method( 'msg' )
			->with( 'reportincident-report-btn-label' )
			->willReturn( $mockMessage );

		$mockReportIncidentController = $this->createMock( ReportIncidentController::class );
		// Mock that ::shouldAddMenuItem returns true.
		$mockReportIncidentController->expects( $this->once() )
			->method( 'shouldAddMenuItem' )
			->with( $mockContext )
			->willReturn( true );
		// Expect that the ::addModulesAndConfigVars is called, as this adds the code needed
		// for the dialog and the opening of the dialog with this link.
		$mockReportIncidentController->expects( $this->once() )
			->method( 'addModulesAndConfigVars' )
			->with( $mockOutput );

		$objectUnderTest = new MainHooksHandler( $mockReportIncidentController );
		$sidebar = [];
		$objectUnderTest->$method( $mockSkin, $sidebar );
		$this->assertArrayEquals(
			$expectedSidebar,
			$sidebar,
			false,
			true,
			"The MainHooksHandler::$method method did not add the expected tool link for the reporting button."
		);
	}

	public static function provideToolLinksAddedWhenCorrectNamespaceAndSkin() {
		return [
			'Hooks::onSkinTemplateNavigation__Universal' => [
				'onSkinTemplateNavigation__Universal',
				[
					'actions' => [
						'reportincident' => [
							'class' => 'ext-reportincident-link',
							'text' => 'Report',
							'href' => '#',
							'icon' => 'flag',
						]
					]
				]
			],
			'Hooks::onSidebarBeforeOutput' => [
				'onSidebarBeforeOutput',
				[
					'TOOLBOX' => [
						'reportincident' => [
							'class' => 'ext-reportincident-link',
							'text' => 'Report',
							'href' => '#',
							'icon' => 'flag',
						]
					]
				]
			],
		];
	}
}
