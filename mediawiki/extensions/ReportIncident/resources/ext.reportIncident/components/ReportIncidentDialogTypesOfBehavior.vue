<template>
	<form id="reportincident-form" class="ext-reportincident-dialog-types-of-behavior">
		<cdx-message>
			<parsed-message
				class="ext-reportincident-dialog__message"
				:message="noticeMsg"
			></parsed-message>
		</cdx-message>

		<!-- type of unacceptable behavior -->
		<cdx-field
			:is-fieldset="true"
			:status="harassmentStatus"
			:messages="formErrorMessages.inputBehaviors"
			class="ext-reportincident-dialog-types-of-behavior__harassment-options">
			<template #label>
				{{ $i18n( 'reportincident-dialog-harassment-type-label' ).text() }}
			</template>
			<cdx-radio
				v-for="radio in harassmentOptions"
				:key="'radio-' + radio.value"
				v-model="inputBehavior"
				name="reportincident-behavior-type-radio-group-field"
				:input-value="radio.value"
				@change="onRadioButtonOptionChanged( $event )"
			>
				{{ radio.label }}
			</cdx-radio>

			<character-limited-text-area
				v-if="collectSomethingElseDetails"
				v-model:text-content="inputSomethingElseDetails"
				:code-point-limit="somethingElseDetailsCodepointLimit"
				class="ext-reportincident-dialog-types-of-behavior__something-else-textarea"
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
const { storeToRefs } = require( 'pinia' );
const { computed, onMounted } = require( 'vue' );
const { CdxField, CdxMessage, CdxRadio } = require( '@wikimedia/codex' );
const CharacterLimitedTextArea = require( './CharacterLimitedTextArea.vue' );
const ParsedMessage = require( './ParsedMessage.vue' );
const Constants = require( '../Constants.js' );

// @vue/component
module.exports = exports = {
	name: 'ReportIncidentDialogTypesOfBehavior',
	components: {
		CdxField,
		CdxMessage,
		CdxRadio,
		CharacterLimitedTextArea,
		ParsedMessage
	},

	setup() {
		const store = useFormStore();
		const logEvent = useInstrument();

		const somethingElseDetailsCodepointLimit = Constants.somethingElseDetailsCodepointLimit;

		onMounted( () => logEvent( 'view', { source: 'describe_unacceptable_behavior' } ) );

		const {
			inputBehavior,
			inputSomethingElseDetails,
			showValidationError
		} = storeToRefs( store );

		const harassmentOptions = store.harassmentOptions;

		/**
		 * Whether the "Something else" textbox value should be sent
		 * in the request body to the REST endpoint on form submission.
		 */
		const collectSomethingElseDetails =
				computed( () => inputBehavior.value === Constants.harassmentTypes.SOMETHING_ELSE );

		const formErrorMessages = computed( () => store.formErrorMessages );

		const harassmentStatus = computed( () => store.formErrorMessages.inputBehaviors ? 'error' : 'default' );

		const noticeMsg = mw.message( 'reportincident-dialog-unacceptable-behavior-community-managed' );

		/**
		 * Callback when the radio button selection changes for the behavior type.
		 */
		function onRadioButtonOptionChanged() {
			showValidationError.value = false;

			logEvent( 'click', {
				context: inputBehavior.value,
				source: 'describe_unacceptable_behavior'
			} );
		}

		return {
			harassmentOptions,
			inputBehavior,
			inputSomethingElseDetails,
			formErrorMessages,
			somethingElseDetailsCodepointLimit,
			harassmentStatus,
			collectSomethingElseDetails,
			onRadioButtonOptionChanged,
			noticeMsg
		};
	}
};
</script>

<style lang="less">
@import ( reference ) 'mediawiki.skin.variables.less';

.ext-reportincident-dialog-types-of-behavior {
	line-height: @line-height-medium;

	&__text-block {
		margin-top: @spacing-125;
	}
}

</style>
