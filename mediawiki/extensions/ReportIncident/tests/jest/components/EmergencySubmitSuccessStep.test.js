'use strict';

jest.mock( '../../../resources/ext.reportIncident/composables/useInstrument.js' );

const EmergencySubmitSuccessStep = require( '../../../resources/ext.reportIncident/components/EmergencySubmitSuccessStep.vue' ),
	{ createTestingPinia } = require( '@pinia/testing' ),
	utils = require( '@vue/test-utils' ),
	Constants = require( '../../../resources/ext.reportIncident/Constants.js' ),
	useInstrument = require( '../../../resources/ext.reportIncident/composables/useInstrument.js' );

describe( 'EmergencySubmitSuccessStep', () => {
	function mount( formStoreState ) {
		return utils.mount( EmergencySubmitSuccessStep, {
			global: {
				plugins: [
					createTestingPinia( {
						stubActions: false,
						initialState: {
							form: formStoreState
						}
					} )
				]
			}
		} );
	}

	let logEvent;

	beforeEach( () => {
		jest.spyOn( mw, 'message' ).mockImplementation( ( key ) => ( {
			text() {
				return key;
			},
			parse() {
				return key;
			}
		} ) );

		logEvent = jest.fn();
		useInstrument.mockImplementation( () => logEvent );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it( 'renders for emergency flow', () => {
		const wrapper = mount( {
			incidentType: Constants.typeOfIncident.immediateThreatPhysicalHarm
		} );
		const message = wrapper.find( '#reportincident-form > .cdx-message' );
		const headers = wrapper.findAll( 'h3' ).map( ( h ) => h.text() );
		expect( message.classes( 'cdx-message--success' ) ).toBe( true );
		expect( message.text() ).toBe( 'reportincident-submit-emergency-success' );
		expect( headers ).toEqual( [
			'reportincident-submit-emergency-section-important-title',
			'reportincident-submit-emergency-section-next-title'
		] );
		expect( logEvent ).toHaveBeenCalledTimes( 1 );
		expect( logEvent ).toHaveBeenCalledWith( 'view', { source: 'submitted' } );
	} );
} );
