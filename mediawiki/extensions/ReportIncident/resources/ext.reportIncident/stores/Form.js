'use strict';

const Pinia = require( 'pinia' );
const Constants = require( '../Constants.js' );
const { ref, computed, watch } = require( 'vue' );

const useFormStore = Pinia.defineStore( 'form', () => {
	const overflowMenuData = ref( {} );
	const incidentType = ref( '' );
	const physicalHarmType = ref( '' );
	const inputBehavior = ref( '' );
	const displayBehaviorsRequiredError = ref( false );
	const inputReportedUser = ref( '' );
	const inputReportedUserDisabled = ref( false );
	const inputDetails = ref( '' );
	const inputSomethingElseDetails = ref( '' );
	const displaySomethingElseDetailsEmptyError = ref( false );
	const displaySomethingElseTextboxRequiredError = ref( false );
	const showValidationError = ref( false );
	const missingBehaviorSelection = ref( false );
	const funnelEntryToken = ref( '' );
	const funnelName = ref( '' );

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
			inputBehavior.value === Constants.harassmentTypes.SOMETHING_ELSE &&
			inputSomethingElseDetails.value === '' &&
			displaySomethingElseTextboxRequiredError.value
		) {
			displayBehaviorsRequiredError.value = true;
			formErrors.inputBehaviors = { error: mw.msg( 'reportincident-dialog-something-else-empty' ) };
		} else if (
			inputBehavior.value === Constants.harassmentTypes.SOMETHING_ELSE &&
			inputSomethingElseDetails.value !== ''
		) {
			displaySomethingElseTextboxRequiredError.value = true;
		}

		if ( displaySomethingElseDetailsEmptyError.value &&
			!areSomethingElseDetailsProvided() ) {
			formErrors.inputBehaviors = {
				error: mw.msg( 'reportincident-dialog-something-else-empty' )
			};
		}

		if ( showValidationError.value && missingBehaviorSelection.value ) {
			formErrors.inputBehaviors = {
				error: mw.msg( 'reportincident-behavior-required' )
			};
		}

		return formErrors;
	} );

	const harassmentOptions = ( mw.config.get( 'wgReportIncidentEnabledNonEmergencyCategories' ) || [] )
		.reduce( ( categories, category ) => {
			const categoryDefinition = Constants.harassmentTypesV2[ category ];
			if ( categoryDefinition ) {
				categories.push( {
					// * reportincident-dialog-harassment-type-intimidation
					// * reportincident-dialog-harassment-type-sexual-harassment
					// * reportincident-dialog-harassment-type-doxing
					// * reportincident-dialog-harassment-type-trolling
					// * reportincident-dialog-harassment-type-hate-speech-or-discrimination
					// * reportincident-dialog-harassment-type-spam
					// * reportincident-dialog-harassment-type-something-else
					// * reportincident-dialog-harassment-type-sockpuppetry
					// * reportincident-dialog-harassment-type-vandalism
					// * reportincident-dialog-harassment-type-userdispute
					// * reportincident-dialog-harassment-type-other
					label: mw.msg( categoryDefinition.labelKey ),
					value: categoryDefinition.id
				} );
			}

			return categories;
		}, [] );

	const contextsByHarmType = {
		[ Constants.physicalHarmTypes.physicalHarm ]: 'physical',
		[ Constants.physicalHarmTypes.selfHarm ]: 'self',
		[ Constants.physicalHarmTypes.publicHarm ]: 'public'
	};

	/**
	 * Getter for the interaction data context associated with the current physical harm type.
	 */
	const physicalHarmTypeContext = computed( () => {
		if ( incidentType.value === Constants.typeOfIncident.immediateThreatPhysicalHarm ) {
			return contextsByHarmType[ physicalHarmType.value ] || 'na';
		}

		return 'na';
	} );

	watch( inputSomethingElseDetails, ( newSomethingElseDetails ) => {
		displaySomethingElseDetailsEmptyError.value = false;

		if ( isUnacceptableBehavior() && isSomethingElse() ) {
			if ( !areSomethingElseDetailsProvided( newSomethingElseDetails ) ) {
				displaySomethingElseDetailsEmptyError.value = true;
			}
		}
	} );

	// eslint-disable-next-line no-unused-vars
	watch( inputBehavior, ( _newInputBehavior ) => {
		// If the user has just changed the type of unacceptable behavior, reset
		// the error: If the details are left empty, they will be validated when
		// moving forward to the next screen by isFormValidForSubmission().
		displaySomethingElseDetailsEmptyError.value = false;
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
		displaySomethingElseTextboxRequiredError.value = isSomethingElse() &&
			isUnacceptableBehavior() &&
			!areSomethingElseDetailsProvided();

		if ( displaySomethingElseTextboxRequiredError.value ) {
			return false;
		}

		displayBehaviorsRequiredError.value = true;

		// The form is valid if the formErrorMessages has no items.
		return Object.keys( formErrorMessages.value ).length === 0;
	}

	/**
	 * Check if an incident type is selected.
	 *
	 * @return {boolean} If an incident type is selected (and for immediate
	 * threat of physical harm, if a subtype is selected)
	 */
	function isIncidentTypeSelected() {
		return incidentType.value.length > 0;
	}

	/**
	 * Check if the user selected one of the "physical harm" incident type, i.e.
	 * if the report is an emergency.
	 *
	 * @return {boolean}
	 */
	function isEmergency() {
		return incidentType.value !== Constants.typeOfIncident.unacceptableUserBehavior;
	}

	/**
	 * Check if the user selected "physical harm" as the incident type, but
	 * did not yet select a subtype.
	 *
	 * @return {boolean}
	 */
	function isPhysicalHarmSelectedButNoSubtypeSelected() {
		return incidentType.value === Constants.typeOfIncident.immediateThreatPhysicalHarm &&
			physicalHarmType.value.length === 0;
	}

	/**
	 * Check if the user selected "unacceptable behavior" as the incident type.
	 *
	 * @return {boolean}
	 */
	function isUnacceptableBehavior() {
		return incidentType.value === Constants.typeOfIncident.unacceptableUserBehavior;
	}

	/**
	 * Check if the user selected "something else" as the type of unacceptable
	 * behavior.
	 *
	 * @return {boolean}
	 */
	function isSomethingElse() {
		return inputBehavior.value === Constants.harassmentTypes.SOMETHING_ELSE;
	}

	/**
	 * Check if the user provided additional details in the textbox associated
	 * with the "something else" behavior type.
	 *
	 * @param {string|undefined} value
	 * @return {boolean}
	 */
	function areSomethingElseDetailsProvided( value = undefined ) {
		if ( typeof value === 'undefined' ) {
			value = inputSomethingElseDetails.value;
		}

		return value.trim().length > 0;
	}

	/**
	 * Check whether a behavior is selected in the list of
	 * unacceptable behaviors types.
	 *
	 * @return {boolean}
	 */
	function noBehaviorIsSelected() {
		return inputBehavior.value.length === 0;
	}

	/**
	 * The form data from step 2 of the dialog in a JSON format that
	 * can be posted to the report incident REST endpoint.
	 */
	const restPayload = computed( () => {
		const restData = {
			reportedUser: inputReportedUser.value,
			incidentType: incidentType.value
		};

		if ( Object.keys( overflowMenuData.value ).includes( 'thread-id' ) ) {
			restData.threadId = overflowMenuData.value[ 'thread-id' ];
		}

		if ( isEmergency() ) {
			return Object.assign( restData, {
				details: inputDetails.value,
				physicalHarmType: physicalHarmType.value
			} );
		}

		if ( isSomethingElse() ) {
			return Object.assign( restData, {
				somethingElseDetails: inputSomethingElseDetails.value,
				behaviorType: inputBehavior.value
			} );
		}

		return Object.assign( restData, { behaviorType: inputBehavior.value } );
	} );

	/**
	 * Resets the form data to its initial state, which
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
		incidentType.value = '';
		physicalHarmType.value = '';
		inputBehavior.value = '';
		inputReportedUser.value = '';
		inputDetails.value = '';
		inputSomethingElseDetails.value = '';
		overflowMenuData.value = {};
		showValidationError.value = false;
		// Disable the required fields error again until
		// that required field is un-focused or a submit
		// is attempted.
		displayBehaviorsRequiredError.value = false;
		displaySomethingElseTextboxRequiredError.value = false;
		displaySomethingElseDetailsEmptyError.value = false;
		// Re-enable the username field if it was disabled
		inputReportedUserDisabled.value = false;

		funnelEntryToken.value = '';
		funnelName.value = '';
	}

	return {
		harassmentOptions,
		overflowMenuData,
		incidentType,
		physicalHarmType,
		inputBehavior,
		displayBehaviorsRequiredError,
		inputReportedUser,
		inputReportedUserDisabled,
		inputDetails,
		inputSomethingElseDetails,
		displaySomethingElseTextboxRequiredError,
		displaySomethingElseDetailsEmptyError,
		restPayload,
		formErrorMessages,
		showValidationError,
		noBehaviorIsSelected,
		missingBehaviorSelection,
		isIncidentTypeSelected,
		isPhysicalHarmSelectedButNoSubtypeSelected,
		isUnacceptableBehavior,
		isSomethingElse,
		areSomethingElseDetailsProvided,
		isFormValidForSubmission,
		funnelName,
		funnelEntryToken,
		physicalHarmTypeContext,
		$reset
	};
} );

module.exports = useFormStore;
