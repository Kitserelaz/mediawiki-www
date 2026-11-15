<?php

namespace MediaWiki\Extension\ReportIncident\Tests\Integration\Config;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CommunityConfiguration\CommunityConfigurationServices;
use MediaWiki\Extension\ReportIncident\Config\ReportIncidentConfigValidator;
use MediaWiki\Extension\ReportIncident\Config\ReportIncidentSchema;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\ReportIncident\Config\ReportIncidentConfigValidator
 * @covers \MediaWiki\Extension\ReportIncident\Config\ReportIncidentSchema
 * @group Database
 */
class ReportIncidentConfigValidatorTest extends MediaWikiIntegrationTestCase {
	private const TEST_EXISTING_PAGE = 'ReportIncidentExistingPage';

	private ReportIncidentConfigValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'CommunityConfiguration' );

		$context = new RequestContext();
		$context->setLanguage( 'qqx' );

		$this->validator = ReportIncidentConfigValidator::factory(
			$this->getServiceContainer()->getTitleParser(),
			$this->getServiceContainer()->getPageStore(),
			$this->getServiceContainer()->getArchivedRevisionLookup(),
			$this->getServiceContainer()->getUserFactory(),
			CommunityConfigurationServices::wrap( $this->getServiceContainer() )->getValidatorFactory(),
			ReportIncidentSchema::class,
			$context
		);
	}

	public function addDBDataOnce() {
		$this->getExistingTestPage( self::TEST_EXISTING_PAGE );

		// Guarantee a user exists to later test against the user input validator
		$this->getTestSysop();
	}

	/**
	 * @dataProvider provideValidationErrors
	 */
	public function testPermissiveValidationSucceeds( array $config, array $expectedErrors ) {
		$status = $this->validator->validatePermissively( (object)$config );
		$this->assertStatusGood( $status );
	}

	/**
	 * @dataProvider provideValidationErrors
	 */
	public function testStrictValidationFails( array $config, array $expectedErrors ) {
		$status = $this->validator->validateStrictly( (object)$config );
		$errors = $status->getValidationErrorsData();

		$this->assertStatusNotGood( $status );
		$this->assertSame( $expectedErrors, $errors );
	}

	/**
	 * Return the base config with a customized field to test.
	 * This allows both object and array values to be set, as well as the object
	 * to be re-instantiated between tests in order to avoid test pollution.
	 *
	 * @param string $name
	 * @param string $value
	 * @return array
	 */
	private static function getConfigUnderTest( $name, $value ) {
		$baseConfig = [
			'ReportIncidentEnabledNamespaces' => [],
			'ReportIncidentE2ETesterUsers' => [],
			'ReportIncident_NonEmergency_Intimidation_DisputeResolutionURL' => '',
			'ReportIncident_NonEmergency_Intimidation_HelpMethod' => (object)[
				'ContactAdmin' => '',
				'ContactCommunity' => '',
				'Email' => '',
			],
			'ReportIncident_NonEmergency_Doxing_HideEditURL' => '',
			'ReportIncident_NonEmergency_Doxing_HelpMethod' => (object)[
				'WikiEmailURL' => '',
				'OtherURL' => '',
				'Email' => '',
			],
			'ReportIncident_NonEmergency_SexualHarassment_HelpMethod' => (object)[
				'ContactAdmin' => '',
				'ContactCommunity' => '',
				'Email' => '',
			],
			'ReportIncident_NonEmergency_Trolling_HelpMethod' => (object)[
				'ContactAdmin' => '',
				'ContactCommunity' => '',
				'Email' => '',
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
				'ContactCommunity' => '',
				'Email' => '',
			],
		];

		$configUnderTest = $baseConfig;
		$keys = explode( '/', $name );
		if ( count( $keys ) === 2 ) {
			$toMerge = $configUnderTest[ $keys[ 0 ] ];
			$attr = $keys[1];
			$toMerge->$attr = $value;
			$configUnderTest[ $keys[ 0 ] ] = $toMerge;
		} else {
			$configUnderTest[ $name ] = $value;
		}
		return $configUnderTest;
	}

	public static function provideValidationErrors(): iterable {
		$emailProps = [
			'ReportIncident_NonEmergency_Intimidation_HelpMethod/Email',
			'ReportIncident_NonEmergency_Doxing_HelpMethod/Email',
			'ReportIncident_NonEmergency_SexualHarassment_HelpMethod/Email',
			'ReportIncident_NonEmergency_Trolling_HelpMethod/Email',
			'ReportIncident_NonEmergency_HateSpeech_HelpMethod/Email',
			'ReportIncident_NonEmergency_Spam_HelpMethod/Email',
			'ReportIncident_NonEmergency_SomethingElse_HelpMethod/Email',
		];

		foreach ( $emailProps as $name ) {
			yield "invalid email for config option \"$name\"" => [
				self::getConfigUnderTest( $name, 'foo' ),
				[
					[
						'property' => $name,
						'pointer' => "/$name",
						'messageLiteral' => '(communityconfiguration-reportincident-invalid-email)',
						'additionalData' => [],
					],
				],
			];
		}

		$titleProps = [
			'ReportIncident_NonEmergency_Intimidation_DisputeResolutionURL',
			'ReportIncident_NonEmergency_Intimidation_HelpMethod/ContactAdmin',
			'ReportIncident_NonEmergency_Intimidation_HelpMethod/ContactCommunity',
			'ReportIncident_NonEmergency_Doxing_HideEditURL',
			'ReportIncident_NonEmergency_Doxing_HelpMethod/WikiEmailURL',
			'ReportIncident_NonEmergency_Doxing_HelpMethod/OtherURL',
			'ReportIncident_NonEmergency_SexualHarassment_HelpMethod/ContactAdmin',
			'ReportIncident_NonEmergency_SexualHarassment_HelpMethod/ContactCommunity',
			'ReportIncident_NonEmergency_Trolling_HelpMethod/ContactAdmin',
			'ReportIncident_NonEmergency_Trolling_HelpMethod/ContactCommunity',
			'ReportIncident_NonEmergency_HateSpeech_HelpMethod/ContactAdmin',
			'ReportIncident_NonEmergency_Spam_SpamContentURL',
			'ReportIncident_NonEmergency_Spam_HelpMethod/ContactAdmin',
			'ReportIncident_NonEmergency_Other_DisputeResolutionURL',
			'ReportIncident_NonEmergency_SomethingElse_HelpMethod/ContactAdmin',
			'ReportIncident_NonEmergency_SomethingElse_HelpMethod/ContactCommunity',
		];

		foreach ( $titleProps as $name ) {
			yield "invalid title for config option \"$name\"" => [
				self::getConfigUnderTest( $name, ':' ),
				[
					[
						'property' => $name,
						'pointer' => "/$name",
						'messageLiteral' => '(title-invalid-empty: &#58;)',
						'additionalData' => [],
					],
				],
			];

			yield "missing title for config option \"$name\"" => [
				self::getConfigUnderTest( $name, 'ReportIncidentNoSuchPageExists' ),
				[
					[
						'property' => $name,
						'pointer' => "/$name",
						'messageLiteral' => '(communityconfiguration-reportincident-invalid-title)',
						'additionalData' => [],
					],
				],
			];

			yield "special page for config option \"$name\"" => [
				self::getConfigUnderTest( $name, 'Special:Version' ),
				[
					[
						'property' => $name,
						'pointer' => "/$name",
						'messageLiteral' => '(communityconfiguration-reportincident-invalid-title)',
						'additionalData' => [],
					],
				],
			];

			yield "URL for \"$name\" doesn't use https" => [
				self::getConfigUnderTest( $name, 'http://example.com' ),
				[
					[
						'property' => $name,
						'pointer' => "/$name",
						'messageLiteral' => '(communityconfiguration-reportincident-invalid-usehttps)',
						'additionalData' => [],
					],
				],
			];

			yield "URL for \"$name\" doesn't have a valid query string" => [
				self::getConfigUnderTest( $name, 'http://example.com?foo=bar&baz=' ),
				[
					[
						'property' => $name,
						'pointer' => "/$name",
						'messageLiteral' => '(communityconfiguration-reportincident-invalid-usehttps)',
						'additionalData' => [],
					],
				],
			];
		}
	}

	/**
	 * @dataProvider provideValidConfig
	 */
	public function testPermissiveValidationSuccess( array $config ): void {
		$status = $this->validator->validatePermissively( (object)$config );

		$this->assertStatusOK( $status );
	}

	/**
	 * @dataProvider provideValidConfig
	 */
	public function testStrictValidationSuccess( array $config ): void {
		$status = $this->validator->validateStrictly( (object)$config );

		$this->assertStatusOK( $status );
	}

	public static function provideValidConfig(): iterable {
		yield 'namespaces set' => [
			[
				'ReportIncidentEnabledNamespaces' => [ NS_PROJECT ],
			],
		];

		yield 'all pages set' => [
			[
				'ReportIncident_NonEmergency_Intimidation_DisputeResolutionURL' => self::TEST_EXISTING_PAGE,
				'ReportIncident_NonEmergency_Intimidation_HelpMethod' => (object)[
					'ContactAdmin' => self::TEST_EXISTING_PAGE,
					'ContactCommunity' => self::TEST_EXISTING_PAGE,
					'Email' => 'foo@bar.com',
				],
				'ReportIncident_NonEmergency_Doxing_HideEditURL' => self::TEST_EXISTING_PAGE,
				'ReportIncident_NonEmergency_Doxing_HelpMethod' => (object)[
					'WikiEmailURL' => self::TEST_EXISTING_PAGE,
					'OtherURL' => self::TEST_EXISTING_PAGE,
					'Email' => 'foo@bar.com',
				],
				'ReportIncident_NonEmergency_SexualHarassment_HelpMethod' => (object)[
					'ContactAdmin' => self::TEST_EXISTING_PAGE,
					'ContactCommunity' => self::TEST_EXISTING_PAGE,
					'Email' => 'foo@bar.com',
				],
				'ReportIncident_NonEmergency_Trolling_HelpMethod' => (object)[
					'ContactAdmin' => self::TEST_EXISTING_PAGE,
					'ContactCommunity' => self::TEST_EXISTING_PAGE,
					'Email' => 'foo@bar.com',
				],
				'ReportIncident_NonEmergency_HateSpeech_HelpMethod' => (object)[
					'ContactAdmin' => self::TEST_EXISTING_PAGE,
					'Email' => 'foo@bar.com',
				],
				'ReportIncident_NonEmergency_Spam_SpamContentURL' => '',
				'ReportIncident_NonEmergency_Spam_HelpMethod' => (object)[
					'ContactAdmin' => self::TEST_EXISTING_PAGE,
					'Email' => 'foo@bar.com',
				],
				'ReportIncident_NonEmergency_Other_DisputeResolutionURL' => self::TEST_EXISTING_PAGE,
				'ReportIncident_NonEmergency_SomethingElse_HelpMethod' => (object)[
					'ContactAdmin' => self::TEST_EXISTING_PAGE,
					'ContactCommunity' => self::TEST_EXISTING_PAGE,
					'Email' => 'foo@bar.com',
				],
			],
		];

		yield 'valid test user enabled' => [
			[
				'ReportIncidentE2ETesterUsers' => [ 'UTSysop' ],
			],
		];
	}
}
