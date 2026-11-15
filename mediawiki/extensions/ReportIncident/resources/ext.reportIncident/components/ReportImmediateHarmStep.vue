<template>
	<form id="reportincident-form" class="ext-reportincident-dialog-step2">
		<cdx-message>
			<parsed-message
				class="ext-reportincident-dialog__message"
				:message="infoTextMsg">
			</parsed-message>
			<!-- eslint-enable -->
		</cdx-message>
		<!-- who is violating behavior guidelines -->
		<cdx-field
			class="ext-reportincident-dialog-step2__form-item
				ext-reportincident-dialog-step2__violator-name"
		>
			<template #label>
				{{ $i18n( 'reportincident-dialog-violator-label' ).text() }}
			</template>
			<template #description>
				{{ $i18n( 'reportincident-dialog-violator-disclaimer' ).text() }}
			</template>
			<cdx-lookup
				v-model:selected="inputReportedUserSelection"
				v-model="inputReportedUser"
				:disabled="inputReportedUserDisabled"
				:placeholder="$i18n( 'reportincident-dialog-violator-placeholder-text' ).text()"
				:menu-items="inputReportedUserMenuItems"
				:menu-config="reportedUserLookupMenuConfig"
				@input="onReportedUserInput"
			>
			</cdx-lookup>
		</cdx-field>

		<!-- Additional details -->
		<cdx-field
			:optional="true"
			:optional-flag="$i18n( 'reportincident-dialog-optional-label' ).text()"
			class="ext-reportincident-dialog-step2__form-item
							ext-reportincident-dialog-step2__additional-details">
			<template #label>
				{{ $i18n( 'reportincident-dialog-additional-details-input-label' ).text() }}
			</template>
			<character-limited-text-area
				v-model:text-content="inputDetails"
				:code-point-limit="additionalDetailsCodepointLimit"
				:placeholder="$i18n(
					'reportincident-dialog-additional-details-input-placeholder'
				).text()"
			>
			</character-limited-text-area>
		</cdx-field>
	</form>
</template>

<script>

const useFormStore = require( '../stores/Form.js' );
const useInstrument = require( '../composables/useInstrument.js' );
const { CdxField, CdxLookup, CdxMessage } = require( '@wikimedia/codex' );
const CharacterLimitedTextArea = require( './CharacterLimitedTextArea.vue' );
const ParsedMessage = require( './ParsedMessage.vue' );
const { storeToRefs } = require( 'pinia' );
const { computed, ref, onMounted, onUnmounted } = require( 'vue' );

// @vue/component
module.exports = exports = {
	name: 'ReportImmediateHarmStep',
	components: {
		CdxField,
		CdxLookup,
		CdxMessage,
		CharacterLimitedTextArea,
		ParsedMessage
	},
	setup() {
		const store = useFormStore();
		const logEvent = useInstrument();

		const {
			inputReportedUser,
			inputReportedUserDisabled,
			inputDetails
		} = storeToRefs( store );

		const infoTextMsg = mw.message( 'reportincident-physical-harm-infotext' );
		const inputReportedUserSelection = ref( '' );
		const windowHeight = ref( window.innerHeight );
		const suggestedUsernames = ref( [] );

		const additionalDetailsCodepointLimit = mw.config.get( 'wgReportIncidentDetailsCodePointLength' );

		let debounce = null;

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

		onMounted( () => {
			// Run onWindowResize on the "resize" event once the
			// second step is mounted.
			window.addEventListener( 'resize', onWindowResize );

			logEvent( 'view', { source: 'submit_report' } );
		} );

		// Stop calling the onWindowResize method when the component
		// is unmounted (after successful submission, closing the dialog,
		// or navigating back to the first step).
		onUnmounted( () => {
			window.removeEventListener( 'resize', onWindowResize );
		} );

		/**
		 * The menu items for the reported user Codex Lookup component.
		 */
		const inputReportedUserMenuItems =
			computed( () => suggestedUsernames.value.map( ( user ) => ( { value: user.name } ) ) );

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
				4
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

			// Load suggestions based on the input already entered.
			loadSuggestedUsernames();
		}

		return {
			infoTextMsg,
			inputDetails,
			inputReportedUser,
			inputReportedUserDisabled,
			inputReportedUserSelection,
			inputReportedUserMenuItems,
			reportedUserLookupMenuConfig,
			additionalDetailsCodepointLimit,
			windowHeight,
			suggestedUsernames,
			onReportedUserInput
		};
	},
	expose: [
		// Expose internal functions and variables used in tests in order
		// to prevent linter errors about unused properties
		'suggestedUsernames',
		'windowHeight'
	]
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
