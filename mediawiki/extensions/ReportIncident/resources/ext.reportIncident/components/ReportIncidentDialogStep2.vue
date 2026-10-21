<template>
	<form id="reportincident-form" class="ext-reportincident-dialog-step2">
		<!-- type of harassment -->
		<cdx-field
			:is-fieldset="true"
			:status="harassmentStatus"
			:messages="formErrorMessages.inputBehaviors"
			class="ext-reportincident-dialog-step2__harassment-options">
			<template #label>
				{{ $i18n( 'reportincident-dialog-harassment-type-label' ).text() }}
			</template>
			<cdx-checkbox
				v-for="checkbox in harassmentOptions"
				:id="'ext-reportincident-dialog-option__' + checkbox.value"
				:key="'checkbox-' + checkbox.value"
				v-model="inputBehaviors"
				:input-value="checkbox.value">
				{{ checkbox.label }}
			</cdx-checkbox>
			<cdx-text-area
				v-if="collectSomethingElseDetails"
				ref="somethingElseDetailsField"
				v-model="inputSomethingElseDetails"
				class="ext-reportincident-dialog-step2__something-else-textarea"
				:placeholder="$i18n(
					'reportincident-dialog-something-else-input-placeholder'
				).text()"
				@focusout="displaySomethingElseTextboxRequiredError = true"
				@input="onSomethingElseDetailsInput"
			></cdx-text-area>
			<template v-if="showSomethingElseCharacterCount" #help-text>
				{{ somethingElseDetailsCharacterCountLeft }}
			</template>
		</cdx-field>

		<!-- who is violating behavior guidelines -->
		<cdx-field
			class="ext-reportincident-dialog-step2__form-item
							ext-reportincident-dialog-step2__violator-name"
			:status="reportedUserStatus"
			:messages="formErrorMessages.inputReportedUser"
			@focusout="displayReportedUserRequiredError = true"
		>
			<template #label>
				{{ $i18n( 'reportincident-dialog-violator-label' ).text() }}
			</template>
			<cdx-lookup
				v-model:selected="inputReportedUserSelection"
				v-model="inputReportedUser"
				:disabled="inputReportedUserDisabled"
				:placeholder="$i18n( 'reportincident-dialog-violator-placeholder-text' ).text()"
				:menu-items="inputReportedUserMenuItems"
				:menu-config="reportedUserLookupMenuConfig"
				@input="onReportedUserInput"
				@update:selected="displayReportedUserRequiredError = false"
			>
			</cdx-lookup>
		</cdx-field>

		<!-- Additional details -->
		<cdx-field
			:optional-flag="$i18n( 'reportincident-dialog-optional-label' ).text()"
			class="ext-reportincident-dialog-step2__form-item
							ext-reportincident-dialog-step2__additional-details">
			<template #label>
				{{ $i18n( 'reportincident-dialog-additional-details-input-label' ).text() }}
			</template>
			<cdx-text-area
				ref="additionalDetailsField"
				v-model="inputDetails"
				:placeholder="$i18n(
					'reportincident-dialog-additional-details-input-placeholder'
				).text()"
				@input="onAdditionalDetailsInput"
			>
			</cdx-text-area>
			<template v-if="showAdditionalDetailsCharacterCount" #help-text>
				{{ additionalDetailsCharacterCountLeft }}
			</template>
		</cdx-field>
	</form>
</template>

<script>

const Constants = require( '../Constants.js' );
const useFormStore = require( '../stores/Form.js' );
const { CdxCheckbox, CdxField, CdxLookup, CdxTextArea } = require( '@wikimedia/codex' );
const { storeToRefs } = require( 'pinia' );
const { computed, ref, onMounted, onUnmounted, watch, nextTick } = require( 'vue' );
const { codePointLength } = require( 'mediawiki.String' );

// @vue/component
module.exports = exports = {
	name: 'ReportIncidentDialogStep2',
	components: {
		CdxCheckbox,
		CdxField,
		CdxLookup,
		CdxTextArea
	},
	setup() {
		const store = useFormStore();

		const {
			inputBehaviors,
			inputReportedUser,
			inputReportedUserDisabled,
			displayReportedUserRequiredError,
			reportedUserDoesNotExist,
			inputSomethingElseDetails,
			displaySomethingElseTextboxRequiredError,
			inputDetails
		} = storeToRefs( store );

		const inputReportedUserSelection = ref( '' );
		const windowHeight = ref( window.innerHeight );
		const suggestedUsernames = ref( [] );

		const additionalDetailsCharacterCountLeft = ref( '' );
		const somethingElseDetailsCharacterCountLeft = ref( '' );
		const additionalDetailsField = ref( null );
		const somethingElseDetailsField = ref( null );

		const codePointLimit = mw.config.get( 'wgCommentCodePointLimit' );

		let debounce = null;

		/**
		 * Whether the "Something else" textbox value should be sent
		 * in the request body to the REST endpoint on form submission.
		 */
		const collectSomethingElseDetails = computed( () => inputBehaviors.value.filter(
			( input ) => input === Constants.harassmentTypes.OTHER
		).length > 0 );

		/**
		 * Called when the browser window is resized.
		 *
		 * This function updates the reference containing
		 * the current height of the window to adjust the
		 * number of menu items shown.
		 */
		function onWindowResize() {
			windowHeight.value = window.innerHeight;
		}

		/**
		 * Applies the code point limit to the field given via a
		 * ref to the codex textarea component.
		 *
		 * @param {Object} fieldRef A ref to the codex textarea component
		 */
		function applyCodePointLimitToField( fieldRef ) {
			const $textarea = $( fieldRef.value.textarea );
			$textarea.codePointLimit( codePointLimit );
			$textarea.on( 'change', () => {
				// Needed because Vue cannot listen to JQuery events, so
				// a native JS input event is needed to cause an update.
				$textarea[ 0 ].dispatchEvent( new CustomEvent( 'input' ) );
			} );
		}

		onMounted( () => {
			// Enforce the code point limit on the additional details field.
			applyCodePointLimitToField( additionalDetailsField );
			// If the Something else textbox is already shown, then apply
			// the code point limit to it. Otherwise, watch for it being
			// created to add the code point limit.
			if ( collectSomethingElseDetails.value ) {
				applyCodePointLimitToField( somethingElseDetailsField );
			} else {
				watch( collectSomethingElseDetails, ( newValue, oldValue ) => {
					if ( oldValue !== newValue && newValue ) {
						nextTick( () => {
							// Once the next tick has occurred, the Something else details textbox
							// has been shown and enforcement of the character limit can be added.
							applyCodePointLimitToField( somethingElseDetailsField );
						} );
					}
				} );
			}
			// Run onWindowResize on the "resize" event once the
			// second step is mounted.
			window.addEventListener( 'resize', onWindowResize );
		} );

		// Stop calling the onWindowResize method when the component
		// is unmounted (after successful submission, closing the dialog,
		// or navigating back to the first step).
		onUnmounted( () => {
			window.removeEventListener( 'resize', onWindowResize );
		} );

		const harassmentOptions = [
			{
				label: mw.msg( 'reportincident-dialog-harassment-type-hate-speech-or-discrimination' ),
				value: Constants.harassmentTypes.HATE_SPEECH
			},
			{
				label: mw.msg( 'reportincident-dialog-harassment-type-sexual-harassment' ),
				value: Constants.harassmentTypes.SEXUAL_HARASSMENT
			},
			{
				label: mw.msg( 'reportincident-dialog-harassment-type-threats-of-violence' ),
				value: Constants.harassmentTypes.THREATS_OR_VIOLENCE
			},
			{
				label: mw.msg( 'reportincident-dialog-harassment-type-intimidation' ),
				value: Constants.harassmentTypes.INTIMIDATION_AGGRESSION
			},
			{
				label: mw.msg( 'reportincident-dialog-harassment-type-something-else' ),
				value: Constants.harassmentTypes.OTHER
			}
		];

		const harassmentStatus = computed( () => store.formErrorMessages.inputBehaviors ? 'error' : 'default' );

		const reportedUserStatus = computed( () => store.formErrorMessages.inputReportedUser ? 'error' : 'default' );

		const formErrorMessages = computed( () => store.formErrorMessages );

		/**
		 * The menu items for the reported user Codex Lookup component.
		 */
		const inputReportedUserMenuItems =
			computed( () => suggestedUsernames.value.map( ( user ) => ( { value: user.name } ) ) );

		const showSomethingElseCharacterCount = computed( () => somethingElseDetailsCharacterCountLeft.value !== '' && collectSomethingElseDetails.value );

		const showAdditionalDetailsCharacterCount = computed( () => additionalDetailsCharacterCountLeft.value !== '' );

		/**
		 * The configuration settings for the Codex Lookup reported username
		 * component.
		 *
		 * This sets the visibleItemLimit to a proportion of the height such
		 * that the dropdown menu should not overflow the bottom of the dialog.
		 */
		const reportedUserLookupMenuConfig = computed( () => ( {
			visibleItemLimit: Math.min(
				Math.max(
					Math.floor( windowHeight.value / 150 ),
					2
				),
				5
			)
		} ) );

		/**
		 * Load username suggestions for the username lookup component
		 * using the 'allusers' query API. The results are set as the
		 * suggestedUsernames reference for further use.
		 *
		 * Calling this method repeatedly is safe as the API call is
		 * debounced using a 100ms delay.
		 */
		function loadSuggestedUsernames() {
			// Clear any other yet to be run API calls to get the suggested usernames.
			clearTimeout( debounce );
			const currentValue = inputReportedUser.value;
			// Do nothing if we have no input.
			if ( !currentValue ) {
				suggestedUsernames.value = [];
				return;
			}

			// Debounce the API calls using a 100ms delay.
			debounce = setTimeout( () => {
				new mw.Api().get( {
					action: 'query',
					list: 'allusers',
					auprefix: currentValue,
					limit: '10'
				} ).then( ( data ) => {
					// If the current value of inputReportedUser
					// has been changed since the API call was first
					// made then ignore this response.
					if ( inputReportedUser.value !== currentValue ) {
						return;
					}

					// If the API errors or returns an unexpected structure,
					// then just display no suggestions.
					if (
						!data ||
						!data.query ||
						!data.query.allusers ||
						!Array.isArray( data.query.allusers ) ||
						data.query.allusers.length === 0
					) {
						suggestedUsernames.value = [];
						return;
					}

					suggestedUsernames.value = data.query.allusers;
				} ).catch( ( error ) => {
					suggestedUsernames.value = [];
					mw.log.error( error );
				} );
			}, 100 );
		}

		/**
		 * Called when the reported username component has an "input" event fired.
		 * This function updates the form state and asks for username suggestions
		 * for the lookup dropdown.
		 *
		 * @param {string} value The new value of the reported username component.
		 */
		function onReportedUserInput( value ) {
			// Keep a track of the actual text in the input for the form store.
			inputReportedUser.value = value;
			// A change to the reported user input means that if the
			// server said the reported user doesn't exist it no
			// longer applies.
			reportedUserDoesNotExist.value = false;
			// Load suggestions based on the input already entered.
			loadSuggestedUsernames();
		}

		/**
		 * Updates the count in the provided ref that stores how many characters
		 * left there are for a given field.
		 *
		 * @param {string} value The current value of the field
		 * @param {Object} counterRef The ref that stores the characters left count for the field
		 */
		function updateCharacterCount( value, counterRef ) {
			let remaining;
			remaining = codePointLimit - codePointLength( value );
			if ( remaining > 99 ) {
				remaining = '';
			} else {
				remaining = mw.language.convertNumber( remaining );
			}
			counterRef.value = remaining;
		}

		/**
		 * Called when the Something else details textbox has the "input" event.
		 * This function calls updateCharacterCount to update the character count
		 * shown below the Something else details field.
		 *
		 * @param {Event} event
		 */
		function onSomethingElseDetailsInput( event ) {
			updateCharacterCount( event.target.value, somethingElseDetailsCharacterCountLeft );
		}

		/**
		 * Called when the Additional details textbox has the "input" event.
		 * This function calls updateCharacterCount to update the character count
		 * shown below the Additional details field.
		 *
		 * @param {Event} event
		 */
		function onAdditionalDetailsInput( event ) {
			updateCharacterCount( event.target.value, additionalDetailsCharacterCountLeft );
		}

		return {
			harassmentOptions,
			inputBehaviors,
			inputDetails,
			inputSomethingElseDetails,
			inputReportedUser,
			inputReportedUserDisabled,
			inputReportedUserSelection,
			inputReportedUserMenuItems,
			reportedUserLookupMenuConfig,
			displaySomethingElseTextboxRequiredError,
			displayReportedUserRequiredError,
			collectSomethingElseDetails,
			formErrorMessages,
			harassmentStatus,
			reportedUserStatus,
			showAdditionalDetailsCharacterCount,
			additionalDetailsCharacterCountLeft,
			showSomethingElseCharacterCount,
			somethingElseDetailsCharacterCountLeft,
			additionalDetailsField,
			somethingElseDetailsField,
			onSomethingElseDetailsInput,
			onAdditionalDetailsInput,
			onReportedUserInput,
			// Used in tests, so needs to be passed out here.
			/* eslint-disable vue/no-unused-properties */
			windowHeight,
			suggestedUsernames,
			updateCharacterCount
			/* eslint-enable vue/no-unused-properties */
		};
	}
};
</script>

<style lang="less">
@import ( reference ) 'mediawiki.skin.variables.less';

.ext-reportincident-dialog-step2 {
	&__form-item {
		margin-top: @spacing-125;
		margin-bottom: @spacing-125;
	}
}
</style>
