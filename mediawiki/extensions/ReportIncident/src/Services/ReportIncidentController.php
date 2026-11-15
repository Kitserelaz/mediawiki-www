<?php

namespace MediaWiki\Extension\ReportIncident\Services;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReportIncident\Api\Rest\Handler\ReportHandler;
use MediaWiki\Output\OutputPage;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Controls whether the reporting links and dialog should be shown.
 */
class ReportIncidentController {

	public function __construct(
		private readonly Config $config,
		private readonly Config $localConfig,
	) {
	}

	/**
	 * Should the reporting link / button be shown in the current namespace
	 *
	 * @param int $namespace
	 * @return bool
	 */
	private function shouldShowButtonForNamespace( int $namespace ): bool {
		return in_array( $namespace, $this->getLocalConfig( 'ReportIncidentEnabledNamespaces' ) );
	}

	/**
	 * Should the reporting link / button be shown for the current user
	 *
	 * This should only be shown to users eligible to send a report unless their
	 * only ineligibility is an unverified email address in which case, show anyway
	 * to improve discoveribility.
	 *
	 * @param User $user
	 * @param bool $skipEligibilityChecks For developer use and for testing (selenium)
	 * @return bool
	 */
	private function shouldShowButtonForUser( User $user, $skipEligibilityChecks = false ): bool {
		// User should be named, regardless of any subsequent bypasses
		if ( !$user->isNamed() ) {
			return false;
		}

		$isDeveloperMode = $this->config->get( 'ReportIncidentDeveloperMode' );

		// If pretending like the user is eligible, ignore all checks.
		if ( $isDeveloperMode && $skipEligibilityChecks ) {
			return true;
		}

		// if user is an e2e tester, show them the button regardless of eligibility
		$e2eTesters = (array)$this->localConfig->get( 'ReportIncidentE2ETesterUsers' );
		if ( in_array( $user->getName(), $e2eTesters ) ) {
			return true;
		}

		if ( $user->getEditCount() === 0 ) {
			return false;
		}

		// Prevent users targeted by any block, including unrelated partial blocks, from submitting reports.
		// (T378778)
		if ( $user->getBlock() ) {
			return false;
		}

		$now = (int)ConvertibleTimestamp::now();
		$registrationTime = (int)$user->getRegistration();
		$reportIncidentMinimumAccountAgeInSeconds = $this->config->get( 'ReportIncidentMinimumAccountAgeInSeconds' );
		if ( $registrationTime &&
			$reportIncidentMinimumAccountAgeInSeconds &&
			!$isDeveloperMode &&
			( ( $now - $registrationTime ) < $reportIncidentMinimumAccountAgeInSeconds ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Is the reporting button / link (and reporting dialog) enabled.
	 *
	 * @return bool
	 */
	private function isButtonEnabled(): bool {
		return $this->config->get( 'ReportIncidentReportButtonEnabled' );
	}

	/**
	 * Should the reporting link / button be shown for the current
	 * namespace and user.
	 *
	 * This can also be used to determine whether to add the HTML
	 * for the reporting dialog in a given request.
	 *
	 * @param IContextSource $context The context associated with the current request.
	 * @return bool Whether the button / link should be shown.
	 */
	public function shouldAddMenuItem( IContextSource $context ): bool {
		return $this->isButtonEnabled() &&
			$this->shouldShowButtonForNamespace( $context->getTitle()->getNamespace() ) &&
			$this->shouldShowButtonForUser(
				$context->getUser(),
				$context->getRequest() ?
					$context->getRequest()->getBool( 'withconfirmedemail' ) : false
			);
	}

	/**
	 * Load the modules and associated JS configuration variables
	 * to allow use of the ReportIncident dialog.
	 *
	 * Should only be called after the other methods in this
	 * service to check if the button should be shown are
	 * consulted.
	 *
	 * @param OutputPage $output The OutputPage object associated with the current response
	 * @return void
	 */
	public function addModulesAndConfigVars( OutputPage $output ): void {
		$user = $output->getUser();
		$isDeveloperMode = $this->config->get( 'ReportIncidentDeveloperMode' );
		$pretendUserHasConfirmedEmail = $isDeveloperMode && $output->getRequest()->getBool( 'withconfirmedemail' );
		$pretendUserHasEmail = $isDeveloperMode && $output->getRequest()->getBool( 'withemail' );

		$communityConfigIntimidationHelpMethods = $this->localConfig
			->get( 'ReportIncident_NonEmergency_Intimidation_HelpMethod' );
		$communityConfigDoxingHelpMethods = $this->localConfig
			->get( 'ReportIncident_NonEmergency_Doxing_HelpMethod' );
		$communityConfigSexualHarassmentHelpMethods = $this->localConfig
			->get( 'ReportIncident_NonEmergency_SexualHarassment_HelpMethod' );
		$communityConfigTrollingHelpMethods = $this->localConfig
			->get( 'ReportIncident_NonEmergency_Trolling_HelpMethod' );
		$communityConfigHateSpeechHelpMethods = $this->localConfig
			->get( 'ReportIncident_NonEmergency_HateSpeech_HelpMethod' );
		$communityConfigSpamHelpMethods = $this->localConfig
			->get( 'ReportIncident_NonEmergency_Spam_HelpMethod' );
		$communityConfigSomethingElseHelpMethods = $this->localConfig
			->get( 'ReportIncident_NonEmergency_SomethingElse_HelpMethod' );
		$communityConfigSockpuppetryHelpMethods = $this->localConfig
			->get( 'ReportIncident_NonEmergency_Sockpuppetry_HelpMethod' );
		$communityConfigVandalismHelpMethods = $this->localConfig
			->get( 'ReportIncident_NonEmergency_Vandalism_HelpMethod' );
		$communityConfigUserDisputeHelpMethods = $this->localConfig
			->get( 'ReportIncident_NonEmergency_UserDispute_HelpMethod' );
		$communityConfigDisruptiveEditingHelpMethods = $this->localConfig
			->get( 'ReportIncident_NonEmergency_DisruptiveEditing_HelpMethod' );
		$communityConfigOtherHelpMethods = $this->localConfig
			->get( 'ReportIncident_NonEmergency_Other_HelpMethod' );
		$output->addJsConfigVars( [
			// If in developer mode, pretend the user has a confirmed email if the query parameter is set to
			// 'withconfirmedemail=1', otherwise use DB value.
			'wgReportIncidentUserHasConfirmedEmail' => $pretendUserHasConfirmedEmail ?: $user->isEmailConfirmed(),
			// If in developer mode, pretend the user has an email set if the query parameter is set to
			// 'withemail=1', otherwise use DB value.
			'wgReportIncidentUserHasEmail' => $pretendUserHasEmail ?: $user->getEmail() !== '',
			// Control whether instrumentation is enabled pending approval (T372823).
			'wgReportIncidentEnableInstrumentation' => $this->config->get( 'ReportIncidentEnableInstrumentation' ),
			'wgReportIncidentDetailsCodePointLength' => ReportHandler::MAX_DETAILS_LENGTH,
			// Config that defines users who can e2e test without actually sending a report
			'wgReportIncidentE2ETesterUsers' => $this->localConfig->get( 'ReportIncidentE2ETesterUsers' ) ?? [],
			// Config that defines what categories are shown
			'wgReportIncidentEnabledNonEmergencyCategories' =>
				$this->config->get( 'ReportIncidentEnabledNonEmergencyCategories' ) ?? [],
			// Non-Emergency help methods
			'wgReportIncidentNonEmergencyIntimidationDisputeResolutionURL' =>
				$this->localConfig->get( 'ReportIncident_NonEmergency_Intimidation_DisputeResolutionURL' ),
			'wgReportIncidentNonEmergencyIntimidationHelpMethodContactAdmin' =>
				$this->getFullUrl( $communityConfigIntimidationHelpMethods->ContactAdmin ),
			'wgReportIncidentNonEmergencyIntimidationHelpMethodEmail' =>
				$communityConfigIntimidationHelpMethods->Email,
			'wgReportIncidentNonEmergencyIntimidationHelpMethodContactCommunity' =>
				$this->getFullUrl( $communityConfigIntimidationHelpMethods->ContactCommunity ),
			'wgReportIncidentNonEmergencyDoxingShowWarning' =>
				$this->localConfig->get( 'ReportIncident_NonEmergency_Doxing_ShowWarning' ),
			'wgReportIncidentNonEmergencyDoxingHideEditURL' =>
				$this->localConfig->get( 'ReportIncident_NonEmergency_Doxing_HideEditURL' ),
			// For doxing help methods, disable all other configured methods if stewards email is enabled
			'wgReportIncidentNonEmergencyDoxingHelpMethodWikiEmailURL' =>
				$communityConfigDoxingHelpMethods->EmailStewards ? '' : $communityConfigDoxingHelpMethods->WikiEmailURL,
			'wgReportIncidentNonEmergencyDoxingHelpMethodEmail' =>
				$communityConfigDoxingHelpMethods->EmailStewards ? '' : $communityConfigDoxingHelpMethods->Email,
			'wgReportIncidentNonEmergencyDoxingHelpMethodOtherURL' =>
				$communityConfigDoxingHelpMethods->EmailStewards ? '' : $communityConfigDoxingHelpMethods->OtherURL,
			'wgReportIncidentNonEmergencyDoxingHelpMethodEmailStewards' =>
				$communityConfigDoxingHelpMethods->EmailStewards,
			'wgReportIncidentNonEmergencySexualHarassmentHelpMethodContactAdmin' =>
				$this->getFullUrl( $communityConfigSexualHarassmentHelpMethods->ContactAdmin ),
			'wgReportIncidentNonEmergencySexualHarassmentHelpMethodEmail' =>
				$communityConfigSexualHarassmentHelpMethods->Email,
			'wgReportIncidentNonEmergencySexualHarassmentHelpMethodContactCommunity' =>
				$this->getFullUrl( $communityConfigSexualHarassmentHelpMethods->ContactCommunity ),
			'wgReportIncidentNonEmergencyTrollingHelpMethodContactAdmin' =>
				$this->getFullUrl( $communityConfigTrollingHelpMethods->ContactAdmin ),
			'wgReportIncidentNonEmergencyTrollingHelpMethodEmail' =>
				$communityConfigTrollingHelpMethods->Email,
			'wgReportIncidentNonEmergencyTrollingHelpMethodContactCommunity' =>
				$this->getFullUrl( $communityConfigTrollingHelpMethods->ContactCommunity ),
			'wgReportIncidentNonEmergencyHateSpeechHelpMethodContactAdmin' =>
				$this->getFullUrl( $communityConfigHateSpeechHelpMethods->ContactAdmin ),
			'wgReportIncidentNonEmergencyHateSpeechHelpMethodEmail' =>
				$communityConfigHateSpeechHelpMethods->Email,
			'wgReportIncidentNonEmergencySpamSpamContentURL' =>
				$this->localConfig->get( 'ReportIncident_NonEmergency_Spam_SpamContentURL' ),
			'wgReportIncidentNonEmergencySpamHelpMethodContactAdmin' =>
				$this->getFullUrl( $communityConfigSpamHelpMethods->ContactAdmin ),
			'wgReportIncidentNonEmergencySpamHelpMethodEmail' =>
				$communityConfigSpamHelpMethods->Email,
			'wgReportIncidentNonEmergencyOtherDisputeResolutionURL' =>
				$this->localConfig->get( 'ReportIncident_NonEmergency_Other_DisputeResolutionURL' ),
			'wgReportIncidentNonEmergencySomethingElseHelpMethodContactAdmin' =>
				$this->getFullUrl( $communityConfigSomethingElseHelpMethods->ContactAdmin ),
			'wgReportIncidentNonEmergencySomethingElseHelpMethodEmail' =>
				$communityConfigSomethingElseHelpMethods->Email,
			'wgReportIncidentNonEmergencySomethingElseHelpMethodContactCommunity' =>
				$this->getFullUrl( $communityConfigSomethingElseHelpMethods->ContactCommunity ),
			'wgReportIncidentNonEmergencySockpuppetryHelpMethodContactAdmin' =>
				$this->getFullUrl( $communityConfigSockpuppetryHelpMethods->ContactAdmin ),
			'wgReportIncidentNonEmergencySockpuppetryHelpMethodEmail' =>
				$communityConfigSockpuppetryHelpMethods->Email,
			'wgReportIncidentNonEmergencySockpuppetryHelpMethodContactCommunity' =>
				$this->getFullUrl( $communityConfigSockpuppetryHelpMethods->ContactCommunity ),
			'wgReportIncidentNonEmergencyVandalismHelpMethodContactAdmin' =>
				$this->getFullUrl( $communityConfigVandalismHelpMethods->ContactAdmin ),
			'wgReportIncidentNonEmergencyVandalismHelpMethodEmail' =>
				$communityConfigVandalismHelpMethods->Email,
			'wgReportIncidentNonEmergencyVandalismHelpMethodContactCommunity' =>
				$this->getFullUrl( $communityConfigVandalismHelpMethods->ContactCommunity ),
			'wgReportIncidentNonEmergencyUserDisputeHelpMethodContactAdmin' =>
				$this->getFullUrl( $communityConfigUserDisputeHelpMethods->ContactAdmin ),
			'wgReportIncidentNonEmergencyUserDisputeHelpMethodEmail' =>
				$communityConfigUserDisputeHelpMethods->Email,
			'wgReportIncidentNonEmergencyUserDisputeHelpMethodContactCommunity' =>
				$this->getFullUrl( $communityConfigUserDisputeHelpMethods->ContactCommunity ),
			'wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodContactAdmin' =>
				$this->getFullUrl( $communityConfigDisruptiveEditingHelpMethods->ContactAdmin ),
			'wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodEmail' =>
				$communityConfigDisruptiveEditingHelpMethods->Email,
			'wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodContactCommunity' =>
				$this->getFullUrl( $communityConfigDisruptiveEditingHelpMethods->ContactCommunity ),
			'wgReportIncidentNonEmergencyOtherHelpMethodContactAdmin' =>
				$this->getFullUrl( $communityConfigOtherHelpMethods->ContactAdmin ),
			'wgReportIncidentNonEmergencyOtherHelpMethodEmail' =>
				$communityConfigOtherHelpMethods->Email,
			'wgReportIncidentNonEmergencyOtherHelpMethodContactCommunity' =>
				$this->getFullUrl( $communityConfigOtherHelpMethods->ContactCommunity ),
		] );
		// Add the ReportIncident module, including the JS and Vue code for the dialog.
		$output->addModules( 'ext.reportIncident' );
		if ( $output->getSkin()->getSkinName() === 'minerva' ) {
			// Load custom menu styles for Minerva; see the 'skinStyles' property of the RL module
			// loaded below.
			$output->addModuleStyles( 'ext.reportIncident.menuStyles' );
		}
	}

	/**
	 * Get a config option from IRS's community configuration,
	 * falling back to the global config if no value was set.
	 *
	 * @param string $key The key to look up in the configuration.
	 * @return mixed
	 */
	private function getLocalConfig( string $key ) {
		$localValue = $this->localConfig->get( $key );
		return $localValue ?: $this->config->get( $key );
	}

	/**
	 * Some config options support either a Title or a URL.
	 * Ensure that a URL is always passed back so that it can be
	 * consistently passed through to the message.
	 *
	 * @param string $value Either a Title or a URL
	 * @return string
	 */
	private function getFullUrl( string $value ) {
		if ( !$value ) {
			return '';
		}

		// Nothing to do if the value is already a URL
		if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return $value;
		}

		$title = Title::newFromText( $value );
		if ( $title ) {
			return $title->getFullURL();
		}

		return '';
	}
}
