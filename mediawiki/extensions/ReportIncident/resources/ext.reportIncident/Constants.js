const Constants = {
	DIALOG_STEP_1: 'dialog_step_1',
	DIALOG_STEP_REPORT_BEHAVIOR_TYPES: 'dialog_step_report_unacceptable_behavior',
	DIALOG_STEP_REPORT_IMMEDIATE_HARM: 'dialog_step_report_immediate_harm',
	DIALOG_STEP_EMERGENCY_SUBMIT_SUCCESS: 'dialog_step_emergency_submit_success',
	DIALOG_STEP_NONEMERGENCY_SUBMIT_SUCCESS: 'dialog_step_nonemergency_submit_success',

	typeOfIncident: {
		unacceptableUserBehavior: 'unacceptable-user-behavior',
		immediateThreatPhysicalHarm: 'immediate-threat-physical-harm'
	},
	physicalHarmTypes: {
		physicalHarm: 'threats-physical-harm',
		selfHarm: 'threats-self-harm',
		publicHarm: 'threats-public-harm'
	},
	harassmentTypes: {
		DOXING: 'doxing',
		HATE_SPEECH: 'hate-or-discrimination',
		INTIMIDATION: 'intimidation',
		SEXUAL_HARASSMENT: 'sexual-harassment',
		SPAM: 'spam',
		TROLLING: 'trolling',
		SOMETHING_ELSE: 'something-else',
		SOCKPUPPETRY: 'sockpuppetry',
		VANDALISM: 'vandalism',
		USER_DISPUTE: 'userdispute',
		DISRUPTIVE_EDITING: 'disruptiveediting',
		OTHER: 'other'
	},
	harassmentTypesV2: {
		DOXING: {
			id: 'doxing',
			labelKey: 'reportincident-dialog-harassment-type-doxing'
		},
		HATE_SPEECH: {
			id: 'hate-or-discrimination',
			labelKey: 'reportincident-dialog-harassment-type-hate-speech-or-discrimination'
		},
		INTIMIDATION: {
			id: 'intimidation',
			labelKey: 'reportincident-dialog-harassment-type-intimidation'
		},
		SEXUAL_HARASSMENT: {
			id: 'sexual-harassment',
			labelKey: 'reportincident-dialog-harassment-type-sexual-harassment'
		},
		SPAM: {
			id: 'spam',
			labelKey: 'reportincident-dialog-harassment-type-spam'
		},
		TROLLING: {
			id: 'trolling',
			labelKey: 'reportincident-dialog-harassment-type-trolling'
		},
		SOMETHING_ELSE: {
			id: 'something-else',
			labelKey: 'reportincident-dialog-harassment-type-something-else'
		},
		SOCKPUPPETRY: {
			id: 'sockpuppetry',
			labelKey: 'reportincident-dialog-harassment-type-sockpuppetry'
		},
		VANDALISM: {
			id: 'vandalism',
			labelKey: 'reportincident-dialog-harassment-type-vandalism'
		},
		USER_DISPUTE: {
			id: 'userdispute',
			labelKey: 'reportincident-dialog-harassment-type-userdispute'
		},
		DISRUPTIVE_EDITING: {
			id: 'disruptiveediting',
			labelKey: 'reportincident-dialog-harassment-type-disruptiveediting'
		},
		OTHER: {
			id: 'other',
			labelKey: 'reportincident-dialog-harassment-type-other'
		}
	},
	/**
	 * The number of Unicode codepoints accepted by textareas holding report details.
	 */
	detailsCodepointLimit: 1000,
	// When selecting "something else" in the non-emergency workflow, the user is prompted
	// to add additional context which is then recorded via instrumentation, which limits
	// the character length possible. Set this limit here.
	somethingElseDetailsCodepointLimit: 200
};

module.exports = Constants;
