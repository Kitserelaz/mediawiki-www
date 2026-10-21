<template>
	<cdx-message
		v-if="formSuccessfullySubmitted"
		type="success"
		:dismiss-button-label="$i18n( 'reportincident-success-message-close' ).text()"
		class="ext-reportincident__success-confirmation-message">
		{{ $i18n( 'reportincident-success-message' ).text() }}
	</cdx-message>
</template>

<script>
const { CdxMessage } = require( '@wikimedia/codex' );
const useFormStore = require( '../stores/Form.js' );
const { storeToRefs } = require( 'pinia' );

// @vue/component
module.exports = exports = {
	name: 'SuccessConfirmationBanner',
	compatConfig: {
		MODE: 3
	},
	compilerOptions: {
		whitespace: 'condense'
	},
	components: {
		CdxMessage
	},
	setup() {
		const store = useFormStore();

		const { formSuccessfullySubmitted } = storeToRefs( store );

		return {
			formSuccessfullySubmitted
		};
	}
};
</script>
