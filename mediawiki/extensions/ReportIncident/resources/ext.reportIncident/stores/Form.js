'use strict';

const Pinia = require( 'pinia' );
const { ref, computed } = require( 'vue' );

const useFormStore = Pinia.defineStore( 'form', () => {
	const overflowMenuData = ref( {} );
	const inputBehaviors = ref( [ ] );
	const displayBehaviorsRequiredError = ref( false );
	const inputReportedUser = ref( '' );
	const inputReportedUserDisabled = ref( false );
	const displayReportedUserRequiredError = ref( false );
	const reportedUserDoesNotExist = ref( false );
	const inputDetails = ref( '' );
	const inputSomethingElseDetails = ref( '' );
	const displaySomethingElseTextboxRequiredError = ref( false );
	const formSuccessfullySubmitted = ref( false );

	/**
	 * A dictionary of error messages for display in step 2 of the dialog.
	 *
	 * This can be used for display by passing the value for the key that is the
	 * name of the reference (i.e. inputBehavior) to the Codex field via the
	 * 'messages' property.
	 */
	const formErrorMessages = computed( () => {
		// every form item must be filled out except for additional details, which is optional
		const formErrors = {};
		// Validate that the "Something else" box is filled if something-else
		// is selected as a behaviour.
		if (
			inputBehaviors.value.indexOf( 'something-else' ) !== -1 &&
			inputSomethingElseDetails.value === '' &&
			displaySomethingElseTextboxRequiredError.value
		) {
			displayBehaviorsRequiredError.value = true;
			formErrors.inputBehaviors = { error: mw.msg( 'reportincident-dialog-something-else-empty' ) };
		} else if (
			inputBehaviors.value.indexOf( 'something-else' ) !== -1 &&
			inputSomethingElseDetails.value !== ''
		) {
			displaySomethingElseTextboxRequiredError.value = true;
		}
		// Validate at least one of behaviours is selected.
		if ( inputBehaviors.value.length === 0 ) {
			if ( displayBehaviorsRequiredError.value ) {
				formErrors.inputBehaviors = { error: mw.msg( 'reportincident-dialog-harassment-empty' ) };
			}
		} else {
			// If text has been entered for this field, then now display errors for empty field.
			displayBehaviorsRequiredError.value = true;
		}
		// Validate the reported user field has some content.
		if ( inputReportedUser.value === '' ) {
			if ( displayReportedUserRequiredError.value ) {
				formErrors.inputReportedUser = { error: mw.msg( 'reportincident-dialog-violator-empty' ) };
			}
		} else {
			// If text has been entered for this field, then now display errors for empty field.
			displayReportedUserRequiredError.value = true;
			if ( reportedUserDoesNotExist.value ) {
				formErrors.inputReportedUser = { error: mw.msg( 'reportincident-dialog-violator-nonexistent' ) };
			}
		}

		return formErrors;
	} );

	/**
	 * Checks whether the form on step 2 of the dialog is ready
	 * for submission as defined by having no errors for the fields.
	 *
	 * Before the check is made, the required field checks are marked
	 * to be performed as they are skipped until a user interacts with
	 * the field or submits the form to prevent the required error messages
	 * being shown when the user first loads the form.
	 *
	 * @return {boolean} Whether the form data is valid for submission.
	 *   If false, formErrorMessages will contain why.
	 */
	function isFormValidForSubmission() {
		// Because this is called when checking if the form can be submitted after
		// the user pressed the submit button, update the form checks to check that
		// errors appear for empty items.
		if ( inputBehaviors.value.indexOf( 'something-else' ) !== -1 ) {
			displaySomethingElseTextboxRequiredError.value = true;
		}
		displayBehaviorsRequiredError.value = true;
		displayReportedUserRequiredError.value = true;
		// The form is valid if the formErrorMessages has no items.
		return Object.keys( formErrorMessages.value ).length === 0;
	}

	/**
	 * The form data from step 2 of the dialog in a JSON format that
	 * can be posted to the report incident REST endpoint.
	 */
	const restPayload = computed( () => {
		const restData = {
			reportedUser: inputReportedUser.value,
			details: inputDetails.value,
			behaviors: inputBehaviors.value
		};
		if ( inputBehaviors.value.indexOf( 'something-else' ) !== -1 ) {
			restData.somethingElseDetails = inputSomethingElseDetails.value;
		}
		if ( Object.keys( overflowMenuData.value ).indexOf( 'thread-id' ) !== -1 ) {
			restData.threadId = overflowMenuData.value[ 'thread-id' ];
		}
		return restData;
	} );

	/**
	 * Resets the form data to it's initial state, which
	 * is all text fields as an empty string and no
	 * checkboxes selected.
	 *
	 * This also configures the required field checks to
	 * be disabled until the field is un-focused or an
	 * attempt is submitted as is done for the first use
	 * of the form.
	 */
	function $reset() {
		// Reset the form data
		inputBehaviors.value = [ ];
		inputReportedUser.value = '';
		inputDetails.value = '';
		inputSomethingElseDetails.value = '';
		overflowMenuData.value = {};
		// Disable the required fields error again until
		// that required field is un-focused or a submit
		// is attempted.
		displayReportedUserRequiredError.value = false;
		displayBehaviorsRequiredError.value = false;
		displaySomethingElseTextboxRequiredError.value = false;
		// Re-enable the username field if it was disabled
		inputReportedUserDisabled.value = false;
		// The username is now empty, so the username not
		// existing error is no longer applicable.
		reportedUserDoesNotExist.value = false;
	}

	return {
		overflowMenuData,
		inputBehaviors,
		displayBehaviorsRequiredError,
		inputReportedUser,
		inputReportedUserDisabled,
		displayReportedUserRequiredError,
		reportedUserDoesNotExist,
		inputDetails,
		inputSomethingElseDetails,
		displaySomethingElseTextboxRequiredError,
		restPayload,
		formErrorMessages,
		formSuccessfullySubmitted,
		isFormValidForSubmission,
		$reset
	};
} );

module.exports = useFormStore;
