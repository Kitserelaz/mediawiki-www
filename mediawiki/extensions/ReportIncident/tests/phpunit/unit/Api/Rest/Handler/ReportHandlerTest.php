<?php

namespace MediaWiki\Extension\ReportIncident\Tests\Unit\Api\Rest\Handler;

use MediaWiki\Block\AbstractBlock;
use MediaWiki\Config\Config;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\ReportIncident\Api\Rest\Handler\ReportHandler;
use MediaWiki\Extension\ReportIncident\IncidentReport;
use MediaWiki\Extension\ReportIncident\IncidentReportEmailStatus;
use MediaWiki\Extension\ReportIncident\Services\ReportIncidentManager;
use MediaWiki\Json\FormatJson;
use MediaWiki\Language\Language;
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
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
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
			new LocalizedHttpException( new MessageValue( 'rest-permission-denied-anon' ), 401 )
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
		$reportingUser->method( 'isRegistered' )->willReturn( true );
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
		$dummyBody = [ 'reportedUser' => 'user', 'revisionId' => 123, 'behaviors' => [] ];
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
		$dummyBody = [ 'reportedUser' => 'user', 'revisionId' => 123, 'behaviors' => [] ];
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
		$dummyBody = [ 'reportedUser' => 'user', 'revisionId' => 123, 'behaviors' => [] ];
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

	public function testRevisionDoesntExist() {
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
			'ReportIncidentDeveloperMode' => true,
			'ReportIncidentMinimumAccountAgeInSeconds' => null,
		] );
		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )
			->with( 1 )
			->willReturn( null );
		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class,
			[ 'config' => $config, 'revisionStore' => $revisionStore ]
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

	public function testBodyFailsValidationOnObjectAsReportedUser() {
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
			'ReportIncidentDeveloperMode' => true,
			'ReportIncidentMinimumAccountAgeInSeconds' => null,
		] );
		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )
			->with( 1 )
			->willReturn( $this->createMock( RevisionRecord::class ) );
		$handler = $this->newServiceInstance( ReportHandler::class, [
			'config' => $config,
			'revisionStore' => $revisionStore
		] );
		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'rest-body-validation-error' ), 400 )
		);
		$dummyBody = [ 'reportedUser' => [ 'test' => 'testing' ], 'revisionId' => 123, 'behaviors' => [] ];
		$this->executeHandler( $handler, new RequestData( [ 'parsedBody' => $dummyBody ] ) );
	}

	public function testBodyFailsValidationOnObjectAsDetails() {
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
		$dummyBody = [ 'reportedUser' => 'user', 'revisionId' => 123, 'behaviors' => [],
			'details' => [ 'test' => 'testing' ] ];
		$this->executeHandler( $handler, new RequestData( [ 'parsedBody' => $dummyBody ] ) );
	}

	public function testBodyFailsValidationOnObjectAsSomethingElseDetails() {
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
		$dummyBody = [ 'reportedUser' => 'user', 'revisionId' => 123, 'behaviors' => [],
			'somethingElseDetails' => [ 'test' => 'testing' ] ];
		$this->executeHandler( $handler, new RequestData( [ 'parsedBody' => $dummyBody ] ) );
	}

	public function testTruncationOfTextareaFields() {
		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )
			->with( 1 )
			->willReturn( $this->createMock( RevisionRecord::class ) );
		$userNameUtils = $this->createMock( UserNameUtils::class );
		$userNameUtils->method( 'isIP' )
			->willReturn( true );
		// Return the text from Language::truncateForVisual with "-truncated" added
		// to the end.
		$contentLanguage = $this->createMock( Language::class );
		$contentLanguage
			->expects( $this->exactly( 2 ) )
			->method( 'truncateForVisual' )
			->willReturnCallback( static fn ( $str ) => "$str-truncated" );
		$handler = $this->getMockBuilder( ReportHandler::class )
			->setConstructorArgs( [
				$this->createMock( Config::class ),
				$revisionStore,
				$userNameUtils,
				$this->createMock( UserIdentityLookup::class ),
				$this->createMock( ReportIncidentManager::class ),
				$this->createMock( UserFactory::class ),
				$contentLanguage,
			] )
			->onlyMethods( [ 'getAuthority', 'validateToken' ] )
			->getMock();
		$handler->method( 'getAuthority' )
			->willReturn( $this->mockRegisteredUltimateAuthority() );
		$handler->expects( $this->once() )
			->method( 'validateToken' );
		$handler = TestingAccessWrapper::newFromObject( $handler );
		/** @var IncidentReport $incidentReportObject */
		$incidentReportObject = $handler->getIncidentReportObjectFromValidatedBody( [
			'revisionId' => 1,
			'reportedUser' => '1.2.3.4',
			'somethingElseDetails' => 'testing-something-else',
			'details' => 'testing-details',
			'behaviors' => [ 'test' ]
		] );
		$this->assertSame(
			'testing-something-else-truncated',
			$incidentReportObject->getSomethingElseDetails(),
			'Something else textarea data was not truncated.'
		);
		$this->assertSame(
			'testing-details-truncated',
			$incidentReportObject->getDetails(),
			'Additional details textarea data was not truncated.'
		);
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
		$revisionStore->method( 'getRevisionById' )->willReturn( $this->createMock( RevisionRecord::class ) );
		$reportManager = $this->createMock( ReportIncidentManager::class );
		$reportManager->method( 'record' )->willReturn( StatusValue::newGood() );
		$reportManager->method( 'notify' )->willReturn( IncidentReportEmailStatus::newGood() );
		$userFactory = $this->createMock( UserFactory::class );
		$userIdentityLookup = $this->createMock( UserIdentityLookup::class );
		$registeredUserMock = $this->createMock( User::class );
		$registeredUserMock->method( 'isRegistered' )->willReturn( true );
		$userIdentityLookup->method( 'getUserIdentityByName' )->willReturn( $registeredUserMock );
		$user = $this->createMock( User::class );
		$user->method( 'isEmailConfirmed' )->willReturn( false );
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
				'reportedUser' => 'Foo',
				'revisionId' => 1,
				'behaviors' => [ 'something', 'something_else' ],
				'details' => 'More details'
			],
			$this->mockRegisteredUltimateAuthority()
		);
		$this->assertSame( 200, $response->getStatusCode() );
	}

	/**
	 * @dataProvider provideTestRestPayload
	 */
	public function testRestPayload(
		array $data,
		StatusValue $recordStatus,
		IncidentReportEmailStatus $notifyStatus,
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
		$revisionRecord = $this->createMock( RevisionRecord::class );
		$revisionRecord->method( 'getId' )->willReturn( $data['revisionId'] );
		$revisionStore->method( 'getRevisionById' )
			->with( $data['revisionId'] )
			->willReturn( $revisionRecord );
		// Mock the result of UserIdentityLookup::getUserIdentityByName to always return
		// an existing user.
		$userIdentityLookup = $this->createMock( UserIdentityLookup::class );
		$userIdentity = $this->createMock( UserIdentity::class );
		$userIdentity->method( 'isRegistered' )->willReturn( true );
		$userIdentityLookup->method( 'getUserIdentityByName' )
			->with( $data['reportedUser'] )
			->willReturn( $userIdentity );
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
				'userNameUtils' => $userNameUtils,
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
		$this->assertSame( 200, $response->getStatusCode() );
	}

	/**
	 * Data provider for ::testRestPayload
	 */
	public static function provideTestRestPayload(): array {
		return [
			'correct values' => [
				[
					'reportedUser' => 'test',
					'revisionId' => 1,
					'behaviors' => [ 'something', 'something_else' ],
					'details' => 'More details'
				],
				StatusValue::newGood(),
				IncidentReportEmailStatus::newGood(),
				false,
				false,
			],
			'correct values, empty details' => [
				[
					'reportedUser' => '1.2.3.4',
					'revisionId' => 1,
					'behaviors' => [ 'something', 'something_else' ],
				],
				StatusValue::newGood(),
				IncidentReportEmailStatus::newGood(),
				false,
				false,
			],
			'trigger a TypeError' => [
				[
					'reportedUser' => 'testing12234',
					'revisionId' => 'foo',
					'behaviors' => [ [], [] ]
				],
				StatusValue::newFatal( 'rest-bad-json-body' ),
				IncidentReportEmailStatus::newGood(),
				true,
				false,
			],
			'record fails' => [
				[
					'reportedUser' => 'Userabc',
					'revisionId' => 1,
					'behaviors' => [ 'test' ]
				],
				StatusValue::newFatal( 'rest-bad-json-body' ),
				IncidentReportEmailStatus::newGood(),
				true,
				false,
			],
			'notify fails' => [
				[
					'reportedUser' => 'testuser',
					'revisionId' => 1,
					'behaviors' => [ 'test' ],
				],
				StatusValue::newGood(),
				IncidentReportEmailStatus::newFatal( 'reportincident-unable-to-send' ),
				false,
				true,
			]
		];
	}

	public function testSubmitIncidentReportWithDeveloperMode() {
		$config = new HashConfig( [
			'ReportIncidentDeveloperMode' => true,
		] );
		$reportManager = $this->createMock( ReportIncidentManager::class );
		$incidentReport = $this->createMock( IncidentReport::class );
		// Mock that the ::notify and ::record methods both return good statuses.
		$incidentReportEmailStatus = IncidentReportEmailStatus::newGood();
		$incidentReportEmailStatus->emailContents = [
			'to' => 'test@test.com',
			'from' => [ 'test@testing.com' ],
			'subject' => 'testing',
			'body' => "testing.\ntest"
		];
		$reportManager->method( 'notify' )->with( $incidentReport )
			->willReturn( $incidentReportEmailStatus );
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
		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertArrayEquals(
			[ 'sentEmail' => (object)[
				'to' => 'test@test.com',
				'from' => [ 'test@testing.com' ],
				'subject' => 'testing',
				'body' => "testing.\ntest"
			] ],
			(array)FormatJson::decode( $response->getBody() ),
			true,
			true,
			'Response body did not contain email details on successful response.'
		);
	}

	public function testSubmitIncidentReportWithoutDeveloperMode() {
		$config = new HashConfig( [
			'ReportIncidentDeveloperMode' => false,
		] );
		$reportManager = $this->createMock( ReportIncidentManager::class );
		$incidentReport = $this->createMock( IncidentReport::class );
		// Mock that the ::notify and ::record methods both return good statuses.
		$reportManager->method( 'notify' )->with( $incidentReport )
			->willReturn( IncidentReportEmailStatus::newGood() );
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
		// Pass the revision check
		$revisionStore = $this->createMock( RevisionStore::class );
		$revision = $this->createMock( RevisionRecord::class );
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
			[ 'revisionId' => 1, 'reportedUser' => '127.0.0.1', 'behaviors' => [ 'test' ] ],
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
		$revision = $this->createMock( RevisionRecord::class );
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
			[ 'revisionId' => 1, 'reportedUser' => '127.0.0.1', 'behaviors' => [ 'test' ] ],
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
		$revision = $this->createMock( RevisionRecord::class );
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
			[ 'revisionId' => 1, 'reportedUser' => '127.0.0.1', 'behaviors' => [ 'test' ] ],
			$authority
		);
	}

	public function testInvalidReportedUsername() {
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
			'ReportIncidentDeveloperMode' => true,
			'ReportIncidentMinimumAccountAgeInSeconds' => null,
		] );
		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )
			->willReturn( $this->createMock( RevisionRecord::class ) );
		// Mock that UserNameUtils::isIP returns false.
		$userNameUtils = $this->createMock( UserNameUtils::class );
		$userNameUtils->method( 'isIP' )->willReturn( false );
		// Mock that UserIdentityLookup::getUserIdentityByName returns null.
		$userIdentityLookup = $this->createMock( UserIdentityLookup::class );
		$userIdentityLookup->expects( $this->once() )
			->method( 'getUserIdentityByName' )
			->with( 'testing' )
			->willReturn( null );
		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class,
			[
				'config' => $config,
				'revisionStore' => $revisionStore,
				'userIdentityLookup' => $userIdentityLookup,
				'userNameUtils' => $userNameUtils
			]
		);
		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'reportincident-dialog-violator-nonexistent' ), 404 )
		);
		$this->executeHandler(
			$handler,
			new RequestData( [ 'headers' => [ 'Content-Type' => 'application/json' ] ] ),
			[],
			[],
			[],
			[ 'revisionId' => 1, 'reportedUser' => 'testing' ],
			$this->mockRegisteredUltimateAuthority()
		);
	}

	public function testNonExistingUsername() {
		$config = new HashConfig( [
			'ReportIncidentApiEnabled' => true,
			'ReportIncidentDeveloperMode' => true,
			'ReportIncidentMinimumAccountAgeInSeconds' => null,
		] );
		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )
			->willReturn( $this->createMock( RevisionRecord::class ) );
		// Mock that UserNameUtils::isIP returns false.
		$userNameUtils = $this->createMock( UserNameUtils::class );
		$userNameUtils->method( 'isIP' )->willReturn( false );
		// Mock that UserIdentityLookup::getUserIdentityByName returns a UserIdentity for an unregistered user.
		$userIdentityLookup = $this->createMock( UserIdentityLookup::class );
		$userIdentityLookup->expects( $this->once() )
			->method( 'getUserIdentityByName' )
			->with( 'testing' )
			->willReturn( null );
		/** @var ReportHandler $handler */
		$handler = $this->newServiceInstance( ReportHandler::class,
			[
				'config' => $config,
				'revisionStore' => $revisionStore,
				'userIdentityLookup' => $userIdentityLookup,
				'userNameUtils' => $userNameUtils,
			]
		);
		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'reportincident-dialog-violator-nonexistent' ), 404 )
		);
		$this->executeHandler(
			$handler,
			new RequestData( [ 'headers' => [ 'Content-Type' => 'application/json' ] ] ),
			[],
			[],
			[],
			[ 'revisionId' => 1, 'reportedUser' => 'testing' ],
			$this->mockRegisteredUltimateAuthority()
		);
	}

}
