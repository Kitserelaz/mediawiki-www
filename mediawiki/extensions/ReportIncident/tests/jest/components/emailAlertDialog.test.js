'use strict';

const EmailAlertDialog = require( '../../../resources/ext.reportIncident/components/EmailAlertDialog.vue' ),
	utils = require( '@vue/test-utils' );

const realLocation = window.location;

describe( 'Report Incident email alert dialog', () => {
	afterEach( () => {
		// Tests may replace the window.location, but this should
		// always be restored afterwards.
		window.location = realLocation;

		jest.restoreAllMocks();
	} );

	it( 'Dialog should be open when wrappedOpen is true', () => {
		const wrapper = utils.mount( EmailAlertDialog, { props: { open: true } } );
		// Email dialog should exist.
		expect( wrapper.find( '.ext-reportincident-emaildialog' ).exists() ).toBe( true );
	} );

	it( 'Dialog should be closed when wrappedOpen is false', () => {
		const wrapper = utils.mount( EmailAlertDialog, { props: { open: false } } );
		// Email dialog should exist.
		expect( wrapper.find( '.ext-reportincident-emaildialog' ).exists() ).toBe( false );
	} );

	it( 'Call to onPrimaryAction with an e-mail set', () => {
		const wrapper = utils.mount( EmailAlertDialog, { props: { open: true } } );
		// Mock window.location.assign.
		delete window.location;
		const assignMock = jest.fn();
		Object.defineProperty( window, 'location', {
			writable: true,
			value: { assign: assignMock }
		} );
		// Mock the call done to fetch wgReportIncidentUserHasEmail
		jest.spyOn( mw.config, 'get' ).mockReturnValue( true );
		// Mock mw.Title.newFromText
		mw.Title.newFromText = jest.fn().mockImplementation( ( title ) => {
			expect( title ).toStrictEqual( 'Special:ConfirmEmail' );
			return {
				getUrl: () => 'testing url'
			};
		} );
		// Call onPrimaryAction
		wrapper.vm.onPrimaryAction();
		// Method should have attempted to redirect the user to the email confirmation page
		// using window.location.assign.
		expect( assignMock ).toHaveBeenCalledWith( 'testing url' );
	} );

	it( 'Call to onPrimaryAction with an e-mail not set', () => {
		const wrapper = utils.mount( EmailAlertDialog, { props: { open: true } } );

		// Mock window.location.assign.
		delete window.location;
		const assignMock = jest.fn();
		Object.defineProperty( window, 'location', {
			writable: true,
			value: { assign: assignMock }
		} );

		// Mock the call done to fetch wgReportIncidentUserHasEmail
		jest.spyOn( mw.config, 'get' ).mockReturnValue( false );

		// Mock mw.Title.newFromText
		mw.Title.newFromText = jest.fn().mockImplementation( ( title ) => {
			expect( title ).toStrictEqual( 'Special:Preferences' );
			return {
				getUrl: () => 'testing url'
			};
		} );
		// Call onPrimaryAction
		wrapper.vm.onPrimaryAction();
		// Method should have attempted to redirect the user to the preferences
		// section to set the e-mail using window.location.assign.
		expect( assignMock ).toHaveBeenCalledWith( 'testing url#mw-prefsection-personal-email' );
	} );
} );
