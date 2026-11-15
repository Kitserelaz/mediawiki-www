<?php

namespace MediaWiki\Extension\ReportIncident\Tests\Unit\Api\Rest\Handler;

use Exception;
use MediaWiki\Block\AbstractBlock;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\ReportIncident\Api\Rest\Handler\ReportHandler;
use MediaWiki\Extension\ReportIncident\IncidentReport;
use MediaWiki\Extension\ReportIncident\Services\ReportIncidentManager;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Permissions\RateLimiter;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\ResponseFactory;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\Title\TitleParser;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\User\UserNameUtils;
use MediaWikiUnitTestCase;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\Message\MessageValue;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group ReportIncident
 *
 * @covers MediaWiki\Extension\ReportIncident\Api\Rest\Handler\ReportHandler
 */
class ReportHandlerTest extends MediaWikiUnitTestCase {

	use MockAuthorityTrait;
	use HandlerTestTrait;
	use MockServiceDependenciesTrait;

	public function testDenyAnonymousUsers() {
		$config = new HashConfig( [ 'ReportIncidentApiEnabled' => true ] );
		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class, [ 'config' => $config ] );
		$this->expectExceptionObject(
			new LocalizedHttpException(
				new MessageValue( 'rest-permission-denied-anon' ),
				ReportHandler::HTTP_STATUS_FORBIDDEN
			)
		);
		$authority = $this->mockAnonUltimateAuthority();
		$this->executeHandler(
			$handler,
			new RequestData( [ 'headers' => [ 'Content-Type' => 'application/json' ] ] ),
			[],
			[],
			[],
			[ 'revisionId' => 1 ],
			$authority
		);
	}

	public function testDenyUsersWithNoEdits() {
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
		] );

		$userFactory = $this->createMock( UserFactory::class );
		$reportingUser = $this->createMock( User::class );
		$reportingUser->method( 'isNamed' )->willReturn( true );
		$reportingUser->method( 'getEditCount' )->willReturn( 0 );
		$userFactory->method( 'newFromUserIdentity' )->willReturn( $reportingUser );

		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class, [
			'config' => $config,
			'userFactory' => $userFactory,
		] );
		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'apierror-permissiondenied' ), 403 )
		);
		$authority = $this->newUserAuthority( [
			'actor' => $reportingUser,
		] );
		$dummyBody = [
			'reportedUser' => 'user',
			'revisionId' => 123,
			'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
			'physicalHarmType' => 'threats-physical-harm'
		];
		$this->executeHandler(
			$handler,
			new RequestData( [ 'parsedBody' => $dummyBody ] ),
			[],
			[],
			[],
			[],
			$authority
		);
	}

	public function testDenyUserWithBlock() {
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
		] );

		$userFactory = $this->createMock( UserFactory::class );
		$reportingUser = $this->createMock( User::class );
		$reportingUser->method( 'isNamed' )->willReturn( true );
		$reportingUser->method( 'isRegistered' )->willReturn( true );
		$reportingUser->method( 'getBlock' )->willReturn( $this->createMock( AbstractBlock::class ) );
		$userFactory->method( 'newFromUserIdentity' )->willReturn( $reportingUser );

		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class, [
			'config' => $config,
			'userFactory' => $userFactory,
		] );
		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'apierror-blocked' ), 403 )
		);
		$authority = $this->newUserAuthority( [
			'actor' => $reportingUser,
		] );
		$dummyBody = [
			'reportedUser' => 'user',
			'revisionId' => 123,
			'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
			'physicalHarmType' => 'threats-physical-harm'
		];
		$this->executeHandler(
			$handler,
			new RequestData( [ 'parsedBody' => $dummyBody ] ),
			[],
			[],
			[],
			[],
			$authority
		);
	}

	public function testAccountsUnderReportIncidentMinimumAccountAgeInSecondsThreshold() {
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
			'ReportIncidentMinimumAccountAgeInSeconds' => 101,
			'ReportIncidentDeveloperMode' => false,
		] );

		$userFactory = $this->createMock( UserFactory::class );
		$reportingUser = $this->createMock( User::class );
		$reportingUser->method( 'isNamed' )->willReturn( true );
		$reportingUser->method( 'isRegistered' )->willReturn( true );
		ConvertibleTimestamp::setFakeTime( '20231019120100' );
		$reportingUser->method( 'getRegistration' )->willReturn( '20231019120000' );
		$userFactory->method( 'newFromUserIdentity' )->willReturn( $reportingUser );

		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class, [
			'config' => $config,
			'userFactory' => $userFactory,
		] );
		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'apierror-permissiondenied' ), 403 )
		);
		$authority = $this->newUserAuthority( [
			'actor' => $reportingUser,
		] );
		$dummyBody = [
			'reportedUser' => 'user',
			'revisionId' => 123,
			'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
			'physicalHarmType' => 'threats-physical-harm'
		];
		$this->executeHandler(
			$handler,
			new RequestData( [ 'parsedBody' => $dummyBody ] ),
			[],
			[],
			[],
			[],
			$authority
		);
	}

	public function testConfigDisabled() {
		$config = new HashConfig( [ 'ReportIncidentApiEnabled' => false ] );
		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class, [ 'config' => $config ] );
		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'rest-no-match' ), 404 )
		);
		$this->executeHandler(
			$handler,
			new RequestData(),
			[],
			[],
			[ 'revisionId' => 1 ],
		);
	}

	public function testRevisionDoesntExistAndNoPageProvided() {
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
			'ReportIncidentDeveloperMode' => true,
			'ReportIncidentMinimumAccountAgeInSeconds' => null,
		] );
		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )
			->with( 1 )
			->willReturn( null );

		$user = $this->createMock( User::class );
		$user->method( 'isNamed' )->willReturn( true );

		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromUserIdentity' )->willReturn( $user );

		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class,
			[
				'config' => $config,
				'revisionStore' => $revisionStore,
				'userFactory' => $userFactory
			]
		);
		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'rest-nonexistent-revision' ), 404 )
		);
		$this->executeHandler(
			$handler,
			new RequestData(),
			[],
			[],
			[],
			[ 'revisionId' => 1 ],
			$this->mockRegisteredUltimateAuthority()
		);
	}

	/**
	 * @dataProvider provideValidationErrorDetails
	 */
	public function testBodyFailsValidation( array $parsedBody ) {
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
			'ReportIncidentDeveloperMode' => true,
			'ReportIncidentMinimumAccountAgeInSeconds' => null,
		] );
		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )
			->with( 1 )
			->willReturn( $this->createMock( RevisionRecord::class ) );
		$userNameUtils = $this->createMock( UserNameUtils::class );
		$userNameUtils->method( 'isIP' )
			->willReturn( true );
		$handler = $this->newServiceInstance( ReportHandler::class, [
			'config' => $config,
			'revisionStore' => $revisionStore,
			'userNameUtils' => $userNameUtils,
		] );
		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'rest-body-validation-error' ), 400 )
		);

		$this->executeHandler( $handler, new RequestData( [ 'parsedBody' => $parsedBody ] ) );
	}

	public static function provideValidationErrorDetails(): iterable {
		yield 'invalid "reportedUser" field' => [
			[
				'reportedUser' => [ 'test' => 'testing' ],
				'revisionId' => 123,
				'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
				'physicalHarmType' => '',
			]
		];

		yield 'invalid "details" field' => [
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
				'physicalHarmType' => '',
				'details' => [ 'test' => 'testing' ]
			]
		];

		yield 'too long "details" field' => [
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
				'physicalHarmType' => '',
				'details' => str_repeat( 'a', 2000 ),
			]
		];

		yield 'invalid "somethingElseDetails" field' => [
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
				'physicalHarmType' => '',
				'somethingElseDetails' => [ 'test' => 'testing' ]
			]
		];

		yield 'too long "somethingElseDetails" field' => [
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
				'physicalHarmType' => '',
				'somethingElseDetails' => str_repeat( 'a', 2000 ),
			]
		];

		yield 'missing "incidentType" field' => [
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'physicalHarmType' => '',
			]
		];

		yield 'invalid "incidentType" field' => [
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'incidentType' => 'foo',
				'physicalHarmType' => '',
			]
		];

		yield 'multi-value "incidentType" field' => [
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'incidentType' => [
					IncidentReport::THREAT_TYPE_IMMEDIATE,
					IncidentReport::THREAT_TYPE_UNACCEPTABLE_BEHAVIOR
				],
				'physicalHarmType' => '',
			]
		];

		yield 'invalid "physicalHarmType" field' => [
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
				'physicalHarmType' => 'foo',
			]
		];

		yield 'invalid "behaviorType" field' => [
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'incidentType' => IncidentReport::THREAT_TYPE_UNACCEPTABLE_BEHAVIOR,
				'behaviorType' => 'bar',
			]
		];
	}

	/**
	 * @dataProvider provideIncompatibleFields
	 */
	public function testValidateIncompatibleFields( array $body, Exception $expected ): void {
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
			'ReportIncidentDeveloperMode' => true,
			'ReportIncidentMinimumAccountAgeInSeconds' => null,
		] );
		$page = new PageIdentityValue( 1, NS_TALK, 'TestPage', PageIdentityValue::LOCAL );
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getPage' )
			->willReturn( $page );
		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )
			->with( 123 )
			->willReturn( $revision );
		$userNameUtils = $this->createMock( UserNameUtils::class );
		$userNameUtils->method( 'isIP' )
			->willReturn( true );

		$userFactory = $this->createMock( UserFactory::class );
		$user = $this->createMock( User::class );
		$user->method( 'isNamed' )->willReturn( true );
		$user->method( 'isEmailConfirmed' )->willReturn( false );
		$userFactory->method( 'newFromUserIdentity' )->willReturn( $user );

		$handler = $this->newServiceInstance( ReportHandler::class, [
			'config' => $config,
			'revisionStore' => $revisionStore,
			'userNameUtils' => $userNameUtils,
			'userFactory' => $userFactory
		] );
		$this->expectExceptionObject( $expected );

		$this->executeHandler(
			$handler,
			new RequestData(),
			[],
			[],
			[],
			$body,
			$this->mockRegisteredUltimateAuthority()
		);
	}

	public static function provideIncompatibleFields(): iterable {
		yield 'missing "physicalHarmType" field for immediate harm' => [
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
				'physicalHarmType' => null,
			],
			new LocalizedHttpException(
				new MessageValue( 'rest-missing-body-field', [ 'physicalHarmType' ] ),
				422
			)
		];

		yield '"behaviorType" field provided for immediate harm' => [
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
				'behaviorType' => 'hate-or-discrimination',
				'physicalHarmType' => 'threats-self-harm',
			],
			new LocalizedHttpException(
				new MessageValue( 'rest-extraneous-body-fields', [ 'behaviorType' ] ),
				422
			)
		];

		yield 'missing "behaviorType" field for unacceptable behavior' => [
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'incidentType' => IncidentReport::THREAT_TYPE_UNACCEPTABLE_BEHAVIOR,
				'behaviorType' => null,
			],
			new LocalizedHttpException(
				new MessageValue( 'rest-missing-body-field', [ 'behaviorType' ] ),
				422
			)
		];

		yield '"physicalHarmType" field provided for unacceptable behavior' => [
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'incidentType' => IncidentReport::THREAT_TYPE_UNACCEPTABLE_BEHAVIOR,
				'behaviorType' => 'hate-or-discrimination',
				'physicalHarmType' => 'threats-self-harm',
			],
			new LocalizedHttpException(
				new MessageValue( 'rest-extraneous-body-fields', [ 'physicalHarmType' ] ),
				422
			)
		];
	}

	public function testDenyWithoutConfirmedEmail() {
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
			'ReportIncidentDeveloperMode' => false,
			'ReportIncidentMinimumAccountAgeInSeconds' => null,
		] );
		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )->willReturn( null );
		$userFactory = $this->createMock( UserFactory::class );
		$user = $this->createMock( User::class );
		$user->method( 'isNamed' )->willReturn( true );
		$user->method( 'isEmailConfirmed' )->willReturn( false );
		$userFactory->method( 'newFromUserIdentity' )->willReturn( $user );
		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class,
			[
				'config' => $config,
				'revisionStore' => $revisionStore,
				'userFactory' => $userFactory,
			]
		);
		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'reportincident-confirmedemail-required' ), 403 )
		);
		$this->executeHandler(
			$handler,
			new RequestData(),
			[],
			[],
			[],
			[ 'revisionId' => 1 ],
			$this->mockRegisteredUltimateAuthority()
		);
	}

	public function testAllowWithoutConfirmedEmailWhenDeveloperMode() {
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
			'ReportIncidentDeveloperMode' => true,
			'ReportIncidentMinimumAccountAgeInSeconds' => null,
		] );
		$revisionStore = $this->createMock( RevisionStore::class );
		$page = new PageIdentityValue( 1, NS_TALK, 'TestPage', PageIdentityValue::LOCAL );
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getPage' )
			->willReturn( $page );
		$revisionStore->method( 'getRevisionById' )->willReturn( $revision );
		$reportManager = $this->createMock( ReportIncidentManager::class );
		$reportManager->method( 'record' )->willReturn( StatusValue::newGood() );
		$reportManager->method( 'notify' )->willReturn( StatusValue::newGood() );
		$userFactory = $this->createMock( UserFactory::class );
		$userIdentityLookup = $this->createMock( UserIdentityLookup::class );
		$registeredUserMock = $this->createMock( User::class );
		$registeredUserMock->method( 'isRegistered' )->willReturn( true );
		$registeredUserMock->method( 'isNamed' )->willReturn( true );
		$userIdentityLookup->method( 'getUserIdentityByName' )->willReturn( $registeredUserMock );
		$user = $this->createMock( User::class );
		$user->method( 'isEmailConfirmed' )->willReturn( false );
		$user->method( 'isNamed' )->willReturn( true );
		$userFactory->method( 'newFromUserIdentity' )->willReturn( $user );
		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class,
			[
				'config' => $config,
				'revisionStore' => $revisionStore,
				'userFactory' => $userFactory,
				'reportIncidentManager' => $reportManager,
				'userIdentityLookup' => $userIdentityLookup
			]
		);
		$response = $this->executeHandler(
			$handler,
			new RequestData(),
			[],
			[],
			[],
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
				'physicalHarmType' => 'threats-physical-harm'
			],
			$this->mockRegisteredUltimateAuthority()
		);
		$this->assertSame( 204, $response->getStatusCode() );
	}

	/**
	 * @dataProvider provideTestRestPayload
	 */
	public function testRestPayload(
		array $data,
		?UserIdentity $reportedUser,
		StatusValue $recordStatus,
		StatusValue $notifyStatus,
		bool $expectRecordException,
		bool $expectNotifyException
	) {
		// Mock the config to enable the API
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
			'ReportIncidentDeveloperMode' => true,
			'ReportIncidentMinimumAccountAgeInSeconds' => null,
		] );
		// Mock the return value of ReportIncidentManager::record and ::notify
		$reportManager = $this->createMock( ReportIncidentManager::class );
		$reportManager->method( 'record' )->willReturn( $recordStatus );
		$reportManager->method( 'notify' )->willReturn( $notifyStatus );
		// Mock the result of RevisionStore::getRevisionById
		$revisionStore = $this->createMock( RevisionStore::class );
		$titleParser = $this->createMock( TitleParser::class );

		if ( $data['revisionId'] !== 0 ) {
			$page = new PageIdentityValue( 1, NS_TALK, 'TestPage', PageIdentityValue::LOCAL );
			$revision = $this->createMock( RevisionRecord::class );
			$revision->method( 'getPage' )
				->willReturn( $page );
			$revision->method( 'getId' )->willReturn( $data['revisionId'] );
			$revisionStore->method( 'getRevisionById' )
				->with( $data['revisionId'] )
				->willReturn( $revision );
		} else {
			$revisionStore->expects( $this->never() )
				->method( 'getRevisionById' );

			$titleParser->method( 'parseTitle' )
				->with( $data['page'] )
				->willReturn( new TitleValue( NS_TALK, 'Test' ) );
		}

		$reportingUser = $this->createMock( User::class );
		$reportingUser->method( 'isNamed' )->willReturn( true );
		$reportingUser->method( 'getEditCount' )->willReturn( 1 );

		$userIdentityLookup = $this->createMock( UserIdentityLookup::class );
		$userIdentityLookup->method( 'getUserIdentityByName' )
			->with( $data['reportedUser'] ?? '' )
			->willReturn( $reportedUser );

		$userFactory = $this->createMock( UserFactory::class );
		$userFactory
			->expects( $this->atLeastOnce() )
			->method( 'newFromUserIdentity' )
			->willReturn( $reportingUser );

		// Disable the constructor and allow calling UserNameUtils::isIP which
		// does not rely on the object properties.
		$userNameUtils = $this->getMockBuilder( UserNameUtils::class )
			->disableOriginalConstructor()
			->onlyMethods( [] )
			->getMock();
		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class,
			[
				'config' => $config,
				'reportIncidentManager' => $reportManager,
				'revisionStore' => $revisionStore,
				'userIdentityLookup' => $userIdentityLookup,
				'userFactory' => $userFactory,
				'userNameUtils' => $userNameUtils,
				'titleParser' => $titleParser,
			]
		);
		if ( $expectRecordException ) {
			$this->expectExceptionObject(
				new LocalizedHttpException( new MessageValue( $recordStatus->getErrors()[0]['message'] ), 400 )
			);
		} elseif ( $expectNotifyException ) {
			$this->expectExceptionObject(
				new LocalizedHttpException( new MessageValue( $notifyStatus->getErrors()[0]['message'] ), 500 )
			);
		}
		$response = $this->executeHandler(
			$handler,
			new RequestData( [] ),
			[],
			[],
			[],
			$data,
			$this->mockRegisteredAuthorityWithPermissions( [ 'reportincident' ] )
		);
		$this->assertSame( 204, $response->getStatusCode() );
	}

	/**
	 * Data provider for ::testRestPayload
	 */
	public static function provideTestRestPayload(): array {
		$reportedUser = new UserIdentityValue( 3, 'ReportedUser' );

		return [
			'correct values' => [
				[
					'reportedUser' => 'user',
					'revisionId' => 123,
					'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
					'physicalHarmType' => 'threats-physical-harm',
					'details' => 'foo',
				],
				$reportedUser,
				StatusValue::newGood(),
				StatusValue::newGood(),
				false,
				false,
			],
			'correct values, non-existent page' => [
				[
					'reportedUser' => 'user',
					'revisionId' => 0,
					'page' => 'Talk:Test',
					'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
					'physicalHarmType' => 'threats-physical-harm',
					'details' => 'foo',
				],
				$reportedUser,
				StatusValue::newGood(),
				StatusValue::newGood(),
				false,
				false,
			],
			'correct values, missing reported user' => [
				[
					'revisionId' => 123,
					'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
					'physicalHarmType' => 'threats-physical-harm',
					'details' => 'foo',
				],
				null,
				StatusValue::newGood(),
				StatusValue::newGood(),
				false,
				false,
			],
			'correct values, empty reported user' => [
				[
					'reportedUser' => '',
					'revisionId' => 123,
					'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
					'physicalHarmType' => 'threats-physical-harm',
					'details' => 'foo',
				],
				null,
				StatusValue::newGood(),
				StatusValue::newGood(),
				false,
				false,
			],
			'correct values, empty details' => [
				[
					'reportedUser' => 'user',
					'revisionId' => 123,
					'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
					'physicalHarmType' => 'threats-physical-harm',
				],
				$reportedUser,
				StatusValue::newGood(),
				StatusValue::newGood(),
				false,
				false,
			],
			'trigger a TypeError' => [
				[
					'reportedUser' => 'user',
					'revisionId' => 123,
					'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
					'physicalHarmType' => 'threats-physical-harm',
				],
				$reportedUser,
				StatusValue::newFatal( 'rest-bad-json-body' ),
				StatusValue::newGood(),
				true,
				false,
			],
			'record fails' => [
				[
					'reportedUser' => 'user',
					'revisionId' => 123,
					'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
					'physicalHarmType' => 'threats-physical-harm',
				],
				$reportedUser,
				StatusValue::newFatal( 'rest-bad-json-body' ),
				StatusValue::newGood(),
				true,
				false,
			],
			'notify fails' => [
				[
					'reportedUser' => 'user',
					'revisionId' => 123,
					'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
					'physicalHarmType' => 'threats-physical-harm',
				],
				$reportedUser,
				StatusValue::newGood(),
				StatusValue::newFatal( 'reportincident-unable-to-send' ),
				false,
				true,
			]
		];
	}

	public function testSubmitIncidentReportWithoutDeveloperMode() {
		$config = new HashConfig( [
			'ReportIncidentDeveloperMode' => false,
		] );
		$reportManager = $this->createMock( ReportIncidentManager::class );
		$incidentReport = $this->createMock( IncidentReport::class );
		// Mock that the ::notify and ::record methods both return good statuses.
		$reportManager->method( 'notify' )->with( $incidentReport )
			->willReturn( StatusValue::newGood() );
		$reportManager->method( 'record' )->with( $incidentReport )
			->willReturn( StatusValue::newGood() );
		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class,
			[
				'config' => $config,
				'reportIncidentManager' => $reportManager,
			]
		);
		$handler = TestingAccessWrapper::newFromObject( $handler );
		$handler->responseFactory = new ResponseFactory( [] );
		/** @var Response $response */
		$response = $handler->submitIncidentReport( $incidentReport );
		$this->assertSame( 204, $response->getStatusCode() );
	}

	public function testRateLimitTrip() {
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
			'ReportIncidentDeveloperMode' => true,
			'ReportIncidentMinimumAccountAgeInSeconds' => null,
		] );
		// Pass the performer registered check
		$user = $this->createMock( User::class );
		$user->method( 'isRegistered' )->willReturn( true );
		$user->method( 'isNamed' )->willReturn( true );

		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromUserIdentity' )->willReturn( $user );

		// Pass the revision check
		$revisionStore = $this->createMock( RevisionStore::class );
		$page = new PageIdentityValue( 1, NS_TALK, 'TestPage', PageIdentityValue::LOCAL );
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getPage' )
			->willReturn( $page );
		$revisionStore->method( 'getRevisionById' )
			->willReturn( $revision );
		// Pass the reported user is IP check
		$userNameUtils = $this->createMock( UserNameUtils::class );
		$userNameUtils->method( 'isIP' )
			->willReturn( true );
		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class,
			[
				'config' => $config,
				'revisionStore' => $revisionStore,
				'userFactory' => $userFactory,
				'userNameUtils' => $userNameUtils,
			]
		);
		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'apierror-ratelimited' ), 429 )
		);
		$rateLimiter = $this->createMock( RateLimiter::class );
		$rateLimiter->method( 'limit' )->willReturn( true );
		$rateLimiter->method( 'isLimitable' )->with( 'reportincident' )->willReturn( true );
		$authority = $this->newUserAuthority( [
			'actor' => $user,
			'rateLimiter' => $rateLimiter,
			'permissions' => [ 'reportincident' ]
		] );
		$this->executeHandler(
			$handler,
			new RequestData( [ 'headers' => [ 'Content-Type' => 'application/json' ] ] ),
			[],
			[],
			[],
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
				'physicalHarmType' => 'threats-physical-harm',
			],
			$authority
		);
	}

	public function testActionUnauthorized() {
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
			'ReportIncidentDeveloperMode' => true,
			'ReportIncidentMinimumAccountAgeInSeconds' => null,
		] );
		// Pass the revision check
		$revisionStore = $this->createMock( RevisionStore::class );
		$page = new PageIdentityValue( 1, NS_TALK, 'TestPage', PageIdentityValue::LOCAL );
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getPage' )
			->willReturn( $page );
		$revisionStore->method( 'getRevisionById' )
			->willReturn( $revision );
		// Pass the reported user is IP check
		$userNameUtils = $this->createMock( UserNameUtils::class );
		$userNameUtils->method( 'isIP' )
			->willReturn( true );

		$user = $this->createMock( User::class );
		$user->method( 'isNamed' )->willReturn( true );
		$user->method( 'isEmailConfirmed' )->willReturn( true );

		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromUserIdentity' )->willReturn( $user );

		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class,
			[
				'config' => $config,
				'revisionStore' => $revisionStore,
				'userFactory' => $userFactory,
				'userNameUtils' => $userNameUtils,
			]
		);
		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'apierror-permissiondenied' ), 403 )
		);
		$authority = $this->mockRegisteredAuthorityWithoutPermissions( [ 'reportincident' ] );
		$this->executeHandler(
			$handler,
			new RequestData( [ 'headers' => [ 'Content-Type' => 'application/json' ] ] ),
			[],
			[],
			[],
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
				'physicalHarmType' => 'threats-physical-harm',
			],
			$authority
		);
	}

	public function testAuthorizeIncidentReportForTemporaryUser() {
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
			'ReportIncidentDeveloperMode' => true,
			'ReportIncidentMinimumAccountAgeInSeconds' => null,
		] );
		// Pass the revision check
		$revisionStore = $this->createMock( RevisionStore::class );
		$page = new PageIdentityValue( 1, NS_TALK, 'TestPage', PageIdentityValue::LOCAL );
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getPage' )
			->willReturn( $page );
		$revisionStore->method( 'getRevisionById' )
			->willReturn( $revision );
		// Pass the reported user is IP check
		$userNameUtils = $this->createMock( UserNameUtils::class );
		$userNameUtils->method( 'isIP' )
			->willReturn( true );
		// Create a mock user that is indicates it is a temporary account
		$user = $this->createMock( User::class );
		$user->method( 'isTemp' )->willReturn( true );
		$user->method( 'isRegistered' )->willReturn( true );
		$user->method( 'isNamed' )->willReturn( true );
		$user->method( 'getName' )->willReturn( '*Unregistered 123' );
		// Mock that the UserFactory returns the User object created above
		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromUserIdentity' )
			->with( $user )
			->willReturnArgument( 0 );
		// Mock that the LoggerInterface indicates this was a temporary account
		// that attempted to submit a report
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )->method( 'warning' )
			->with(
				'Temporary user "{user}" attempted to perform "reportincident".',
				[ 'user' => $user->getName() ]
			);
		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class,
			[
				'config' => $config,
				'revisionStore' => $revisionStore,
				'userNameUtils' => $userNameUtils,
				'userFactory' => $userFactory,
			]
		);
		$handler = TestingAccessWrapper::newFromObject( $handler );
		$handler->logger = $mockLogger;
		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'apierror-permissiondenied' ), 403 )
		);
		$authority = $this->newUserAuthority( [
			'actor' => $user,
		] );
		$this->executeHandler(
			$handler->object,
			new RequestData( [ 'headers' => [ 'Content-Type' => 'application/json' ] ] ),
			[],
			[],
			[],
			[
				'reportedUser' => 'user',
				'revisionId' => 123,
				'incidentType' => IncidentReport::THREAT_TYPE_IMMEDIATE,
				'physicalHarmType' => 'threats-physical-harm',
			],
			$authority
		);
	}

	public function testTempUserFailsFirstValidation(): void {
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
			'ReportIncidentDeveloperMode' => true,
			'ReportIncidentMinimumAccountAgeInSeconds' => null,
		] );

		$userNameUtils = $this->createMock( UserNameUtils::class );
		$userNameUtils->expects( $this->never() )->method( 'isIP' );

		$user = $this->createMock( User::class );
		$user->method( 'isTemp' )->willReturn( true );
		$user->method( 'isRegistered' )->willReturn( true );
		$user->method( 'isNamed' )->willReturn( false );
		$user->method( 'getName' )->willReturn( '*Unregistered 123' );

		// Mock that the UserFactory returns the User object created above
		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromUserIdentity' )
			->with( $user )
			->willReturnArgument( 0 );

		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->expects( $this->never() )->method( 'getRevisionById' );

		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class,
			[
				'config' => $config,
				'revisionStore' => $revisionStore,
				'userNameUtils' => $userNameUtils,
				'userFactory' => $userFactory,
			]
		);

		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'rest-permission-denied-anon' ), 403 )
		);

		$this->executeHandler(
			$handler,
			new RequestData( [ 'headers' => [ 'Content-Type' => 'application/json' ] ] ),
			[],
			[],
			[],
			[ 'revisionId' => 1, 'reportedUser' => '127.0.0.1', 'behaviors' => [ 'test' ] ],
			$this->newUserAuthority( [ 'actor' => $user ] )
		);
	}
}
