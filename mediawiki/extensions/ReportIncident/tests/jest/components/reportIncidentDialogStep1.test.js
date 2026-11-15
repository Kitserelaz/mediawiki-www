'use strict';

jest.mock( '../../../resources/ext.reportIncident/composables/useInstrument.js' );

const ReportIncidentDialogStep1 = require( '../../../resources/ext.reportIncident/components/ReportIncidentDialogStep1.vue' ),
	{ createTestingPinia } = require( '@pinia/testing' ),
	utils = require( '@vue/test-utils' ),
	{ storeToRefs } = require( 'pinia' ),
	Constants = require( '../../../resources/ext.reportIncident/Constants.js' ),
	useFormStore = require( '../../../resources/ext.reportIncident/stores/Form.js' ),
	useInstrument = require( '../../../resources/ext.reportIncident/composables/useInstrument.js' );

const renderComponent = ( testingPinia ) => utils.mount( ReportIncidentDialogStep1, {
	global: {
		// eslint-disable-next-line es-x/no-nullish-coalescing-operators
		plugins: [ testingPinia ?? createTestingPinia( { stubActions: false } ) ]
	}
} );
describe( 'Report Incident Dialog Step 1', () => {
	let logEvent;

	beforeEach( () => {
		logEvent = jest.fn();
		return useInstrument.mockImplementation( () => logEvent );
	} );

	it( 'mounts the component', () => {
		const wrapper = renderComponent();
		expect( wrapper.find( '.ext-reportincident-dialog-step1' ).exists() ).toBe( true );
	} );

	it( 'clears validation errors on change', async () => {
		const wrapper = renderComponent();
		const store = useFormStore();
		const { showValidationError } = storeToRefs( store );
		showValidationError.value = true;
		await wrapper.vm.onChange();
		expect( showValidationError.value ).toBe( false );
	} );

	it( 'Resets physical harm type if radio button switched to non-emergency flow', async () => {
		const wrapper = renderComponent();
		const store = useFormStore();
		const { incidentType, physicalHarmType } = storeToRefs( store );
		physicalHarmType.value = 'foo';
		await wrapper.vm.onChange();
		expect( physicalHarmType.value ).toBe( 'foo' );

		incidentType.value = Constants.typeOfIncident.unacceptableUserBehavior;
		await wrapper.vm.onChange();
		expect( physicalHarmType.value ).toBe( '' );
	} );

	it( 'sets the incident type status to error if we should show a validation error and no incident type is selected', async () => {
		const wrapper = renderComponent();
		const store = useFormStore();
		const { showValidationError, incidentType } = storeToRefs( store );
		showValidationError.value = true;
		incidentType.value = '';
		expect( wrapper.vm.incidentTypeStatus ).toBe( 'error' );
	} );

	it( 'sets the incident type status to default if show validation error not set, and incident type is selected', async () => {
		const wrapper = renderComponent();
		const store = useFormStore();
		const { showValidationError, incidentType } = storeToRefs( store );
		showValidationError.value = false;
		incidentType.value = Constants.typeOfIncident.unacceptableUserBehavior;
		expect( wrapper.vm.incidentTypeStatus ).toBe( 'default' );
	} );

	it( 'sets the incident type status to default if show validation error is set, and incident type is selected', async () => {
		const wrapper = renderComponent();
		const store = useFormStore();
		const { showValidationError, incidentType } = storeToRefs( store );
		showValidationError.value = true;
		incidentType.value = Constants.typeOfIncident.unacceptableUserBehavior;
		expect( wrapper.vm.incidentTypeStatus ).toBe( 'default' );
	} );

	it( 'sets the physical harm type status to default if show validation error is set, and physical harm subtype is selected', async () => {
		const wrapper = renderComponent();
		const store = useFormStore();
		const { showValidationError, physicalHarmType } = storeToRefs( store );
		showValidationError.value = true;
		physicalHarmType.value = Constants.physicalHarmTypes.physicalHarm;
		expect( wrapper.vm.physicalHarmTypeStatus ).toBe( 'default' );
	} );

	it( 'sets the physical harm type status to default if show validation error is set, and physical harm subtype is selected', async () => {
		const wrapper = renderComponent();
		const store = useFormStore();
		const { incidentType, showValidationError, physicalHarmType } = storeToRefs( store );
		incidentType.value = Constants.typeOfIncident.immediateThreatPhysicalHarm;
		showValidationError.value = true;
		physicalHarmType.value = Constants.physicalHarmTypes.physicalHarm;
		expect( wrapper.vm.physicalHarmTypeStatus ).toBe( 'default' );
	} );

	it( 'sets the physical harm type status to error if show validation error is set, and physical harm subtype is not selected', async () => {
		const wrapper = renderComponent();
		const store = useFormStore();
		const { incidentType, showValidationError, physicalHarmType } = storeToRefs( store );
		incidentType.value = Constants.typeOfIncident.immediateThreatPhysicalHarm;
		showValidationError.value = true;
		physicalHarmType.value = '';
		expect( wrapper.vm.physicalHarmTypeStatus ).toBe( 'error' );
	} );

	it( 'instruments changes to physical harm type', async () => {
		const wrapper = renderComponent();
		const store = useFormStore();

		const { incidentType, physicalHarmType } = storeToRefs( store );
		incidentType.value = Constants.typeOfIncident.immediateThreatPhysicalHarm;
		physicalHarmType.value = Constants.physicalHarmTypes.publicHarm;

		await wrapper.vm.onPhysicalHarmTypeChanged();

		expect( logEvent ).toHaveBeenCalledTimes( 1 );
		expect( logEvent ).toHaveBeenCalledWith( 'click', { source: 'form', context: 'public' } );
	} );
} );
