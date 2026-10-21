<?php

namespace MediaWiki\Extension\ReportIncident\Tests\Unit;

use MediaWiki\Config\Config;
use MediaWiki\Config\HashConfig;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReportIncident\Services\ReportIncidentController;
use MediaWiki\Output\OutputPage;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;
use Skin;
use Wikimedia\TestingAccessWrapper;

/**
 * @group ReportIncident
 *
 * @covers \MediaWiki\Extension\ReportIncident\Services\ReportIncidentController
 */
class ReportIncidentControllerTest extends MediaWikiUnitTestCase {
	use MockServiceDependenciesTrait;

	/** @dataProvider provideShouldShowButtonForNamespace */
	public function testShouldShowButtonForNamespace( $namespace, $supportedNamespaces, $expectedReturnValue ) {
		$config = new HashConfig( [
			'ReportIncidentEnabledNamespaces' => $supportedNamespaces,
		] );
		$objectUnderTest = $this->newServiceInstance( ReportIncidentController::class, [
			'config' => $config
		] );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$this->assertSame(
			$expectedReturnValue,
			$objectUnderTest->shouldShowButtonForNamespace( $namespace ),
			'::shouldShowButtonForNamespace did not return the expected boolean.'
		);
	}

	public static function provideShouldShowButtonForNamespace() {
		return [
			'Namespace is supported' => [ 3, [ 3 ], true ],
			'Namespace is not supported' => [ 4, [ 3, 2 ], false ],
		];
	}

	/** @dataProvider provideShouldShowButtonForUser */
	public function testShouldShowButtonForUser( $isUserNamed ) {
		$mockUser = $this->createMock( User::class );
		$mockUser->method( 'isNamed' )
			->willReturn( $isUserNamed );
		$objectUnderTest = $this->newServiceInstance( ReportIncidentController::class, [] );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$this->assertSame(
			$isUserNamed,
			$objectUnderTest->shouldShowButtonForUser( $mockUser ),
			'::shouldShowButtonForUser did not return the expected boolean.'
		);
	}

	public static function provideShouldShowButtonForUser() {
		return [
			'User is named' => [ true ],
			'User is not named' => [ false ],
		];
	}

	/** @dataProvider provideShouldAddMenuItem */
	public function testShouldAddMenuItem(
		Config $config, int $namespace, ?string $skinName, bool $isUserNamed, bool $expectedReturnResult
	) {
		// Mock the IContextSource that is passed to the ::shouldAddMenuItem method
		$mockContext = $this->createMock( IContextSource::class );
		// Mock the getUser method.
		$userMock = $this->createMock( User::class );
		$userMock->method( 'isNamed' )->willReturn( $isUserNamed );
		$mockContext->method( 'getUser' )->willReturn( $userMock );
		// Mock the namespace
		$mockTitle = $this->createMock( Title::class );
		$mockTitle->method( 'getNamespace' )->willReturn( $namespace );
		$mockContext->method( 'getTitle' )->willReturn( $mockTitle );
		// Mock the skin name
		$mockSkin = $this->createMock( Skin::class );
		$mockSkin->method( 'getSkinName' )->willReturn( $skinName );
		$mockContext->method( 'getSkin' )->willReturn( $mockSkin );
		// Get the object under test
		/** @var ReportIncidentController $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance(
			ReportIncidentController::class,
			[ 'config' => $config ]
		);
		$this->assertSame(
			$expectedReturnResult,
			$objectUnderTest->shouldAddMenuItem( $mockContext ),
			'::shouldShowButtonForUser did not return the expected boolean.'
		);
	}

	public static function provideShouldAddMenuItem() {
		return [
			'All checks should fail' => [
				new HashConfig( [
					'ReportIncidentReportButtonEnabled' => false,
					'ReportIncidentEnabledNamespaces' => [ NS_USER_TALK ],
				] ),
				NS_TEMPLATE,
				'minerva',
				false,
				false,
			],
			'Feature flag disabled' => [
				new HashConfig( [
					'ReportIncidentReportButtonEnabled' => false,
					'ReportIncidentEnabledNamespaces' => [ NS_USER_TALK ],
				] ),
				NS_USER_TALK,
				'vector',
				true,
				false,
			],
			'Unsupported namespace' => [
				new HashConfig( [
					'ReportIncidentReportButtonEnabled' => true,
					'ReportIncident' => [ 'vector' ],
					'ReportIncidentEnabledNamespaces' => [ NS_USER_TALK ],
				] ),
				NS_TEMPLATE,
				'vector',
				true,
				false,
			],
			'User is not named' => [
				new HashConfig( [
					'ReportIncidentReportButtonEnabled' => true,
					'ReportIncidentEnabledNamespaces' => [ NS_USER_TALK ],
				] ),
				NS_USER_TALK,
				'vector',
				false,
				false,
			],
			'All checks should pass' => [
				new HashConfig( [
					'ReportIncidentReportButtonEnabled' => true,
					'ReportIncidentEnabledNamespaces' => [ NS_USER_TALK ],
				] ),
				NS_USER_TALK,
				'vector',
				true,
				true,
			],
		];
	}

	public function testAddModulesAndConfigVarsForMinerva() {
		$config = new HashConfig( [
			'ReportIncidentAdministratorsPage' => 'Main_Page',
			'ReportIncidentDeveloperMode' => false,
		] );
		$outputPageMock = $this->createMock( OutputPage::class );
		$userMock = $this->createMock( User::class );
		$userMock->method( 'isEmailConfirmed' )->willReturn( true );
		$outputPageMock->method( 'getUser' )->willReturn( $userMock );
		$outputPageMock->expects( $this->once() )->method( 'addJsConfigVars' )
			->with( [
				'wgReportIncidentAdministratorsPage' => 'Main_Page',
				'wgReportIncidentUserHasConfirmedEmail' => true
			] );
		$outputPageMock->expects( $this->once() )->method( 'addModules' )
			->with( 'ext.reportIncident' );
		$outputPageMock->expects( $this->once() )->method( 'addModuleStyles' )
			->with( 'ext.reportIncident.menuStyles' );
		// Mock the methods used to get the current skin name to return "minerva"
		$mockSkin = $this->createMock( Skin::class );
		$mockSkin->method( 'getSkinName' )
			->willReturn( 'minerva' );
		$outputPageMock->expects( $this->once() )->method( 'getSkin' )
			->willReturn( $mockSkin );
		/** @var ReportIncidentController $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance( ReportIncidentController::class, [
			'config' => $config
		] );
		$objectUnderTest->addModulesAndConfigVars( $outputPageMock );
	}

	public function testAddModulesAndConfigVars() {
		$config = new HashConfig( [
			'ReportIncidentAdministratorsPage' => 'Main_Page',
			'ReportIncidentDeveloperMode' => false,
		] );
		$outputPageMock = $this->createMock( OutputPage::class );
		$userMock = $this->createMock( User::class );
		$userMock->method( 'isEmailConfirmed' )->willReturn( true );
		$outputPageMock->method( 'getUser' )->willReturn( $userMock );
		$outputPageMock->expects( $this->once() )->method( 'addJsConfigVars' )
			->with( [
				'wgReportIncidentAdministratorsPage' => 'Main_Page',
				'wgReportIncidentUserHasConfirmedEmail' => true
			] );
		$outputPageMock->expects( $this->once() )->method( 'addModules' )
			->with( 'ext.reportIncident' );
		$outputPageMock->expects( $this->never() )->method( 'addModuleStyles' )
			->with( 'ext.reportIncident.menuStyles' );
		// Mock the methods used to get the current skin name to return "vector"
		$mockSkin = $this->createMock( Skin::class );
		$mockSkin->method( 'getSkinName' )
			->willReturn( 'vector' );
		$outputPageMock->expects( $this->once() )->method( 'getSkin' )
			->willReturn( $mockSkin );
		/** @var ReportIncidentController $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance( ReportIncidentController::class, [
			'config' => $config
		] );
		$objectUnderTest->addModulesAndConfigVars( $outputPageMock );
	}

	public function testAddModulesAndConfigVarsNoConfirmedEmail() {
		$config = new HashConfig( [
			'ReportIncidentAdministratorsPage' => 'Main_Page',
			'ReportIncidentDeveloperMode' => false,
		] );
		$outputPageMock = $this->createMock( OutputPage::class );
		$userMock = $this->createMock( User::class );
		$userMock->method( 'isEmailConfirmed' )->willReturn( false );
		$outputPageMock->method( 'getUser' )->willReturn( $userMock );
		$outputPageMock->expects( $this->once() )->method( 'addJsConfigVars' )
			->with( [
				'wgReportIncidentAdministratorsPage' => 'Main_Page',
				'wgReportIncidentUserHasConfirmedEmail' => false
			] );
		$outputPageMock->expects( $this->once() )->method( 'addModules' )
			->with( 'ext.reportIncident' );
		$outputPageMock->expects( $this->never() )->method( 'addModuleStyles' )
			->with( 'ext.reportIncident.menuStyles' );
		// Mock the methods used to get the current skin name to return "vector"
		$mockSkin = $this->createMock( Skin::class );
		$mockSkin->method( 'getSkinName' )
			->willReturn( 'vector' );
		$outputPageMock->expects( $this->once() )->method( 'getSkin' )
			->willReturn( $mockSkin );
		/** @var ReportIncidentController $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance( ReportIncidentController::class, [
			'config' => $config
		] );
		$objectUnderTest->addModulesAndConfigVars( $outputPageMock );
	}
}
