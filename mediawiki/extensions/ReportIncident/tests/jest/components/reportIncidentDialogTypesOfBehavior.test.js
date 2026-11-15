'use strict';

const { mockCodePointLength } = require( '../utils.js' );

// Need to run this here as the import of ReportIncidentDialogTypesOfBehavior.vue
// without mediawiki.String defined causes errors in running these tests.
mockCodePointLength();

const ReportIncidentDialogTypesOfBehavior = require( '../../../resources/ext.reportIncident/components/ReportIncidentDialogTypesOfBehavior.vue' ),
	Constants = require( '../../../resources/ext.reportIncident/Constants.js' ),
	useFormStore = require( '../../../resources/ext.reportIncident/stores/Form.js' ),
	utils = require( '@vue/test-utils' ),
	{ createTestingPinia } = require( '@pinia/testing' );

const suppressMessage = 'Invalid prop: type check failed for prop "remainingCharacters"';
const renderComponent = ( testingPinia ) => utils.mount( ReportIncidentDialogTypesOfBehavior, {
	global: {
		// eslint-disable-next-line es-x/no-nullish-coalescing-operators
		plugins: [ testingPinia ?? createTestingPinia( { stubActions: false } ) ],
		config: {
			// Suppress message about mismatching property types
			warnHandler( msg, instance, trace ) {

				if ( !msg.includes( suppressMessage ) ) {
					// eslint-disable-next-line no-console
					console.warn( msg, instance, trace );
				}
			}
		}
	}
} );

describe( 'Report Incident Dialog Types of Behavior', () => {
	beforeEach( () => {
		const mwConfig = {
			wgReportIncidentDetailsCodePointLength: 1000
		};
		jest.spyOn( mw.config, 'get' ).mockImplementation( ( key ) => mwConfig[ key ] );
	} );

	it( 'renders correctly', () => {
		const wrapper = renderComponent();
		expect( wrapper.find( '.ext-reportincident-dialog-types-of-behavior' ).exists() ).toBe( true );
	} );

	it( 'has all default form elements loaded', () => {
		const wrapper = renderComponent();

		// The list of unacceptable behaviors is shown
		expect( wrapper.find( '.ext-reportincident-dialog-types-of-behavior__harassment-options' ).exists() ).toBe( true );
		// The textbox for the something else option is not shown until the "Something else" option is selected
		expect( wrapper.find( '.ext-reportincident-dialog-types-of-behavior__something-else-textarea' ).exists() ).toBe( false );
	} );

	it( 'shows non-emergency categories as defined by ReportIncidentEnabledNonEmergencyCategories', async () => {
		global.mw.config.get = jest.fn();
		global.mw.config.get.mockImplementation( ( key ) => {
			switch ( key ) {
				case 'wgReportIncidentEnableInstrumentation':
					return false;
				case 'wgReportIncidentEnabledNonEmergencyCategories':
					return [
						'INTIMIDATION'
					];
				default:
					throw new Error( 'Unknown key: ' + key );
			}
		} );
		const wrapper = renderComponent();
		expect( wrapper.find( 'input[value="intimidation"]' ).exists() ).toBe( true );
		expect( wrapper.find( 'input[value="sexual-harassment"]' ).exists() ).toBe( false );
	} );

	it( 'Gets correct error messages for display', () => {
		const wrapper = renderComponent();
		const store = useFormStore();

		store.isFormValidForSubmission();

		expect( wrapper.vm.formErrorMessages ).toStrictEqual( store.formErrorMessages );
	} );

	it( 'Should not collect incident details when "Something else" is not selected', () => {
		const wrapper = renderComponent();
		const store = useFormStore();

		store.inputBehavior = '';
		expect( wrapper.vm.collectSomethingElseDetails ).toBe( false );

		store.inputBehavior = Constants.harassmentTypes.INTIMIDATION;
		expect( wrapper.vm.collectSomethingElseDetails ).toBe( false );
	} );

	it( 'Should collect something else details when "Something else" is selected', () => {
		const wrapper = renderComponent();
		const store = useFormStore();

		store.inputBehavior = Constants.harassmentTypes.SOMETHING_ELSE;
		expect( wrapper.vm.collectSomethingElseDetails ).toBe( true );
	} );
} );
