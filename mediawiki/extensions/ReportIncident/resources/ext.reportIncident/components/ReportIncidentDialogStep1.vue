<template>
	<div class="ext-reportincident-dialog-step1">
		<cdx-field
			:is-fieldset="true"
			:status="incidentTypeStatus"
			:messages="incidentTypeMessages">
			<div
				v-for="radio in incidentTypes"
				:key="radio.value"
			>
				<h3>{{ radio.header }}</h3>
				<cdx-radio
					:key="'radio-' + radio.value"
					v-model="incidentType"
					name="reportincident-type-radio-group-field"
					:input-value="radio.value"
					@change="onChange( $event )"
				>
					{{ radio.label }}
					<template v-if="radio.helpText" #description>
						{{ radio.helpText }}
					</template>
					<template v-if="radio.value === 'immediate-threat-physical-harm'" #custom-input>
						<cdx-field
							:is-fieldset="true"
							:messages="physicalHarmTypeMessages"
							:status="physicalHarmTypeStatus"
						>
							<cdx-select
								v-model:selected="physicalHarmType"
								:menu-items="physicalHarmTypes"
								:disabled="incidentType !== 'immediate-threat-physical-harm'"
								:default-label="$i18n( 'reportincident-choose-option' ).text()"
								@update:selected="onPhysicalHarmTypeChanged"
							></cdx-select>
						</cdx-field>
					</template>
				</cdx-radio>
			</div>
		</cdx-field>
	</div>
</template>

<script>

const { computed } = require( 'vue' );
const useFormStore = require( '../stores/Form.js' );
const useInstrument = require( '../composables/useInstrument.js' );
const { storeToRefs } = require( 'pinia' );
const Constants = require( '../Constants.js' );
const { CdxSelect, CdxField, CdxRadio } = require( '@wikimedia/codex' );
// @vue/component
module.exports = exports = {
	name: 'ReportIncidentDialogStep1',
	components: {
		CdxField,
		CdxRadio,
		CdxSelect
	},
	setup() {
		const store = useFormStore();
		const logEvent = useInstrument();

		const {
			funnelName,
			incidentType,
			physicalHarmType,
			showValidationError
		} = storeToRefs( store );
		const incidentTypeStatus = computed( () => showValidationError.value && incidentType.value.length === 0 ? 'error' : 'default' );
		const incidentTypeMessages = { error: mw.msg( 'reportincident-type-incident-required' ) };
		const physicalHarmTypeStatus = computed( () => showValidationError.value && incidentType.value === Constants.typeOfIncident.immediateThreatPhysicalHarm && physicalHarmType.value.length === 0 ? 'error' : 'default' );
		const physicalHarmTypeMessages = { error: mw.msg( 'reportincident-threat-harm-required' ) };

		/**
		 * Callback when the radio button selection changes for the incident type.
		 */
		function onChange() {
			// Reset any validation errors.
			showValidationError.value = false;

			if ( incidentType.value === Constants.typeOfIncident.unacceptableUserBehavior ) {
				// If the user switched back to unacceptable user behaviors, reset
				// the physical harm type value.
				physicalHarmType.value = '';
				funnelName.value = 'non-emergency';
			} else {
				funnelName.value = 'emergency';
			}

			logEvent( 'click', {
				source: 'form',
				context: funnelName.value
			} );
		}

		/**
		 * Record an instrumentation event when a physical harm type is selected.
		 */
		function onPhysicalHarmTypeChanged() {
			logEvent( 'click', {
				source: 'form',
				context: store.physicalHarmTypeContext
			} );
		}

		const incidentTypes = [
			{
				header: mw.msg( 'reportincident-radio-header-nonemergency' ),
				label: mw.msg( 'reportincident-type-nonemergency' ),
				value: Constants.typeOfIncident.unacceptableUserBehavior,
				helpText: mw.msg( 'reportincident-nonemergency-help' )
			},
			{
				header: mw.msg( 'reportincident-radio-header-emergency' ),
				label: mw.msg( 'reportincident-type-emergency' ),
				value: Constants.typeOfIncident.immediateThreatPhysicalHarm,
				helpText: mw.msg( 'reportincident-type-emergency-help' )
			}
		];
		const physicalHarmTypes = [
			{
				label: mw.msg( 'reportincident-threats-physical-harm' ),
				value: Constants.physicalHarmTypes.physicalHarm
			},
			{
				label: mw.msg( 'reportincident-threats-self-harm' ),
				value: Constants.physicalHarmTypes.selfHarm
			},
			{
				label: mw.msg( 'reportincident-threats-public-harm' ),
				value: Constants.physicalHarmTypes.publicHarm
			}
		];

		return {
			incidentType,
			incidentTypes,
			physicalHarmType,
			physicalHarmTypes,
			incidentTypeStatus,
			incidentTypeMessages,
			physicalHarmTypeStatus,
			physicalHarmTypeMessages,
			onChange,
			onPhysicalHarmTypeChanged
		};
	}
};
</script>

<style lang="less">
@import ( reference ) 'mediawiki.skin.variables.less';

.ext-reportincident-dialog-step1 {
	line-height: @line-height-medium;

	&__text-block {
		margin-top: @spacing-125;
	}
}
</style>
