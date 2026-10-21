'use strict';

const { mockCodePointLength } = require( '../utils.js' );

// Need to run this here as the import of ReportIncidentDialogStep2.vue
// without mediawiki.String defined causes errors in running these tests.
const mockMediaWikiStringCodePointLength = mockCodePointLength();

const ReportIncidentDialogStep2 = require( '../../../resources/ext.reportIncident/components/ReportIncidentDialogStep2.vue' ),
	utils = require( '@vue/test-utils' ),
	{ createTestingPinia } = require( '@pinia/testing' ),
	{ mockApiGet } = require( '../utils.js' ),
	{ nextTick, ref } = require( 'vue' ),
	Constants = require( '../../../resources/ext.reportIncident/Constants.js' ),
	useFormStore = require( '../../../resources/ext.reportIncident/stores/Form.js' );

const renderComponent = ( testingPinia ) => utils.mount( ReportIncidentDialogStep2, {
	global: {
		// eslint-disable-next-line es-x/no-nullish-coalescing-operators
		plugins: [ testingPinia ?? createTestingPinia( { stubActions: false } ) ]
	}
} );

/**
 * Mocks mw.log.error() and returns a jest.fn() for error()
 *
 * @return {jest.fn}
 */
function mockErrorLogger() {
	const mwLogError = jest.fn();
	mw.log.error = mwLogError;
	return mwLogError;
}

/**
 * Mocks mw.language.convertNumber() and returns a jest.fn() for convertNumber()
 *
 * @return {jest.fn}
 */
function mockConvertNumber() {
	const mwConvertNumber = jest.fn();
	mwConvertNumber.mockImplementation( ( number ) => number );
	mw.language.convertNumber = mwConvertNumber;
	return mwConvertNumber;
}

/**
 * Wait until the debounce performed by loadSuggestedUsernames
 * is complete by waiting 120ms (longer than the 100ms delay
 * in that function).
 *
 * @return {Promise}
 */
const waitUntilDebounceComplete = () => new Promise( ( resolve ) => {
	setTimeout( () => {
		resolve();
	}, 120 );
} );

describe( 'Report Incident Dialog Step 2', () => {
	let jQueryCodePointLimitMock;
	beforeEach( () => {
		// Mock the codePointLimit which is added by a plugin.
		jQueryCodePointLimitMock = jest.fn();
		global.$.prototype.codePointLimit = jQueryCodePointLimitMock;
		// Mock wgCommentCodePointLimit to the default value of 500.
		jest.spyOn( mw.config, 'get' ).mockImplementation( ( key ) => {
			switch ( key ) {
				case 'wgCommentCodePointLimit':
					return 500;
			}
		} );
	} );

	it( 'renders correctly', () => {
		const wrapper = renderComponent();
		expect( wrapper.find( '.ext-reportincident-dialog-step2' ).exists() ).toBe( true );
		// Expect that the value of wgCommentCodePointLimit is passed to the codePointLimit call
		// for the additional details field.
		expect( jQueryCodePointLimitMock ).toHaveBeenCalledWith( 500 );
	} );

	it( 'has all default form elements loaded', () => {
		const wrapper = renderComponent();

		expect( wrapper.find( '.ext-reportincident-dialog-step2__harassment-options' ).exists() ).toBe( true );
		expect( wrapper.find( '.ext-reportincident-dialog-step2__violator-name' ).exists() ).toBe( true );
		expect( wrapper.find( '.ext-reportincident-dialog-step2__additional-details' ).exists() ).toBe( true );
	} );

	it( 'Gets correct error messages for display', () => {
		const wrapper = renderComponent();
		const store = useFormStore();

		store.isFormValidForSubmission();

		expect( wrapper.vm.formErrorMessages ).toStrictEqual( store.formErrorMessages );
	} );

	it( 'Sets correct status values', async () => {
		const wrapper = renderComponent();
		const store = useFormStore();

		// No errors should be displayed until a user tries to submit the form
		// or focuses out of the required field.
		expect( wrapper.vm.harassmentStatus ).toBe( 'default' );
		expect( wrapper.vm.reportedUserStatus ).toBe( 'default' );

		// Call method used to indicate that form is about to be submitted.
		store.isFormValidForSubmission();

		// Errors should be displayed when a user tries to submit the form without
		// specifying required fields.
		expect( wrapper.vm.harassmentStatus ).toBe( 'error' );
		expect( wrapper.vm.reportedUserStatus ).toBe( 'error' );
	} );

	it( 'Should not collect something else details', () => {
		const wrapper = renderComponent();
		const store = useFormStore();

		store.inputBehaviors = [];
		expect( wrapper.vm.collectSomethingElseDetails ).toBe( false );

		store.inputBehaviors = [ Constants.harassmentTypes.INTIMIDATION_AGGRESSION ];
		expect( wrapper.vm.collectSomethingElseDetails ).toBe( false );
	} );

	it( 'Should collect something else details', () => {
		const wrapper = renderComponent();
		const store = useFormStore();

		store.inputBehaviors = [ Constants.harassmentTypes.OTHER ];
		expect( wrapper.vm.collectSomethingElseDetails ).toBe( true );

		store.inputBehaviors = [
			Constants.harassmentTypes.OTHER,
			Constants.harassmentTypes.HATE_SPEECH
		];
		expect( wrapper.vm.collectSomethingElseDetails ).toBe( true );
	} );

	it( 'Should apply codePointLimit on something else textarea on open', async () => {
		const wrapper = renderComponent();
		const store = useFormStore();

		store.inputBehaviors = [ Constants.harassmentTypes.OTHER ];
		expect( wrapper.vm.collectSomethingElseDetails ).toBe( true );
		// Wait until the watch on collectSomethingElseDetails has run.
		await nextTick();
		// Wait until the next tick so that the call inside a nextTick call of the code under-test
		// is complete.
		return nextTick( () => {
			// codePointLimit should have been called twice (additional details and something else fields)
			expect( jQueryCodePointLimitMock ).toHaveBeenCalledTimes( 2 );
			expect( jQueryCodePointLimitMock ).toHaveBeenNthCalledWith( 1, 500 );
			expect( jQueryCodePointLimitMock ).toHaveBeenNthCalledWith( 2, 500 );
		} );
	} );

	it( 'Should apply codePointLimit on something else textarea if open on mount', async () => {
		const testingPinia = createTestingPinia( { stubActions: false } );
		const store = useFormStore();

		store.inputBehaviors = [ Constants.harassmentTypes.OTHER ];
		const wrapper = renderComponent( testingPinia );
		expect( wrapper.vm.collectSomethingElseDetails ).toBe( true );
		// codePointLimit should have been called twice (additional details and something else fields)
		expect( jQueryCodePointLimitMock ).toHaveBeenCalledTimes( 2 );
		expect( jQueryCodePointLimitMock ).toHaveBeenNthCalledWith( 1, 500 );
		expect( jQueryCodePointLimitMock ).toHaveBeenNthCalledWith( 2, 500 );
	} );

	it( 'showSomethingElseCharacterCount should be false when something else field hidden', () => {
		const wrapper = renderComponent();
		wrapper.vm.somethingElseDetailsCharacterCountLeft = 1;
		return nextTick( () => {
			expect( wrapper.vm.showSomethingElseCharacterCount ).toBe( false );
		} );
	} );

	it( 'showSomethingElseCharacterCount should be false when counter is the empty string', () => {
		const wrapper = renderComponent();
		const store = useFormStore();
		store.inputBehaviors = [ Constants.harassmentTypes.OTHER ];
		wrapper.vm.somethingElseDetailsCharacterCountLeft = '';
		return nextTick( () => {
			expect( wrapper.vm.showSomethingElseCharacterCount ).toBe( false );
		} );
	} );

	it( 'showSomethingElseCharacterCount should be true when counter is a number and the something else field is shown', () => {
		const wrapper = renderComponent();
		const store = useFormStore();
		store.inputBehaviors = [ Constants.harassmentTypes.OTHER ];
		wrapper.vm.somethingElseDetailsCharacterCountLeft = 2;
		return nextTick( () => {
			expect( wrapper.vm.showSomethingElseCharacterCount ).toBe( true );
		} );
	} );

	it( 'showAdditionalDetailsCharacterCount should be false when counter is the empty string', () => {
		const wrapper = renderComponent();
		wrapper.vm.additionalDetailsCharacterCountLeft = '';
		return nextTick( () => {
			expect( wrapper.vm.showAdditionalDetailsCharacterCount ).toBe( false );
		} );
	} );

	it( 'showAdditionalDetailsCharacterCount should be true when counter is a number', () => {
		const wrapper = renderComponent();
		wrapper.vm.additionalDetailsCharacterCountLeft = 2;
		return nextTick( () => {
			expect( wrapper.vm.showAdditionalDetailsCharacterCount ).toBe( true );
		} );
	} );

	it( 'Updates count to empty string on call to updateCharacterCount with more than 99 characters left', () => {
		const wrapper = renderComponent();
		const counterRef = ref( 1 );
		mockMediaWikiStringCodePointLength.mockReturnValueOnce( 10 );
		wrapper.vm.updateCharacterCount( 'test', counterRef );
		expect( counterRef.value ).toBe( '' );
		expect( mockMediaWikiStringCodePointLength ).toHaveBeenLastCalledWith( 'test' );
	} );

	it( 'Updates count to a number on call to updateCharacterCount with fewer than 99 characters left', () => {
		const wrapper = renderComponent();
		const counterRef = ref( '' );
		mockConvertNumber();
		mockMediaWikiStringCodePointLength.mockReturnValueOnce( 451 );
		wrapper.vm.updateCharacterCount( 'testing', counterRef );
		expect( counterRef.value ).toBe( 49 );
		expect( mockMediaWikiStringCodePointLength ).toHaveBeenLastCalledWith( 'testing' );
	} );

	it( 'Updates count 0 on call to updateCharacterCount with no characters left', () => {
		const wrapper = renderComponent();
		const counterRef = ref( '' );
		mockConvertNumber();
		mockMediaWikiStringCodePointLength.mockReturnValueOnce( 500 );
		wrapper.vm.updateCharacterCount( 'testing', counterRef );
		expect( counterRef.value ).toBe( 0 );
		expect( mockMediaWikiStringCodePointLength ).toHaveBeenLastCalledWith( 'testing' );
	} );

	it( 'Updates character count on call to onSomethingElseDetailsInput', async () => {
		const wrapper = renderComponent();
		const store = useFormStore();
		store.inputBehaviors = [ Constants.harassmentTypes.OTHER ];
		mockConvertNumber();
		mockMediaWikiStringCodePointLength.mockReturnValueOnce( 450 );
		wrapper.vm.onSomethingElseDetailsInput( { target: { value: 'test' } } );
		expect( wrapper.vm.somethingElseDetailsCharacterCountLeft ).toBe( 50 );
	} );

	it( 'Updates character count on call to onAdditionalDetailsInput', () => {
		const wrapper = renderComponent();
		mockConvertNumber();
		mockMediaWikiStringCodePointLength.mockReturnValueOnce( 450 );
		wrapper.vm.onAdditionalDetailsInput( { target: { value: 'test' } } );
		expect( wrapper.vm.additionalDetailsCharacterCountLeft ).toBe( 50 );
	} );

	it( 'Should update menu config on change in window height', () => {
		const wrapper = renderComponent();

		// Set the window height to 1 to test that the minimum visibleItemLimit will be 2.
		wrapper.vm.windowHeight = 1;
		expect( wrapper.vm.reportedUserLookupMenuConfig.visibleItemLimit ).toBe( 2 );

		// Set the window height to 1000 to test that the maximum visibleItemLimit is 5.
		wrapper.vm.windowHeight = 1;
		expect( wrapper.vm.reportedUserLookupMenuConfig.visibleItemLimit ).toBe( 2 );

		// Set the window height to 500 to test the x / 150 calculation
		wrapper.vm.windowHeight = 500;
		// The floor division of 500 by 150 is 3.
		expect( wrapper.vm.reportedUserLookupMenuConfig.visibleItemLimit ).toBe( 3 );
	} );

	it( 'Should query allusers API on call to onReportedUserInput', async () => {
		const apiGet = mockApiGet(
			Promise.resolve(
				{ query: { allusers: [
					{ userid: 1, name: 'testing' },
					{ userid: 2, name: 'testing1' },
					{ userid: 3, name: 'testing2' }
				] } }
			)
		);
		const wrapper = renderComponent();
		const store = useFormStore();
		// Fake that a previous submit has caused the user non-existent
		// error to display.
		store.reportedUserDoesNotExist = true;
		// Call the method under test
		wrapper.vm.onReportedUserInput( 'testing' );
		expect( wrapper.vm.inputReportedUser ).toBe( 'testing' );
		// Change to the inputReportedUser value should invalidate previous
		// non-existent user error returned by the server.
		expect( store.reportedUserDoesNotExist ).toBe( false );
		// Wait until the debounce time has expired and add around 20ms to be sure it has run.
		await waitUntilDebounceComplete();
		// The suggestions should now be set.
		expect( wrapper.vm.inputReportedUserMenuItems ).toStrictEqual( [
			{ value: 'testing' },
			{ value: 'testing1' },
			{ value: 'testing2' }
		] );
		expect( apiGet ).toHaveBeenCalledWith( {
			action: 'query',
			list: 'allusers',
			auprefix: 'testing',
			limit: '10'
		} );
	} );

	it( 'Call to onReportedUserInput but API promise rejects', async () => {
		const rejectedPromise = Promise.reject( 'error' );
		// Catch the rejected promise in a function that does nothing to
		// allow the tests to run (otherwise they fail with an
		// ERR_UNHANDLED_REJECTION error).
		rejectedPromise.catch( () => {} );
		const apiGet = mockApiGet( rejectedPromise );
		const mwLogError = mockErrorLogger();
		const wrapper = renderComponent();
		// Call the method under test
		wrapper.vm.onReportedUserInput( 'testing' );
		// Wait until the debounce time has expired and add around 20ms to be sure it has run.
		await waitUntilDebounceComplete();
		// The suggestions should now be set.
		expect( wrapper.vm.inputReportedUserMenuItems ).toStrictEqual( [] );
		// Expect that mw.log.error() was called
		expect( mwLogError ).toHaveBeenCalledWith( 'error' );
		expect( apiGet ).toHaveBeenCalledWith( {
			action: 'query',
			list: 'allusers',
			auprefix: 'testing',
			limit: '10'
		} );
	} );

	it( 'Call to onReportedUserInput but input is updated before API request finished', async () => {
		const apiGet = mockApiGet(
			Promise.resolve(
				{ query: { allusers: [
					{ userid: 1, name: 'testing' },
					{ userid: 2, name: 'testing1' },
					{ userid: 3, name: 'testing2' }
				] } }
			)
		);
		const wrapper = renderComponent();
		const store = useFormStore();
		// Update the value of inputReportedUserMenuItems so that the test can verify it empties on a failed request.
		wrapper.vm.suggestedUsernames.value = [ { name: 'test123123123123123' } ];
		// Call the method under test
		wrapper.vm.onReportedUserInput( 'testingabc' );
		// Update the value of store.inputReportedUser before the debounce timer has finished.
		store.inputReportedUser = 'testing1234';
		// Wait until the debounce time has expired and add around 20ms to be sure it has run.
		await waitUntilDebounceComplete();
		// The suggestions should now be set.
		expect( wrapper.vm.inputReportedUserMenuItems ).toStrictEqual( [] );
		expect( apiGet ).toHaveBeenCalledWith( {
			action: 'query',
			list: 'allusers',
			auprefix: 'testingabc',
			limit: '10'
		} );
	} );

	it( 'Call to onReportedUserInput but API returns unparsable response', async () => {
		const apiGet = mockApiGet( Promise.resolve( { test: 'test' } ) );
		const wrapper = renderComponent();
		// Update the value of inputReportedUserMenuItems so that the test can verify it empties on a failed request.
		wrapper.vm.suggestedUsernames = [ { name: 'testing123123' } ];
		// Call the method under test
		wrapper.vm.onReportedUserInput( 'testing12' );
		// Wait until the debounce time has expired and add around 20ms to be sure it has run.
		await waitUntilDebounceComplete();
		// The suggestions should now be set.
		expect( wrapper.vm.inputReportedUserMenuItems ).toStrictEqual( [] );
		expect( apiGet ).toHaveBeenCalledWith( {
			action: 'query',
			list: 'allusers',
			auprefix: 'testing12',
			limit: '10'
		} );
	} );

	it( 'Call to onReportedUserInput but input is empty', () => {
		const wrapper = renderComponent();
		// Update the value of inputReportedUserMenuItems so that the test can verify it empties on a failed request.
		wrapper.vm.suggestedUsernames = [ { name: 'testing123123' } ];
		// Call the method under test
		wrapper.vm.onReportedUserInput( '' );
		// The suggetions should be empty for an empty input.
		expect( wrapper.vm.inputReportedUserMenuItems ).toStrictEqual( [] );
	} );

	it( 'Call to onReportedUserInput twice within the debounce period', async () => {
		const apiGet = mockApiGet(
			Promise.resolve(
				{ query: { allusers: [
					{ userid: 1, name: 'testing123' },
					{ userid: 2, name: 'testing1234' },
					{ userid: 3, name: 'testing12345' }
				] } }
			)
		);
		const wrapper = renderComponent();

		// Call the method under test
		wrapper.vm.onReportedUserInput( 'testing12' );
		// Call the method under test again.
		wrapper.vm.onReportedUserInput( 'testing123' );
		// Wait until the debounce time has expired and add around 20ms to be sure it has run.
		await waitUntilDebounceComplete();
		// The suggestions should now be set.
		expect( wrapper.vm.inputReportedUserMenuItems ).toStrictEqual( [
			{ value: 'testing123' },
			{ value: 'testing1234' },
			{ value: 'testing12345' }
		] );
		expect( apiGet ).toHaveBeenCalledWith( {
			action: 'query',
			list: 'allusers',
			auprefix: 'testing123',
			limit: '10'
		} );
	} );
} );
