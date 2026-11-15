<?php
namespace MediaWiki\Extension\ReportIncident\Config;

use Iterator;
use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CommunityConfiguration\Schema\SchemaBuilder;
use MediaWiki\Extension\CommunityConfiguration\Validation\IValidator;
use MediaWiki\Extension\CommunityConfiguration\Validation\ValidationStatus;
use MediaWiki\Extension\CommunityConfiguration\Validation\ValidatorFactory;
use MediaWiki\Page\PageLookup;
use MediaWiki\Revision\ArchivedRevisionLookup;
use MediaWiki\Title\MalformedTitleException;
use MediaWiki\Title\TitleParser;
use MediaWiki\User\UserFactory;

/**
 * Validator class used by CommunityConfiguration for IRS local links.
 */
class ReportIncidentConfigValidator implements IValidator {
	public function __construct(
		private readonly IValidator $jsonSchemaValidator,
		private readonly TitleParser $titleParser,
		private readonly PageLookup $pageLookup,
		private readonly IContextSource $context,
		private readonly ArchivedRevisionLookup $archivedRevisionLookup,
		private readonly UserFactory $userFactory,
	) {
	}

	public static function factory(
		TitleParser $titleParser,
		PageLookup $pageLookup,
		ArchivedRevisionLookup $archivedRevisionLookup,
		UserFactory $userFactory,
		ValidatorFactory $validatorFactory,
		string $jsonSchema,
		?IContextSource $context = null
	): self {
		$jsonSchemaValidator = $validatorFactory->newValidator(
			'ReportIncident',
			'jsonschema',
			[ $jsonSchema ],
		);

		$context ??= RequestContext::getMain();

		return new self(
			$jsonSchemaValidator,
			$titleParser,
			$pageLookup,
			$context,
			$archivedRevisionLookup,
			$userFactory
		);
	}

	/** @inheritDoc */
	public function validateStrictly( $config, ?string $version = null ): ValidationStatus {
		$status = $this->jsonSchemaValidator->validateStrictly( $config, $version );
		if ( !$status->isOK() ) {
			return $status;
		}

		return $this->validateConfig( $config );
	}

	/**
	 * Skip validateConfig on permissive validation (config reads) as it's very expensive. It's
	 * only important to validate on write to ensure that configurations that require pages to
	 * exist. If moving a page breaks the configuration, it shouldn't break the entire feature.
	 *
	 * @inheritDoc
	 */
	public function validatePermissively( $config, ?string $version = null ): ValidationStatus {
		return $this->jsonSchemaValidator->validatePermissively( $config, $version );
	}

	/**
	 * Validate values supplied by community configuration.
	 * @param \stdClass $config
	 * @return ValidationStatus
	 */
	private function validateConfig( $config ): ValidationStatus {
		$status = new ValidationStatus();

		$testers = $config->ReportIncidentE2ETesterUsers ?? [];
		if ( count( $testers ) ) {
			foreach ( $testers as $testerUsername ) {
				$testerUser = $this->userFactory->newFromName( $testerUsername );
				if ( !$testerUser->getId() ) {
					$status->addFatal(
						'ReportIncidentE2ETesterUsers',
						"/ReportIncidentE2ETesterUsers",
						$this->context->msg( 'communityconfiguration-reportincident-nonexistent-user-error' )->text()
					);
					continue;
				}
			}
		}

		$wikiPages = [
			'ReportIncident_NonEmergency_Intimidation_DisputeResolutionURL',
			[ 'ReportIncident_NonEmergency_Intimidation_HelpMethod', 'ContactAdmin' ],
			[ 'ReportIncident_NonEmergency_Intimidation_HelpMethod', 'ContactCommunity' ],
			'ReportIncident_NonEmergency_Doxing_HideEditURL',
			[ 'ReportIncident_NonEmergency_Doxing_HelpMethod', 'WikiEmailURL' ],
			[ 'ReportIncident_NonEmergency_Doxing_HelpMethod', 'OtherURL' ],
			[ 'ReportIncident_NonEmergency_SexualHarassment_HelpMethod', 'ContactAdmin' ],
			[ 'ReportIncident_NonEmergency_SexualHarassment_HelpMethod', 'ContactCommunity' ],
			[ 'ReportIncident_NonEmergency_Trolling_HelpMethod', 'ContactAdmin' ],
			[ 'ReportIncident_NonEmergency_Trolling_HelpMethod', 'ContactCommunity' ],
			[ 'ReportIncident_NonEmergency_HateSpeech_HelpMethod', 'ContactAdmin' ],
			'ReportIncident_NonEmergency_Spam_SpamContentURL',
			[ 'ReportIncident_NonEmergency_Spam_HelpMethod', 'ContactAdmin' ],
			'ReportIncident_NonEmergency_Other_DisputeResolutionURL',
			[ 'ReportIncident_NonEmergency_SomethingElse_HelpMethod', 'ContactAdmin' ],
			[ 'ReportIncident_NonEmergency_SomethingElse_HelpMethod', 'ContactCommunity' ],
			[ 'ReportIncident_NonEmergency_Sockpuppetry_HelpMethod', 'ContactAdmin' ],
			[ 'ReportIncident_NonEmergency_Sockpuppetry_HelpMethod', 'ContactCommunity' ],
			[ 'ReportIncident_NonEmergency_Vandalism_HelpMethod', 'ContactAdmin' ],
			[ 'ReportIncident_NonEmergency_Vandalism_HelpMethod', 'ContactCommunity' ],
			[ 'ReportIncident_NonEmergency_UserDispute_HelpMethod', 'ContactAdmin' ],
			[ 'ReportIncident_NonEmergency_UserDispute_HelpMethod', 'ContactCommunity' ],
			[ 'ReportIncident_NonEmergency_DisruptiveEditing_HelpMethod', 'ContactAdmin' ],
			[ 'ReportIncident_NonEmergency_DisruptiveEditing_HelpMethod', 'ContactCommunity' ],
			[ 'ReportIncident_NonEmergency_Other_HelpMethod', 'ContactAdmin' ],
			[ 'ReportIncident_NonEmergency_Other_HelpMethod', 'ContactCommunity' ],
		];
		foreach ( $wikiPages as $key ) {
			try {
				// If key is an array, it represents a nested value
				if ( is_array( $key ) ) {
					$value = $config;
					foreach ( $key as $singleKey ) {
						$value = $value->$singleKey ?? '';
					}

					// Convert key into a string so that it can be displayed in the error
					$key = implode( '/', $key );
				} else {
					// Treat an empty string value as if the value was not set.
					$value = $config->$key ?? '';
				}
				if ( $value === '' ) {
					continue;
				}

				// If the value is a URL, check if it's valid and let it pass if so before Title checks
				if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
					$url = parse_url( $value );

					// Force https
					if ( !isset( $url['scheme'] ) || $url['scheme'] !== 'https' ) {
						$status->addFatal(
							$key,
							"/$key",
							$this->context->msg( 'communityconfiguration-reportincident-invalid-usehttps' )->text()
						);
						continue;
					}

					// If parameters are set, all of them must have a value
					$parameters = [];
					if ( isset( $url['query'] ) ) {
						parse_str( $url['query'], $parameters );
					}
					foreach ( $parameters as $parameter => $parameterValue ) {
						if ( $parameterValue === '' ) {
							$status->addFatal(
								$key,
								"/$key",
								$this
									->context
									->msg(
										'communityconfiguration-reportincident-invalid-queryparameter', $parameter
									)
									->text()
							);
						}
						continue;
					}

					// Valid URL; pass
					continue;
				}

				$title = $this->titleParser->parseTitle( $value );

				if ( $title->isExternal() ) {
					continue;
				}

				if ( $title->getNamespace() <= NS_SPECIAL ) {
					$status->addFatal(
						$key,
						"/$key",
						$this->context->msg( 'communityconfiguration-reportincident-invalid-title' )->text()
					);
					continue;
				}

				$page = $this->pageLookup->getPageForLink( $title );

				// Check if the page was deleted, defined by a revision id being associated with the archived
				// page. These still need to be considered valid as if a page is deleted, the input will be rendered
				// invalid and CommunityConfig will fail to load.
				$archivedPage = $this->archivedRevisionLookup->getLastRevisionId( $page );

				if ( !$page->exists() && !$archivedPage ) {
					$status->addFatal(
						$key,
						"/$key",
						$this->context->msg( 'communityconfiguration-reportincident-invalid-title' )->text()
					);
				}
			} catch ( MalformedTitleException $e ) {
				$status->addFatal(
					$key,
					"/$key",
					$this->context->msg( $e->getMessageObject() )->text()
				);
			}
		}

		$emails = [
			[ 'ReportIncident_NonEmergency_Intimidation_HelpMethod', 'Email' ],
			[ 'ReportIncident_NonEmergency_Doxing_HelpMethod', 'Email' ],
			[ 'ReportIncident_NonEmergency_SexualHarassment_HelpMethod', 'Email' ],
			[ 'ReportIncident_NonEmergency_Trolling_HelpMethod', 'Email' ],
			[ 'ReportIncident_NonEmergency_HateSpeech_HelpMethod', 'Email' ],
			[ 'ReportIncident_NonEmergency_Spam_HelpMethod', 'Email' ],
			[ 'ReportIncident_NonEmergency_SomethingElse_HelpMethod', 'Email' ],
			[ 'ReportIncident_NonEmergency_Sockpuppetry_HelpMethod', 'Email' ],
			[ 'ReportIncident_NonEmergency_Vandalism_HelpMethod', 'Email' ],
			[ 'ReportIncident_NonEmergency_UserDispute_HelpMethod', 'Email' ],
			[ 'ReportIncident_NonEmergency_DisruptiveEditing_HelpMethod', 'Email' ],
			[ 'ReportIncident_NonEmergency_Other_HelpMethod', 'Email' ],
		];
		foreach ( $emails as $key ) {
			// If key is an array, it represents a nested value
			if ( is_array( $key ) ) {
				$value = $config;
				foreach ( $key as $singleKey ) {
					$value = $value->$singleKey ?? '';
				}

				// Convert key into a string so that it can be displayed in the error
				$key = implode( '/', $key );
			} else {
				// Treat an empty string value as if the value was not set.
				$value = $config->$key ?? '';
			}
			if ( $value === '' ) {
				continue;
			}

			// Taken from Parser's Sanitizer::validateEmail
			// Please note strings below are enclosed in brackets [], this make the
			// hyphen "-" a range indicator. Hence it is double backslashed below.
			// See T28948
			$rfc5322_atext = "a-z0-9!#$%&'*+\\-\/=?^_`{|}~";
			$rfc1034_ldh_str = "a-z0-9\\-";
			$html5_email_regexp = "/
			^                      # start of string
			[$rfc5322_atext\\.]+    # user part which is liberal :p
			@                      # 'apostrophe'
			[$rfc1034_ldh_str]+       # First domain part
			(\\.[$rfc1034_ldh_str]+)*  # Following part prefixed with a dot
			$                      # End of string
			/ix";
			// ^ case Insensitive, eXtended
			if ( !preg_match( $html5_email_regexp, $value ) ) {
				$status->addFatal(
					$key,
					"/$key",
					$this->context->msg( 'communityconfiguration-reportincident-invalid-email' )->text()
				);
			}
		}

		return $status;
	}

	/** @inheritDoc */
	public function areSchemasSupported(): bool {
		return $this->jsonSchemaValidator->areSchemasSupported();
	}

	/** @inheritDoc */
	public function getSchemaBuilder(): SchemaBuilder {
		return $this->jsonSchemaValidator->getSchemaBuilder();
	}

	/** @inheritDoc */
	public function getSchemaIterator(): Iterator {
		return $this->jsonSchemaValidator->getSchemaIterator();
	}

	/** @inheritDoc */
	public function getSchemaVersion(): ?string {
		return $this->jsonSchemaValidator->getSchemaVersion();
	}
}
