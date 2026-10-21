'use strict';

const ReportIncidentDialogStep1 = require( '../../../resources/ext.reportIncident/components/ReportIncidentDialogStep1.vue' ),
	utils = require( '@vue/test-utils' );

describe( 'Report Incident Dialog Step 1', () => {
	it( 'mounts the component', () => {
		const wrapper = utils.shallowMount( ReportIncidentDialogStep1 );
		expect( wrapper.find( '.ext-reportincident-dialog-step1' ).exists() ).toBe( true );
	} );
} );
