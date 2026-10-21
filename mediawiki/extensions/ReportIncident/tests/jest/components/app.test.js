'use strict';

const { mockCodePointLength } = require( '../utils.js' );

// Need to run this here as the import of App.vue without
// mediawiki.String defined causes errors in running these tests.
mockCodePointLength();

const Main = require( '../../../resources/ext.reportIncident/components/App.vue' ),
	mount = require( '@vue/test-utils' ).mount,
	{ nextTick } = require( 'vue' ),
	{ createTestingPinia } = require( '@pinia/testing' ),
	{ mockApiGet } = require( '../utils.js' ),
	useFormStore = require( '../../../resources/ext.reportIncident/stores/Form.js' );

const renderComponent = () => mount( Main, {
	global: {
		plugins: [ createTestingPinia( { stubActions: false } ) ]
	}
} );

/**
 * Expects that for a given jest.fn() mock of mw.Api().get()
 * that the parameters to get() are as expected.
 *
 * @param {jest.fn} apiGet
 * @param {string} username
 * @return {*}
 */
function expectApiGetParameters( apiGet, username ) {
	return expect( apiGet ).toHaveBeenCalledWith( {
		action: 'query',
		list: 'allusers',
		aufrom: username,
		auto: username,
		aulimit: '1'
	} );
}

/**
 * Mocks mw.hook().add() for a specific
 * hook name provided in the arguments.
 * Returns the jest.fn() for the add
 * method that can be used to expect that it
 * is called with the appropriate arguments.
 *
 * @param {string} hookName
 * @return {jest.fn}
 */
function mockHookAdd( hookName ) {
	const hookAdd = jest.fn();
	jest.spyOn( mw, 'hook' ).mockImplementation( ( calledWith ) => {
		if ( calledWith === hookName ) {
			return {
				add: hookAdd
			};
		}
	} );
	return hookAdd;
}

/**
 * Mocks mw.util.isIPAddress() and returns the jest.fn()
 * for the isIPAddress method.
 *
 * @param {boolean} returnValue
 * @return {jest.fn}
 */
function mockIsIPAddress( returnValue ) {
	const isIPAddress = jest.fn();
	isIPAddress.mockImplementation( () => returnValue );
	mw.util.isIPAddress = isIPAddress;
	return isIPAddress;
}

describe( 'Main Component Test Suite', () => {
	beforeEach( () => {
		jest.spyOn( mw.config, 'get' ).mockImplementation( ( key ) => {
			switch ( key ) {
				case 'wgReportIncidentAdministratorsPage':
					return 'Wikipedia:Administrators';
				case 'wgReportIncidentUserHasConfirmedEmail':
					return true;
				default:
					throw new Error( 'Unknown key: ' + key );
			}
		} );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it( 'renders correctly', () => {
		const wrapper = renderComponent();
		expect( wrapper.exists() ).toEqual( true );
	} );

	it( 'mounts the report incident dialog on report link click', async () => {
		const wrapper = renderComponent();
		const store = useFormStore();
		// Set DiscussionTools data that will be reset as this link is not comment/topic specific.
		store.overflowMenuData = { test: 'test' };
		store.inputReportedUserDisabled = true;
		store.inputReportedUser = 'test';
		store.displayReportedUserRequiredError = true;
		// Fake that the form was previously successfully submitted.
		store.formSuccessfullySubmitted = true;
		// Fire the handler.
		wrapper.vm.reportLinkInToolsMenuHandler( { preventDefault: jest.fn() } );
		await nextTick();
		expect( wrapper.find( '.ext-reportincident-emaildialog' ).exists() ).toEqual( false );
		expect( wrapper.find( '.ext-reportincident-dialog' ).exists() ).toEqual( true );
		// Expect that data set by a click on the DiscussionTools link is cleared
		expect( store.overflowMenuData ).toStrictEqual( {} );
		expect( store.inputReportedUserDisabled ).toBe( false );
		expect( store.inputReportedUser ).toBe( '' );
		expect( store.displayReportedUserRequiredError ).toBe( false );
		// Expect that the form being successfully submitted is now reset as a new submission is being made
		expect( store.formSuccessfullySubmitted ).toBe( false );
	} );

	it( 'Shows the email dialog on report link click with unconfirmed email', async () => {
		global.mw.config.get = jest.fn();
		global.mw.config.get.mockImplementation( ( key ) => {
			switch ( key ) {
				case 'wgReportIncidentAdministratorsPage':
					return 'Wikipedia:Administrators';
				case 'wgReportIncidentUserHasConfirmedEmail':
					return false;
				default:
					throw new Error( 'Unknown key: ' + key );
			}
		} );
		const wrapper = renderComponent();
		// Fire the handler.
		wrapper.vm.reportLinkInToolsMenuHandler( { preventDefault: jest.fn() } );
		// nextTick call is needed because vuejs doesn't update the
		// DOM immediately.
		await nextTick();
		expect( wrapper.find( '.ext-reportincident-emaildialog' ).exists() ).toEqual( true );
	} );

	it( 'Does nothing when firing discussionToolsOverflowMenuOnChoose for not reportincident menu item', async () => {
		const hookAdd = mockHookAdd( 'discussionToolsOverflowMenuOnChoose' );
		const wrapper = renderComponent();
		expect( hookAdd ).toHaveBeenCalledWith( wrapper.vm.discussionToolsOverflowMenuOnChooseHandler );
		wrapper.vm.discussionToolsOverflowMenuOnChooseHandler( 'test', {}, {} );
		// nextTick call is needed because vuejs doesn't update the
		// DOM immediately.
		await nextTick();
		expect( wrapper.find( '.ext-reportincident-dialog' ).exists() ).toEqual( false );
		expect( wrapper.find( '.ext-reportincident-emaildialog' ).exists() ).toEqual( false );
	} );

	it( 'Opens dialog on call to discussionToolsOverflowMenuOnChooseHandler with no author', async () => {
		const hookAdd = mockHookAdd( 'discussionToolsOverflowMenuOnChoose' );
		const wrapper = renderComponent();
		const store = useFormStore();
		expect( hookAdd ).toHaveBeenCalledWith( wrapper.vm.discussionToolsOverflowMenuOnChooseHandler );
		// Call the discussionToolsOverflowMenuOnChoose hook
		// with the reportincident ID and menu data with a thread-id defined.
		wrapper.vm.discussionToolsOverflowMenuOnChooseHandler(
			'reportincident',
			{
				getData: () => ( { 'thread-id': 'c-1.2.3.4-20230504030201' } )
			},
			{
				author: null
			}
		);
		// nextTick call is needed because vuejs doesn't update the
		// DOM immediately.
		await nextTick();
		expect( wrapper.find( '.ext-reportincident-dialog' ).exists() ).toEqual( true );
		// Assert that the correct data was set by the hook handler which is got from
		// the getData method.
		expect( store.inputReportedUser ).toBe( '' );
		expect( store.overflowMenuData ).toStrictEqual( { 'thread-id': 'c-1.2.3.4-20230504030201' } );
		expect( store.inputReportedUserDisabled ).toBe( false );
	} );

	it( 'Opens dialog on call to discussionToolsOverflowMenuOnChooseHandler with IP author', async () => {
		const hookAdd = mockHookAdd( 'discussionToolsOverflowMenuOnChoose' );
		const isIPAddress = mockIsIPAddress( true );
		const wrapper = renderComponent();
		const store = useFormStore();
		// Test that calling discussionToolsOverflowMenuOnChooseHandler
		// with no defined store.overflowMenuData causes the fields to
		// be reset.
		store.inputBehaviors = [ 'test' ];
		// Expect that the discussionToolsOverflowMenuOnChooseHandler is added
		// to the fires of discussionToolsOverflowMenuOnChoose.
		expect( hookAdd ).toHaveBeenCalledWith( wrapper.vm.discussionToolsOverflowMenuOnChooseHandler );
		// Call the discussionToolsOverflowMenuOnChoose hook
		// with the reportincident ID and menu data with a thread-id defined.
		wrapper.vm.discussionToolsOverflowMenuOnChooseHandler(
			'reportincident',
			{
				getData: () => ( { 'thread-id': 'c-1.2.3.4-20230504030201' } )
			},
			{
				author: '1.2.3.4'
			}
		);
		// nextTick call is needed because vuejs doesn't update the
		// DOM immediately.
		await nextTick();
		expect( wrapper.find( '.ext-reportincident-dialog' ).exists() ).toEqual( true );
		// Assert that the correct data was set by the hook handler which is got from
		// the getData method.
		expect( store.inputReportedUser ).toBe( '1.2.3.4' );
		expect( store.overflowMenuData ).toStrictEqual( { 'thread-id': 'c-1.2.3.4-20230504030201' } );
		await expect( store.inputReportedUserDisabled ).toBe( true );
		// Expect that the behaviours were reset via $reset
		expect( store.inputBehaviors ).toStrictEqual( [] );
		// Expect that mw.util.isIPAddress was called with the correct name
		expect( isIPAddress ).toBeCalledWith( '1.2.3.4' );
	} );

	it( 'Opens dialog on call to discussionToolsOverflowMenuOnChooseHandler with existing user as author', async () => {
		const hookAdd = mockHookAdd( 'discussionToolsOverflowMenuOnChoose' );
		const isIPAddress = mockIsIPAddress( false );
		const wrapper = renderComponent();
		const store = useFormStore();
		expect( hookAdd ).toHaveBeenCalledWith( wrapper.vm.discussionToolsOverflowMenuOnChooseHandler );
		const apiGet = mockApiGet( Promise.resolve(
			{ query: { allusers: [
				{ userid: 1, name: 'testuser' }
			] } }
		) );
		// Call the discussionToolsOverflowMenuOnChoose hook
		// with the reportincident ID and menu data with a thread-id defined.
		wrapper.vm.discussionToolsOverflowMenuOnChooseHandler(
			'reportincident',
			{
				getData: () => ( { 'thread-id': 'c-testuser-20230504030201' } )
			},
			{
				author: 'testuser'
			}
		);
		// nextTick call is needed because vuejs doesn't update the
		// DOM immediately.
		await nextTick();
		expect( wrapper.find( '.ext-reportincident-dialog' ).exists() ).toEqual( true );
		// Assert that the correct data was set by the hook handler which is got from
		// the getData method.
		expect( store.inputReportedUser ).toBe( 'testuser' );
		expect( store.overflowMenuData ).toStrictEqual( { 'thread-id': 'c-testuser-20230504030201' } );
		// Expect that the allusers API was called.
		await expectApiGetParameters( apiGet, 'testuser' );
		expect( store.inputReportedUserDisabled ).toBe( true );
		// Expect that mw.util.isIPAddress was called with the correct name
		expect( isIPAddress ).toBeCalledWith( 'testuser' );
	} );

	it( 'Opens dialog on call to discussionToolsOverflowMenuOnChooseHandler with non-existent user as author', async () => {
		const hookAdd = mockHookAdd( 'discussionToolsOverflowMenuOnChoose' );
		const isIPAddress = mockIsIPAddress( false );
		const wrapper = renderComponent();
		const store = useFormStore();
		const apiGet = mockApiGet( Promise.resolve( { query: { allusers: [] } } ) );
		expect( hookAdd ).toHaveBeenCalledWith( wrapper.vm.discussionToolsOverflowMenuOnChooseHandler );
		// Call the discussionToolsOverflowMenuOnChoose hook
		// with the reportincident ID and menu data with a thread-id defined.
		wrapper.vm.discussionToolsOverflowMenuOnChooseHandler(
			'reportincident',
			{
				getData: () => ( { 'thread-id': 'c-testuser-20230504030201' } )
			},
			{
				author: 'testuser'
			}
		);
		// nextTick call is needed because vuejs doesn't update the
		// DOM immediately.
		await nextTick();
		expect( wrapper.find( '.ext-reportincident-dialog' ).exists() ).toEqual( true );
		// Assert that the correct data was set by the hook handler which is got from
		// the getData method.
		expect( store.inputReportedUser ).toBe( 'testuser' );
		expect( store.overflowMenuData ).toStrictEqual( { 'thread-id': 'c-testuser-20230504030201' } );
		// Expect that the allusers API was called.
		await expectApiGetParameters( apiGet, 'testuser' );
		expect( store.inputReportedUserDisabled ).toBe( false );
		// Expect that mw.util.isIPAddress was called with the correct name
		expect( isIPAddress ).toBeCalledWith( 'testuser' );
	} );

	it( 'Opens dialog on call to discussionToolsOverflowMenuOnChooseHandler with failed allusers API call', async () => {
		const hookAdd = mockHookAdd( 'discussionToolsOverflowMenuOnChoose' );
		const wrapper = renderComponent();
		const isIPAddress = mockIsIPAddress( false );
		const store = useFormStore();
		expect( hookAdd ).toHaveBeenCalledWith( wrapper.vm.discussionToolsOverflowMenuOnChooseHandler );
		const rejectedPromise = Promise.reject( 'test' );
		// Catch the rejected promise in a function that does nothing to
		// allow the tests to run (otherwise they fail with an
		// ERR_UNHANDLED_REJECTION error).
		rejectedPromise.catch( () => {} );
		const apiGet = mockApiGet( rejectedPromise );
		// Call the discussionToolsOverflowMenuOnChoose hook
		// with the reportincident ID and menu data with a thread-id defined.
		wrapper.vm.discussionToolsOverflowMenuOnChooseHandler(
			'reportincident',
			{
				getData: () => ( { 'thread-id': 'c-testuser-20230504030201' } )
			},
			{
				author: 'testuser'
			}
		);
		// nextTick call is needed because vuejs doesn't update the
		// DOM immediately.
		await nextTick();
		expect( wrapper.find( '.ext-reportincident-dialog' ).exists() ).toEqual( true );
		// Assert that the correct data was set by the hook handler which is got from
		// the getData method.
		expect( store.inputReportedUser ).toBe( 'testuser' );
		expect( store.overflowMenuData ).toStrictEqual( { 'thread-id': 'c-testuser-20230504030201' } );
		expect( store.inputReportedUserDisabled ).toBe( false );
		// Expect that the allusers API was called.
		expectApiGetParameters( apiGet, 'testuser' );
		// Expect that mw.util.isIPAddress was called with the correct name
		expect( isIPAddress ).toBeCalledWith( 'testuser' );
	} );

	it( 'Keeps form data on call to discussionToolsOverflowMenuOnChooseHandler for same thread-id', async () => {
		const hookAdd = mockHookAdd( 'discussionToolsOverflowMenuOnChoose' );
		const wrapper = renderComponent();
		const isIPAddress = mockIsIPAddress( false );
		const store = useFormStore();
		expect( hookAdd ).toHaveBeenCalledWith( wrapper.vm.discussionToolsOverflowMenuOnChooseHandler );
		const apiGet = mockApiGet( Promise.resolve() );
		// Define store.overflowMenuData
		store.overflowMenuData = { 'thread-id': 'c-testuser-20230504030201' };
		// Define behaviours
		store.inputBehaviors = [ 'test' ];
		// Call the discussionToolsOverflowMenuOnChoose hook
		// with the reportincident ID and menu data with a thread-id defined
		// that is the same as already in store.overflowMenuData
		wrapper.vm.discussionToolsOverflowMenuOnChooseHandler(
			'reportincident',
			{
				getData: () => ( { 'thread-id': 'c-testuser-20230504030201' } )
			},
			{
				author: 'testuser'
			}
		);
		// nextTick call is needed because vuejs doesn't update the
		// DOM immediately.
		await nextTick();
		expect( wrapper.find( '.ext-reportincident-dialog' ).exists() ).toEqual( true );
		// Assert that the correct data was set by the hook handler which is got from
		// the getData method.
		expect( store.inputReportedUser ).toBe( 'testuser' );
		expect( store.overflowMenuData ).toStrictEqual( { 'thread-id': 'c-testuser-20230504030201' } );
		expect( store.inputReportedUserDisabled ).toBe( false );
		// Assert that the behaviours were not reset
		expect( store.inputBehaviors ).toStrictEqual( [ 'test' ] );
		// Expect that the allusers API was called.
		expectApiGetParameters( apiGet, 'testuser' );
		// Expect that mw.util.isIPAddress was called with the correct name
		expect( isIPAddress ).toBeCalledWith( 'testuser' );
	} );

	it( 'checkUsernameExists rejects on invalid API response', async () => {
		const wrapper = renderComponent();
		const apiGet = mockApiGet( Promise.resolve( { test: 'test' } ) );
		await expect( wrapper.vm.checkUsernameExists( 'testuser2' ) ).rejects.toBeUndefined();
		return expectApiGetParameters( apiGet, 'testuser2' );
	} );

	it( 'checkUsernameExists rejects on rejected API response', async () => {
		const wrapper = renderComponent();
		const rejectedPromise = Promise.reject( 'test' );
		// Catch the rejected promise in a function that does nothing to
		// allow the tests to run (otherwise they fail with an
		// ERR_UNHANDLED_REJECTION error).
		rejectedPromise.catch( () => {} );
		const apiGet = mockApiGet( rejectedPromise );
		await expect( wrapper.vm.checkUsernameExists( 'testuser3' ) ).rejects.toBeUndefined();
		return expectApiGetParameters( apiGet, 'testuser3' );
	} );
} );
