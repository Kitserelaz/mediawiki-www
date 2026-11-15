<template>
	<email-alert-dialog v-model:open="emailAlertOpen">
	</email-alert-dialog>
	<report-incident-dialog v-model:open="reportIncidentOpen">
		<template #dialog_step_1>
			<report-incident-dialog-step1></report-incident-dialog-step1>
		</template>
		<template #dialog_step_report_unacceptable_behavior>
			<report-incident-dialog-types-of-behavior></report-incident-dialog-types-of-behavior>
		</template>
		<template #dialog_step_report_immediate_harm>
			<report-immediate-harm-step></report-immediate-harm-step>
		</template>
		<template #dialog_step_emergency_submit_success>
			<emergency-submit-success-step></emergency-submit-success-step>
		</template>
		<template #dialog_step_nonemergency_submit_success>
			<non-emergency-submit-success-step>
			</non-emergency-submit-success-step>
		</template>
	</report-incident-dialog>
</template>

<script>
const EmailAlertDialog = require( './EmailAlertDialog.vue' );
const ReportIncidentDialog = require( './ReportIncidentDialog.vue' );
const ReportIncidentDialogStep1 = require( './ReportIncidentDialogStep1.vue' );
const ReportIncidentDialogTypesOfBehavior = require( './ReportIncidentDialogTypesOfBehavior.vue' );
const ReportImmediateHarmStep = require( './ReportImmediateHarmStep.vue' );
const EmergencySubmitSuccessStep = require( './EmergencySubmitSuccessStep.vue' );
const NonEmergencySubmitSuccessStep = require( './NonEmergencySubmitSuccessStep.vue' );
const useFormStore = require( '../stores/Form.js' );
const useInstrument = require( '../composables/useInstrument.js' );
const { ref } = require( 'vue' );

// @vue/component
module.exports = exports = {
	name: 'App',
	compilerOptions: {
		whitespace: 'condense'
	},
	components: {
		EmailAlertDialog,
		ReportIncidentDialog,
		ReportIncidentDialogStep1,
		ReportImmediateHarmStep,
		ReportIncidentDialogTypesOfBehavior,
		EmergencySubmitSuccessStep,
		NonEmergencySubmitSuccessStep
	},
	setup() {
		const emailAlertOpen = ref( false );
		const reportIncidentOpen = ref( false );

		const store = useFormStore();
		const logEvent = useInstrument();

		/**
		 * Open the main dialog if user has confirmed email, otherwise show email alert dialog.
		 */
		function showDialogDependingOnEmailConfirmationStatus() {
			if ( mw.config.get( 'wgReportIncidentUserHasConfirmedEmail' ) ) {
				reportIncidentOpen.value = true;
				logEvent( 'view', { source: 'form' } );
			} else {
				emailAlertOpen.value = true;
			}
		}

		/**
		 * Handles clicks on the Report links in the Tools menu.
		 * This opens the dialog and clears any data set by a
		 * click on a DiscussionTools report link.
		 */
		function reportLinkInToolsMenuHandler() {
			// If DiscussionTools data was set, then reset the
			// form state as the user probably is intending
			// to report something else.
			if ( Object.keys( store.overflowMenuData ).length ) {
				store.$reset();
			}
			showDialogDependingOnEmailConfirmationStatus();
		}

		/**
		 * Calls the allusers API to check if a user
		 * exists with the given username. Returns
		 * a promise that will resolve to a boolean
		 * or reject if the response was invalid.
		 *
		 * @param {string} username
		 * @return {Promise.<boolean>}
		 */
		function checkUsernameExists( username ) {
			return new Promise( ( resolve, reject ) => {
				new mw.Api().get( {
					action: 'query',
					list: 'allusers',
					aufrom: username,
					auto: username,
					aulimit: '1'
				} ).then(
					( response ) => {
						if (
							!response ||
							!response.query ||
							!response.query.allusers ||
							!Array.isArray( response.query.allusers )
						) {
							return reject();
						}

						resolve( Boolean( response.query.allusers.length ) );
					},
					() => {
						reject();
					}
				);
			} );
		}

		/**
		 * Handles a fire of the discussionToolsOverflowMenuOnChoose JS hook.
		 * Arguments are those which are passed by the firing of the hook
		 * in DiscussionTools code.
		 *
		 * @param {string} id
		 * @param {Object} menuItem
		 * @param {Object} threadItem
		 */
		function discussionToolsOverflowMenuOnChooseHandler( id, menuItem, threadItem ) {
			if ( id === 'reportincident' ) {
				// Clear any existing data if:
				// * The previous data has no DiscussionTools menuItem data, or
				// * The previous data has a different thread-id.
				if (
					!Object.keys( store.overflowMenuData ).length ||
					!store.overflowMenuData[ 'thread-id' ] ||
					!menuItem.getData() ||
					!menuItem.getData()[ 'thread-id' ] ||
					menuItem.getData()[ 'thread-id' ] !== store.overflowMenuData[ 'thread-id' ]
				) {
					store.$reset();
				}
				if ( threadItem.author !== null ) {
					store.inputReportedUser = threadItem.author;
				} else {
					store.inputReportedUser = '';
				}
				store.overflowMenuData = menuItem.getData();
				showDialogDependingOnEmailConfirmationStatus();
				// Only set the reported user input as disabled if
				// the allusers API says this is a user or the
				// user is an IP address.
				if ( !store.inputReportedUser ) {
					store.inputReportedUserDisabled = false;
				} else if ( mw.util.isIPAddress( store.inputReportedUser ) ) {
					store.inputReportedUserDisabled = true;
				} else {
					checkUsernameExists( store.inputReportedUser ).then(
						( usernameExists ) => {
							store.inputReportedUserDisabled = usernameExists;
						},
						() => {}
					);
				}
			}
		}

		return {
			emailAlertOpen,
			reportIncidentOpen,
			checkUsernameExists,
			discussionToolsOverflowMenuOnChooseHandler,
			reportLinkInToolsMenuHandler
		};
	},
	expose: [
		// Expose internal functions called from tests in order
		// to prevent linter errors about unused properties
		'checkUsernameExists',
		'discussionToolsOverflowMenuOnChooseHandler',
		'reportLinkInToolsMenuHandler'
	]
};
</script>
