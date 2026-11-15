<?php

namespace MediaWiki\Extension\ReportIncident\Tests\Unit;

use MediaWiki\Block\Block;
use MediaWiki\Config\HashConfig;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReportIncident\Api\Rest\Handler\ReportHandler;
use MediaWiki\Extension\ReportIncident\Services\ReportIncidentController;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\WebRequest;
use MediaWiki\Skin\Skin;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group ReportIncident
 *
 * @covers \MediaWiki\Extension\ReportIncident\Services\ReportIncidentController
 */
class ReportIncidentControllerTest extends MediaWikiUnitTestCase {
	use MockServiceDependenciesTrait;

	private function newReportIncidentController(
		array $globalConfig,
		array $communityConfig = []
	): ReportIncidentController {
		$communityConfig += [
			'ReportIncidentEnabledNamespaces' => [],
			'ReportIncidentE2ETesterUsers' => (object)[],
			'ReportIncident_NonEmergency_Intimidation_DisputeResolutionURL' => '',
			'ReportIncident_NonEmergency_Intimidation_HelpMethod' => (object)[
				'ContactAdmin' => '',
				'Email' => '',
				'ContactCommunity' => '',
			],
			'ReportIncident_NonEmergency_Doxing_ShowWarning' => true,
			'ReportIncident_NonEmergency_Doxing_HideEditURL' => '',
			'ReportIncident_NonEmergency_Doxing_HelpMethod' => (object)[
				'WikiEmailURL' => '',
				'Email' => '',
				'OtherURL' => '',
				'EmailStewards' => false,
			],
			'ReportIncident_NonEmergency_SexualHarassment_HelpMethod' => (object)[
				'ContactAdmin' => '',
				'Email' => '',
				'ContactCommunity' => '',
			],
			'ReportIncident_NonEmergency_Trolling_HelpMethod' => (object)[
				'ContactAdmin' => '',
				'Email' => '',
				'ContactCommunity' => '',
			],
			'ReportIncident_NonEmergency_HateSpeech_HelpMethod' => (object)[
				'ContactAdmin' => '',
				'Email' => '',
			],
			'ReportIncident_NonEmergency_Spam_SpamContentURL' => '',
			'ReportIncident_NonEmergency_Spam_HelpMethod' => (object)[
				'ContactAdmin' => '',
				'Email' => '',
			],
			'ReportIncident_NonEmergency_Other_DisputeResolutionURL' => '',
			'ReportIncident_NonEmergency_SomethingElse_HelpMethod' => (object)[
				'ContactAdmin' => '',
				'Email' => '',
				'ContactCommunity' => '',
			],
			'ReportIncident_NonEmergency_Sockpuppetry_HelpMethod' => (object)[
				'ContactAdmin' => '',
				'Email' => '',
				'ContactCommunity' => '',
			],
			'ReportIncident_NonEmergency_Vandalism_HelpMethod' => (object)[
				'ContactAdmin' => '',
				'Email' => '',
				'ContactCommunity' => '',
			],
			'ReportIncident_NonEmergency_UserDispute_HelpMethod' => (object)[
				'ContactAdmin' => '',
				'Email' => '',
				'ContactCommunity' => '',
			],
			'ReportIncident_NonEmergency_DisruptiveEditing_HelpMethod' => (object)[
				'ContactAdmin' => '',
				'Email' => '',
				'ContactCommunity' => '',
			],
			'ReportIncident_NonEmergency_Other_HelpMethod' => (object)[
				'ContactAdmin' => '',
				'Email' => '',
				'ContactCommunity' => '',
			],
		];
		return new ReportIncidentController(
			new HashConfig( $globalConfig ),
			new HashConfig( $communityConfig )
		);
	}

	/** @dataProvider provideShouldShowButtonForNamespace */
	public function testShouldShowButtonForNamespace(
		$namespace,
		$supportedNamespaces,
		$communityConfigNamespaces,
		$expectedReturnValue
	) {
		$objectUnderTest = $this->newReportIncidentController(
			[ 'ReportIncidentEnabledNamespaces' => $supportedNamespaces ],
			[ 'ReportIncidentEnabledNamespaces' => $communityConfigNamespaces ]
		);
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$this->assertSame(
			$expectedReturnValue,
			$objectUnderTest->shouldShowButtonForNamespace( $namespace ),
			'::shouldShowButtonForNamespace did not return the expected boolean.'
		);
	}

	public static function provideShouldShowButtonForNamespace() {
		return [
			'Namespace is supported' => [ 3, [ 3 ], [], true ],
			'Namespace is not supported' => [ 4, [ 3, 2 ], [], false ],
			'Namespace is set in community configuration' => [ 3, [ 4, 2 ], [ 3 ], true ],
		];
	}

	/** @dataProvider provideShouldShowButtonForUser */
	public function testShouldShowButtonForUser(
		$isUserNamed,
		$editCount,
		$isBlocked,
		$accountAgeOverMinimum,
		$shouldShow
	) {
		$mockUser = $this->createMock( User::class );
		$mockUser->method( 'isNamed' )
			->willReturn( $isUserNamed );
		$mockUser->method( 'getEditCount' )
			->willReturn( $editCount );
		$mockUser->method( 'getBlock' )
			->willReturn( $isBlocked ? $this->createMock( Block::class ) : null );
		$accountAge = $accountAgeOverMinimum ? 0 : (int)ConvertibleTimestamp::now();
		$mockUser->method( 'getRegistration' )
			->willReturn( $accountAge );

		$objectUnderTest = $this->newReportIncidentController( [
			'ReportIncidentMinimumAccountAgeInSeconds' => (int)ConvertibleTimestamp::now() - 86400,
			'ReportIncidentDeveloperMode' => false,
		] );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$this->assertSame(
			$shouldShow,
			$objectUnderTest->shouldShowButtonForUser( $mockUser ),
			'::shouldShowButtonForUser did not return the expected boolean.'
		);
	}

	public static function provideShouldShowButtonForUser() {
		return [
			'User isn\'t named' => [ false, 1, false, true, false ],
			'User has no edits' => [ true, 0, false, true, false ],
			'User is  blocked' => [ true, 1, true, true, false ],
			'User account age does not meet threshold' => [ true, 1, false, false, false ],
			'User is eligible' => [ true, 1, false, true, true ],
		];
	}

	/** @dataProvider provideShouldShowButtonForUserBypasses */
	public function testShouldShowButtonForUserBypasses(
		$isDeveloperMode,
		$shouldSkipEligibilityChecks,
		$isE2ETester,
		$shouldShowButton
	) {
		// Mock a user who fails all eligibility checks by default
		$mockUser = $this->createMock( User::class );
		$mockUser->method( 'getName' )
			->willReturn( 'Foo' );
		$mockUser->method( 'isNamed' )
			->willReturn( true );
		$mockUser->method( 'getEditCount' )
			->willReturn( 0 );
		$mockUser->method( 'getBlock' )
			->willReturn( $this->createMock( Block::class ) );
		$accountAge = (int)ConvertibleTimestamp::now();
		$mockUser->method( 'getRegistration' )
			->willReturn( $accountAge );

		$objectUnderTest = $this->newReportIncidentController( [
			'ReportIncidentMinimumAccountAgeInSeconds' => (int)ConvertibleTimestamp::now(),
			'ReportIncidentDeveloperMode' => $isDeveloperMode,
		], [
			'ReportIncidentE2ETesterUsers' => $isE2ETester ? [ 'Foo' ] : [],
		] );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$this->assertSame(
			$shouldShowButton,
			$objectUnderTest->shouldShowButtonForUser( $mockUser, $shouldSkipEligibilityChecks ),
			'::shouldShowButtonForUser did not return the expected boolean.'
		);
	}

	public static function provideShouldShowButtonForUserBypasses() {
		return [
			'no developer mode, should skip eligibility checks' => [ false, true, false, false ],
			'developer mode, don\'t skip eligibility checks' => [ true, false, false, false ],
			'developer mode, skip eligibility checks' => [ true, true, false, true ],
			'not an e2e tester' => [ false, false, false, false ],
			'e2e tester' => [ false, false, true, true, ]
		];
	}

	/** @dataProvider provideShouldAddMenuItem */
	public function testShouldAddMenuItem(
		array $config, int $namespace, ?string $skinName, bool $isUserNamed, bool $expectedReturnResult
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
		// Mock the test helper config
		$mockRequest = $this->createMock( WebRequest::class );
		$mockRequest->method( 'getBool' )->willReturn( false );
		$mockContext->method( 'getRequest' )->willReturn( $mockRequest );
		// Get the object under test
		/** @var ReportIncidentController $objectUnderTest */
		$objectUnderTest = $this->newReportIncidentController( $config );
		$this->assertSame(
			$expectedReturnResult,
			$objectUnderTest->shouldAddMenuItem( $mockContext ),
			'::shouldShowButtonForUser did not return the expected boolean.'
		);
	}

	public static function provideShouldAddMenuItem() {
		return [
			'All checks should fail' => [
				[
					'ReportIncidentReportButtonEnabled' => false,
					'ReportIncidentEnabledNamespaces' => [ NS_USER_TALK ],
					'ReportIncidentEnableInstrumentation' => true,
					'ReportIncidentMinimumAccountAgeInSeconds' => 0,
					'ReportIncidentDeveloperMode' => false,
				],
				NS_TEMPLATE,
				'minerva',
				false,
				false,
			],
			'Feature flag disabled' => [
				[
					'ReportIncidentReportButtonEnabled' => false,
					'ReportIncidentEnabledNamespaces' => [ NS_USER_TALK ],
					'ReportIncidentEnableInstrumentation' => true,
					'ReportIncidentMinimumAccountAgeInSeconds' => 0,
					'ReportIncidentDeveloperMode' => false,
				],
				NS_USER_TALK,
				'vector',
				true,
				false,
			],
			'Unsupported namespace' => [
				[
					'ReportIncidentReportButtonEnabled' => true,
					'ReportIncident' => [ 'vector' ],
					'ReportIncidentEnabledNamespaces' => [ NS_USER_TALK ],
					'ReportIncidentEnableInstrumentation' => true,
					'ReportIncidentMinimumAccountAgeInSeconds' => 0,
					'ReportIncidentDeveloperMode' => false,
				],
				NS_TEMPLATE,
				'vector',
				true,
				false,
			],
			'User is not named' => [
				[
					'ReportIncidentReportButtonEnabled' => true,
					'ReportIncidentEnabledNamespaces' => [ NS_USER_TALK ],
					'ReportIncidentEnableInstrumentation' => true,
					'ReportIncidentMinimumAccountAgeInSeconds' => 0,
					'ReportIncidentDeveloperMode' => false,
				],
				NS_USER_TALK,
				'vector',
				false,
				false,
			],
			'All checks should pass' => [
				[
					'ReportIncidentReportButtonEnabled' => true,
					'ReportIncidentEnabledNamespaces' => [ NS_USER_TALK ],
					'ReportIncidentEnableInstrumentation' => true,
					'ReportIncidentMinimumAccountAgeInSeconds' => 0,
					'ReportIncidentDeveloperMode' => false,
				],
				NS_USER_TALK,
				'vector',
				true,
				true,
			],
		];
	}

	public function testAddModulesAndConfigVarsForMinerva() {
		$outputPageMock = $this->createMock( OutputPage::class );
		$userMock = $this->createMock( User::class );
		$userMock->method( 'isEmailConfirmed' )->willReturn( true );
		$outputPageMock->method( 'getUser' )->willReturn( $userMock );
		$outputPageMock->expects( $this->once() )->method( 'addJsConfigVars' )
			->with( [
				'wgReportIncidentUserHasConfirmedEmail' => true,
				'wgReportIncidentEnableInstrumentation' => true,
				'wgReportIncidentDetailsCodePointLength' => ReportHandler::MAX_DETAILS_LENGTH,
				'wgReportIncidentUserHasEmail' => false,
				'wgReportIncidentE2ETesterUsers' => (object)[],
				'wgReportIncidentEnabledNonEmergencyCategories' => [],
				'wgReportIncidentNonEmergencyIntimidationDisputeResolutionURL' => '',
				'wgReportIncidentNonEmergencyIntimidationHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyIntimidationHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyIntimidationHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyDoxingShowWarning' => true,
				'wgReportIncidentNonEmergencyDoxingHideEditURL' => '',
				'wgReportIncidentNonEmergencyDoxingHelpMethodWikiEmailURL' => '',
				'wgReportIncidentNonEmergencyDoxingHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyDoxingHelpMethodOtherURL' => '',
				'wgReportIncidentNonEmergencyDoxingHelpMethodEmailStewards' => false,
				'wgReportIncidentNonEmergencySexualHarassmentHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencySexualHarassmentHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencySexualHarassmentHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyTrollingHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyTrollingHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyTrollingHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyHateSpeechHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyHateSpeechHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencySpamSpamContentURL' => '',
				'wgReportIncidentNonEmergencySpamHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencySpamHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyOtherDisputeResolutionURL' => '',
				'wgReportIncidentNonEmergencySomethingElseHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencySomethingElseHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencySomethingElseHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencySockpuppetryHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencySockpuppetryHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencySockpuppetryHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyVandalismHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyVandalismHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyVandalismHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyUserDisputeHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyUserDisputeHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyUserDisputeHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyOtherHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyOtherHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyOtherHelpMethodContactCommunity' => '',
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
		$objectUnderTest = $this->newReportIncidentController( [
			'ReportIncidentDeveloperMode' => false,
			'ReportIncidentEnableInstrumentation' => true,
			'ReportIncidentEnabledNonEmergencyCategories' => [],
		] );
		$objectUnderTest->addModulesAndConfigVars( $outputPageMock );
	}

	/**
	 * @dataProvider provideConfigVarsData
	 */
	public function testAddModulesAndConfigVars(
		array $globalConfig,
		array $communityConfig,
		array $expectedLocalLinks
	): void {
		$outputPageMock = $this->createMock( OutputPage::class );
		$userMock = $this->createMock( User::class );
		$userMock->method( 'isEmailConfirmed' )->willReturn( true );
		$outputPageMock->method( 'getUser' )->willReturn( $userMock );
		$outputPageMock->expects( $this->once() )->method( 'addJsConfigVars' )
			->with( [
				'wgReportIncidentUserHasConfirmedEmail' => true,
				'wgReportIncidentEnableInstrumentation' => true,
				'wgReportIncidentDetailsCodePointLength' => ReportHandler::MAX_DETAILS_LENGTH,
				'wgReportIncidentUserHasEmail' => false,
				'wgReportIncidentE2ETesterUsers' => (object)[],
				'wgReportIncidentEnabledNonEmergencyCategories' => [],
				'wgReportIncidentNonEmergencyIntimidationDisputeResolutionURL' => '',
				'wgReportIncidentNonEmergencyIntimidationHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyIntimidationHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyIntimidationHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyDoxingShowWarning' => true,
				'wgReportIncidentNonEmergencyDoxingHideEditURL' => '',
				'wgReportIncidentNonEmergencyDoxingHelpMethodWikiEmailURL' => '',
				'wgReportIncidentNonEmergencyDoxingHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyDoxingHelpMethodOtherURL' => '',
				'wgReportIncidentNonEmergencyDoxingHelpMethodEmailStewards' => false,
				'wgReportIncidentNonEmergencySexualHarassmentHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencySexualHarassmentHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencySexualHarassmentHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyTrollingHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyTrollingHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyTrollingHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyHateSpeechHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyHateSpeechHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencySpamSpamContentURL' => '',
				'wgReportIncidentNonEmergencySpamHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencySpamHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyOtherDisputeResolutionURL' => '',
				'wgReportIncidentNonEmergencySomethingElseHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencySomethingElseHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencySomethingElseHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencySockpuppetryHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencySockpuppetryHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencySockpuppetryHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyVandalismHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyVandalismHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyVandalismHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyUserDisputeHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyUserDisputeHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyUserDisputeHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyOtherHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyOtherHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyOtherHelpMethodContactCommunity' => '',
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
		$globalConfig += [
			'ReportIncidentDeveloperMode' => false,
			'ReportIncidentEnableInstrumentation' => true,
			'ReportIncidentEnabledNonEmergencyCategories' => [],
		];
		/** @var ReportIncidentController $objectUnderTest */
		$objectUnderTest = $this->newReportIncidentController( $globalConfig, $communityConfig );
		$objectUnderTest->addModulesAndConfigVars( $outputPageMock );
	}

	public static function provideConfigVarsData(): iterable {
		yield 'no customized local links in LocalSettings nor community configuration' => [
			[],
			[],
			[
				'disputeResolution' => 'Project:Dispute resolution',
				'localIncidentReport' => 'Project:Report an incident',
				'askTheCommunity' => 'Project:Village pump',
			],
		];

		yield 'customized local links in LocalSettings, nothing in community configuration' => [
			[
				'ReportIncidentLocalIncidentReportPage' => 'Project:Local incident report',
			],
			[],
			[
				'disputeResolution' => 'Project:Dispute resolution',
				'localIncidentReport' => 'Project:Local incident report',
				'askTheCommunity' => 'Project:Village pump',
			],
		];

		yield 'customized local links in LocalSettings and in community configuration' => [
			[
				'ReportIncidentLocalIncidentReportPage' => 'Project:Local incident report',
				'ReportIncidentCommunityQuestionsPage' => 'Project:Community questions',
			],
			[
				'ReportIncidentLocalIncidentReportPage' => 'Project:CommunityConfig Local incident report',
			],
			[
				'disputeResolution' => 'Project:Dispute resolution',
				'localIncidentReport' => 'Project:CommunityConfig Local incident report',
				'askTheCommunity' => 'Project:Community questions',
			],
		];
	}

	public function testAddModulesAndConfigVarsNoConfirmedEmail() {
		$outputPageMock = $this->createMock( OutputPage::class );
		$userMock = $this->createMock( User::class );
		$userMock->method( 'isEmailConfirmed' )->willReturn( false );
		$outputPageMock->method( 'getUser' )->willReturn( $userMock );
		$outputPageMock->expects( $this->once() )->method( 'addJsConfigVars' )
			->with( [
				'wgReportIncidentUserHasConfirmedEmail' => false,
				'wgReportIncidentEnableInstrumentation' => true,
				'wgReportIncidentDetailsCodePointLength' => ReportHandler::MAX_DETAILS_LENGTH,
				'wgReportIncidentUserHasEmail' => false,
				'wgReportIncidentE2ETesterUsers' => (object)[],
				'wgReportIncidentEnabledNonEmergencyCategories' => [],
				'wgReportIncidentNonEmergencyIntimidationDisputeResolutionURL' => '',
				'wgReportIncidentNonEmergencyIntimidationHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyIntimidationHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyIntimidationHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyDoxingShowWarning' => true,
				'wgReportIncidentNonEmergencyDoxingHideEditURL' => '',
				'wgReportIncidentNonEmergencyDoxingHelpMethodWikiEmailURL' => '',
				'wgReportIncidentNonEmergencyDoxingHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyDoxingHelpMethodOtherURL' => '',
				'wgReportIncidentNonEmergencyDoxingHelpMethodEmailStewards' => false,
				'wgReportIncidentNonEmergencySexualHarassmentHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencySexualHarassmentHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencySexualHarassmentHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyTrollingHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyTrollingHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyTrollingHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyHateSpeechHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyHateSpeechHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencySpamSpamContentURL' => '',
				'wgReportIncidentNonEmergencySpamHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencySpamHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyOtherDisputeResolutionURL' => '',
				'wgReportIncidentNonEmergencySomethingElseHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencySomethingElseHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencySomethingElseHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencySockpuppetryHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencySockpuppetryHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencySockpuppetryHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyVandalismHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyVandalismHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyVandalismHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyUserDisputeHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyUserDisputeHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyUserDisputeHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodContactCommunity' => '',
				'wgReportIncidentNonEmergencyOtherHelpMethodContactAdmin' => '',
				'wgReportIncidentNonEmergencyOtherHelpMethodEmail' => '',
				'wgReportIncidentNonEmergencyOtherHelpMethodContactCommunity' => '',
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
		$objectUnderTest = $this->newReportIncidentController( [
			'ReportIncidentDeveloperMode' => false,
			'ReportIncidentEnableInstrumentation' => true,
			'ReportIncidentEnabledNonEmergencyCategories' => [],
		] );
		$objectUnderTest->addModulesAndConfigVars( $outputPageMock );
	}
}
