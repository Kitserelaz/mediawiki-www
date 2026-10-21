<template>
	<email-alert-dialog v-model:open="emailAlertOpen">
	</email-alert-dialog>
	<report-incident-dialog v-model:open="open">
		<template #dialog_step_1>
			<report-incident-dialog-step-1></report-incident-dialog-step-1>
		</template>
		<template #dialog_step_2>
			<report-incident-dialog-step-2></report-incident-dialog-step-2>
		</template>
	</report-incident-dialog>
</template>

<script>
const { ref, onMounted } = require( 'vue' );
const EmailAlertDialog = require( './EmailAlertDialog.vue' );
const ReportIncidentDialog = require( './ReportIncidentDialog.vue' );
const ReportIncidentDialogStep1 = require( './ReportIncidentDialogStep1.vue' );
const ReportIncidentDialogStep2 = require( './ReportIncidentDialogStep2.vue' );
const useFormStore = require( '../stores/Form.js' );

// @vue/component
module.exports = exports = {
	name: 'App',
	compatConfig: {
		MODE: 3
	},
	compilerOptions: {
		whitespace: 'condense'
	},
	components: {
		'email-alert-dialog': EmailAlertDialog,
		'report-incident-dialog': ReportIncidentDialog,
		'report-incident-dialog-step-1': ReportIncidentDialogStep1,
		'report-incident-dialog-step-2': ReportIncidentDialogStep2
	},
	setup() {

		const emailAlertOpen = ref( false );
		const open = ref( false );

		const store = useFormStore();

		/**
		 * Open the main dialog if user has confirmed email, otherwise show email alert dialog.
		 */
		function showDialogDependingOnEmailConfirmationStatus() {
			// Clear the successful submission banner, as a new report is being made now.
			store.formSuccessfullySubmitted = false;
			if ( mw.config.get( 'wgReportIncidentUserHasConfirmedEmail' ) ) {
				open.value = true;
			} else {
				emailAlertOpen.value = true;
			}
		}

		/**
		 * Handles clicks on the Report links in the Tools menu.
		 * This opens the dialog and clears any data set by a
		 * click on a DiscussionTools report link.
		 *
		 * @param {Event} event
		 */
		function reportLinkInToolsMenuHandler( event ) {
			event.preventDefault();
			// If DiscussionTools data was set, then reset the
			// form state as the user probably is intending
			// to report something else.
			if ( Object.keys( store.overflowMenuData ).length ) {
				store.$reset();
			}
			showDialogDependingOnEmailConfirmationStatus();
		}

		// Open the dialog if the link is clicked on.
		// eslint-disable-next-line no-jquery/no-global-selector
		$( '.ext-reportincident-link' ).on( 'click', reportLinkInToolsMenuHandler );

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
					store.displayReportedUserRequiredError = false;
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

		onMounted( () => {
			mw.hook( 'discussionToolsOverflowMenuOnChoose' ).add( discussionToolsOverflowMenuOnChooseHandler );
		} );
		return {
			open,
			emailAlertOpen,
			// Used in tests, so needs to be passed out here.
			/* eslint-disable vue/no-unused-properties */
			checkUsernameExists,
			discussionToolsOverflowMenuOnChooseHandler,
			reportLinkInToolsMenuHandler
			/* eslint-enable vue/no-unused-properties */
		};
	}
};
</script>
