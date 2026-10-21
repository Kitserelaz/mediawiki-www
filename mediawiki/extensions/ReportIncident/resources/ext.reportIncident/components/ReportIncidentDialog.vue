<template>
	<cdx-dialog
		v-model:open="wrappedOpen"
		:title="$i18n( 'reportincident-dialog-title' ).text()"
		:close-button-label="$i18n( 'reportincident-dialog-close-btn' ).text()"
		class="ext-reportincident-dialog"
	>
		<!-- dialog content-->
		<div class="ext-reportincident-dialog__content">
			<slot :name="currentSlotName"></slot>
		</div>

		<!-- dialog footer -->
		<template #footer>
			<p
				v-if="showFooterHelpText"
				v-i18n-html:reportincident-dialog-admin-review="[ adminLink ]"
				class="ext-reportincident-dialog__text-subtext">
			</p>
			<cdx-message
				v-if="showFooterErrorText"
				type="error"
				inline
				class="ext-reportincident-dialog__form-error-text">
				{{ footerErrorMessage }}
			</cdx-message>
			<div class="ext-reportincident-dialog-footer">
				<cdx-button
					class="ext-reportincident-dialog-footer__back-btn"
					:disabled="formSubmissionInProgress || null"
					@click="navigatePrevious"
				>
					{{ defaultButtonLabel }}
				</cdx-button>
				<cdx-button
					class="ext-reportincident-dialog-footer__next-btn"
					weight="primary"
					action="progressive"
					:disabled="formSubmissionInProgress || null"
					@click="navigateNext"
				>
					{{ primaryButtonLabel }}
				</cdx-button>
			</div>
		</template>
	</cdx-dialog>
</template>

<script>

const useFormStore = require( '../stores/Form.js' );
const { toRef, ref, computed } = require( 'vue' );
const { CdxButton, CdxDialog, CdxMessage, useModelWrapper } = require( '@wikimedia/codex' );
const Constants = require( '../Constants.js' );

// @vue/component
module.exports = exports = {
	name: 'ReportIncidentDialog',

	components: {
		CdxButton,
		CdxDialog,
		CdxMessage
	},

	props: {
		initialStep: {
			type: String,
			default: Constants.DIALOG_STEP_1
		}
	},

	emits: [ 'update:open' ],

	setup( props, { emit } ) {
		const wrappedOpen = useModelWrapper( toRef( props, 'open' ), emit, 'update:open' );
		const currentStep = ref( props.initialStep );
		const footerErrorMessage = ref( '' );
		const formSubmissionInProgress = ref( false );

		const currentSlotName = computed( () => `${ currentStep.value }` );
		const showFooterHelpText = computed( () => currentStep.value === Constants.DIALOG_STEP_1 );
		const showFooterErrorText = computed( () => currentStep.value === Constants.DIALOG_STEP_2 && footerErrorMessage.value !== '' );

		const primaryButtonLabel = computed( () => currentStep.value === Constants.DIALOG_STEP_1 ?
			mw.msg( 'reportincident-dialog-proceed-btn' ) :
			mw.msg( 'reportincident-dialog-submit-btn' ) );

		const defaultButtonLabel = computed( () => currentStep.value === Constants.DIALOG_STEP_1 ?
			mw.msg( 'reportincident-dialog-first-step-cancel-btn' ) :
			mw.msg( 'reportincident-dialog-back-btn' ) );

		/**
		 * Prints the email that was sent or failed to send
		 * at the stage of calling IEmailer::send. This is
		 * only returned by the server when in developer
		 * mode, so this should not cause spam in production.
		 *
		 * @param {Object} response
		 */
		function printEmailToConsole( response ) {
			if ( response && response.sentEmail ) {
				// Display the email sent to the administrators if in
				// developer mode.
				/* eslint-disable no-console */
				console.log( 'An email has been sent for this report' );
				console.log( 'Sent from:\n' + response.sentEmail.from.address );
				console.log( 'Sent to:\n' + response.sentEmail.to.map( ( item ) => item.address ).join( ', ' ) );
				console.log( 'Subject of the email:\n' + response.sentEmail.subject );
				console.log( 'Body of the email:\n' + response.sentEmail.body );
				/* eslint-enable no-console */
			}
		}

		/**
		 * Function called when the POST request to the
		 * ReportIncident reporting REST API succeeds.
		 *
		 * @param {Object} response
		 */
		function onReportSubmitSuccess( response ) {
			const store = useFormStore();
			printEmailToConsole( response );
			wrappedOpen.value = false;
			currentStep.value = Constants.DIALOG_STEP_1;
			store.$reset();
			store.formSuccessfullySubmitted = true;
			formSubmissionInProgress.value = false;
		}

		/**
		 * Function called when the POST request to the
		 * ReportIncident reporting REST API fails.
		 *
		 * @param {string} _err
		 * @param {Object} errObject
		 */
		function onReportSubmitFailure( _err, errObject ) {
			const store = useFormStore();
			let errorKey = null;
			if (
				errObject.xhr.responseJSON
			) {
				printEmailToConsole( errObject.xhr.responseJSON );
				if ( errObject.xhr.responseJSON.errorKey ) {
					errorKey = errObject.xhr.responseJSON.errorKey;
				}
			}
			if ( errorKey === 'reportincident-dialog-violator-nonexistent' ) {
				// Show the server error next to the correct field.
				store.reportedUserDoesNotExist = true;
				// Remove any existing footer error message as a field
				// specific one exists.
				footerErrorMessage.value = '';
				// Re-enable the field if is disabled as the server has said
				// the user does not exist, so it will need to be fixed.
				store.inputReportedUserDisabled = false;
			} else {
				let message;
				if ( !navigator.onLine ) {
					// If the navigator.onLine is false, the user is definitely
					// offline so display the internet disconnected error. The user
					// may still be offline if this property is true and in this
					// case the generic error will be shown.
					message = mw.msg( 'reportincident-dialog-internet-disconnected-error' );
				} else if (
					errObject.xhr.status >= 500 &&
					errObject.xhr.status < 600
				) {
					// If the HTTP status code starts with 5, then this is a
					// server error and the footer error message should indicate
					// it is the server that was the problem.
					message = mw.msg( 'reportincident-dialog-server-error' );
				} else {
					// Otherwise use the generic error.
					message = mw.msg( 'reportincident-dialog-generic-error' );
				}
				footerErrorMessage.value = message;
			}
			formSubmissionInProgress.value = false;
		}

		function navigateNext() {
			// if on the first page, navigate to the second page
			if ( currentStep.value === Constants.DIALOG_STEP_1 ) {
				currentStep.value = Constants.DIALOG_STEP_2;
			} else {
				// if on the second page, validate, then POST the data
				const store = useFormStore();
				const restPayload = store.restPayload;
				restPayload.revisionId = mw.config.get( 'wgCurRevisionId' );
				// TODO: Simulate mw.Api.postWithToken() by re-trying if the REST API call fails
				// because the CSRF token does not match.
				restPayload.token = mw.user.tokens.get( 'csrfToken' );
				if ( store.isFormValidForSubmission() ) {
					formSubmissionInProgress.value = true;
					new mw.Rest().post(
						'/reportincident/v0/report',
						restPayload
					).then( onReportSubmitSuccess, onReportSubmitFailure );
				} else {
					// Clear footer error messages as the form-specific ones will be set.
					footerErrorMessage.value = '';
				}
			}
		}

		function navigatePrevious() {
			if ( currentStep.value === Constants.DIALOG_STEP_1 ) {
				// if on the first page, close the dialog
				wrappedOpen.value = false;
				// Also clear any form data, as the user has had to
				// navigate back from the second page to the first to
				// cancel which suggests they don't want to submit this
				// report.
				const store = useFormStore();
				store.$reset();
			} else {
				// if on the second page, navigate back to the first page
				currentStep.value = Constants.DIALOG_STEP_1;
			}
		}

		const adminLink = mw.util.getUrl( mw.config.get( 'wgReportIncidentAdministratorsPage' ) );

		return {
			wrappedOpen,
			primaryButtonLabel,
			defaultButtonLabel,
			currentSlotName,
			navigateNext,
			navigatePrevious,
			adminLink,
			footerErrorMessage,
			showFooterHelpText,
			showFooterErrorText,
			formSubmissionInProgress,
			// Used in tests, so needs to be passed out here.
			/* eslint-disable vue/no-unused-properties */
			onReportSubmitFailure
			/* eslint-enable vue/no-unused-properties */
		};
	}
};
</script>

<style lang="less">
@import ( reference ) 'mediawiki.skin.variables.less';

.ext-reportincident-dialog {
	.ext-reportincident-dialog-footer {
		float: right;
	}

	@media screen and ( max-width: @max-width-breakpoint-mobile ) {
		.ext-reportincident-dialog-footer {
			display: flex;
			flex-direction: column-reverse;
			width: 100%;

			&__back-btn {
				width: 100%;
				margin-top: @spacing-35;
			}

			&__next-btn {
				width: 100%;
				margin-top: @spacing-35;
			}
		}
	}
}
</style>
