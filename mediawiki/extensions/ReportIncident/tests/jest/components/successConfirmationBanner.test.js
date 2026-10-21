'use strict';

const SuccessConfirmationBanner = require( '../../../resources/ext.reportIncident/components/SuccessConfirmationBanner.vue' ),
	utils = require( '@vue/test-utils' ),
	{ createTestingPinia } = require( '@pinia/testing' ),
	useFormStore = require( '../../../resources/ext.reportIncident/stores/Form.js' ),
	{ nextTick } = require( 'vue' );

const renderComponent = () => utils.mount( SuccessConfirmationBanner, {
	global: {
		plugins: [ createTestingPinia( { stubActions: false } ) ]
	}
} );

describe( 'Success confirmation banner', () => {
	it( 'Is displayed when store.formSuccessfullySubmitted is true', async () => {
		const wrapper = renderComponent();
		const store = useFormStore();
		store.formSuccessfullySubmitted = true;
		expect( wrapper.vm.formSuccessfullySubmitted ).toBe( true );
		await nextTick();
		expect( wrapper.find( '.ext-reportincident__success-confirmation-message' ).exists() ).toBe( true );
	} );

	it( 'Is hidden when store.formSuccessfullySubmitted is false', async () => {
		const wrapper = renderComponent();
		const store = useFormStore();
		store.formSuccessfullySubmitted = false;
		expect( wrapper.vm.formSuccessfullySubmitted ).toBe( false );
		await nextTick();
		expect( wrapper.find( '.ext-reportincident__success-confirmation-message' ).exists() ).toBe( false );
	} );
} );
