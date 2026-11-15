import ReportIncidentPage from '../pageobjects/reportincident.page.js';
import { createApiClient } from 'wdio-mediawiki/Api';
import LoginPage from 'wdio-mediawiki/LoginPage';
import { getTestString } from 'wdio-mediawiki/Util';

describe( 'ReportIncident dialog', () => {
	const waitOpts = { timeout: 30000 };

	let apiClient;
	let userName;
	let reportUrl;
	let userPassword;

	const navigateToUserPage = async () => {
		await ReportIncidentPage.open(
			userName,
			{ withconfirmedemail: 1 }
		);
	};

	const sleep = ( ms ) => {
		let callback;
		const p = new Promise( ( resolve ) => {
			callback = resolve;
		} );

		setTimeout( callback, ms );
		return p;
	};

	// Create a new user and log in with it before the test suite runs
	before( async () => {
		const MW_SCRIPT_PATH = ( 'MW_SCRIPT_PATH' in process.env ?
			process.env.MW_SCRIPT_PATH :
			''
		);
		const baseUri = ( MW_SCRIPT_PATH.endsWith( '/' ) ?
			MW_SCRIPT_PATH :
			`${ MW_SCRIPT_PATH }/`
		);

		userPassword = getTestString();
		userName = getTestString( 'Report-incident-' );
		apiClient = await createApiClient();

		await apiClient.createAccount( userName, userPassword );
		await LoginPage.login( userName, userPassword );

		reportUrl = `${ baseUri }rest.php/reportincident/v0/report`;
	} );

	// Navigate to the newly-created user page before each test
	beforeEach( async () => await navigateToUserPage() );

	it( 'Should open the dialog if the tools menu is used', async () => {
		// Click the "More" menu containing the "Report incident" link
		await ReportIncidentPage.actionsMenu.click();
		await ReportIncidentPage.reportLinkInToolsMenu.waitForDisplayed( waitOpts );
		// The tools link should exist, otherwise fail.
		await expect( ReportIncidentPage.reportLinkInToolsMenu ).toExist();
		// Initially the dialog should not be open
		await expect( ReportIncidentPage.reportIncidentDialog ).not.toExist();

		// Click the report link in the tools menu
		await ReportIncidentPage.reportLinkInToolsMenu.click();
		await ReportIncidentPage.reportIncidentDialog.waitForDisplayed( waitOpts );
		await expect( ReportIncidentPage.reportIncidentDialog ).toExist();

		// The dialog should initially be on step 1.
		await expect( ReportIncidentPage.stepOneContent ).toExist();
		await expect( ReportIncidentPage.dialogFooterNextButton ).toExist();
		await expect( ReportIncidentPage.dialogFooterBackButton ).toExist();
	} );
	it( 'Should be able to advance to step 2 and see a form with the expected fields', async () => {
		// Click the "More" menu containing the "Report incident" link
		await ReportIncidentPage.actionsMenu.click();
		await ReportIncidentPage.reportLinkInToolsMenu.waitForDisplayed( waitOpts );

		// Click the report link in the tools menu
		await ReportIncidentPage.reportLinkInToolsMenu.click();
		await ReportIncidentPage.reportIncidentDialog.waitForDisplayed( waitOpts );
		await expect( ReportIncidentPage.reportIncidentDialog ).toExist();

		// Select "Unacceptable user behavior"
		await ReportIncidentPage.dialogUnacceptableBehaviorsButton.click();
		// Advance to step 2.
		await ReportIncidentPage.dialogFooterNextButton.click();
		// Step two content for types of behavior should exist, along with
		// all the form items.
		await expect( ReportIncidentPage.typesOfBehviorScreenContent ).toExist();
		await expect( ReportIncidentPage.harassmentOptionsFormFieldset ).toExist();
		await expect( ReportIncidentPage.hateSpeechOrDiscriminationOption ).toExist();
		await expect( ReportIncidentPage.sexualHarassmentOption ).toExist();
		await expect( ReportIncidentPage.trollingOption ).toExist();
		await expect( ReportIncidentPage.intimidationOption ).toExist();
		await expect( ReportIncidentPage.somethingElseOption ).toExist();

		// A textarea for additional details should not be shown, since the
		// "Something else" radio button is not selected
		await expect( ReportIncidentPage.somethingElseTextbox ).not.toExist();

		// Selecting an option other than "Something else" should not show the
		// "Something else" textbox
		await ReportIncidentPage.intimidationOption.click();
		await expect( ReportIncidentPage.somethingElseTextbox ).not.toExist();
	} );
	it( 'Should ask for additional details only when selecting "Something else"', async () => {
		// Click the "More" menu containing the "Report incident" link
		await ReportIncidentPage.actionsMenu.click();
		await ReportIncidentPage.reportLinkInToolsMenu.waitForDisplayed( waitOpts );

		// Click the report link in the tools menu
		await ReportIncidentPage.reportLinkInToolsMenu.click();
		await ReportIncidentPage.reportIncidentDialog.waitForDisplayed( waitOpts );
		await expect( ReportIncidentPage.reportIncidentDialog ).toExist();

		// Select "Unacceptable user behavior"
		await ReportIncidentPage.dialogUnacceptableBehaviorsButton.click();
		// Advance to step 2.
		await ReportIncidentPage.dialogFooterNextButton.click();
		// Step two content for types of behavior should exist, along with
		// all the form items.
		await expect( ReportIncidentPage.typesOfBehviorScreenContent ).toExist();
		await expect( ReportIncidentPage.harassmentOptionsFormFieldset ).toExist();
		await expect( ReportIncidentPage.hateSpeechOrDiscriminationOption ).toExist();
		await expect( ReportIncidentPage.sexualHarassmentOption ).toExist();
		await expect( ReportIncidentPage.trollingOption ).toExist();
		await expect( ReportIncidentPage.intimidationOption ).toExist();
		await expect( ReportIncidentPage.somethingElseOption ).toExist();

		// A textarea for additional details should not be shown, since the
		// "Something else" radio button is not selected
		await expect( ReportIncidentPage.somethingElseTextbox ).not.toExist();

		// Selecting the "Something else" option should show the associated textbox
		await ReportIncidentPage.somethingElseOption.click();
		await expect( ReportIncidentPage.somethingElseTextbox ).toExist();

		// The non-emergency flow does not collect the 'user reported' anymore
		await expect( ReportIncidentPage.violatorFormInput ).not.toExist();
	} );
	it( 'Should display form errors when submit attempted with no form data', async () => {
		// Click the "More" menu containing the "Report incident" link
		await ReportIncidentPage.actionsMenu.click();
		await ReportIncidentPage.reportLinkInToolsMenu.waitForDisplayed( waitOpts );

		// Click the report link in the tools menu
		await ReportIncidentPage.reportLinkInToolsMenu.click();
		await ReportIncidentPage.reportIncidentDialog.waitForDisplayed( waitOpts );
		await expect( ReportIncidentPage.reportIncidentDialog ).toExist();

		// Select "Unacceptable user behavior" & advance to step 2
		await ReportIncidentPage.dialogUnacceptableBehaviorsButton.click();
		await ReportIncidentPage.dialogFooterNextButton.click();
		// Go back to step 1.
		await ReportIncidentPage.dialogFooterBackButton.click();
		// Advance to step 2.
		await ReportIncidentPage.dialogFooterNextButton.click();
		// Assert the dialog is on the list of unacceptable behaviors
		await expect( ReportIncidentPage.typesOfBehviorScreenContent ).toExist();

		// Attempt to submit the form with no data specified.
		await ReportIncidentPage.dialogFooterNextButton.click();
		// Check that the form displays errors on the required fields.
		await expect( ReportIncidentPage.harassmentOptionsFieldsetFormErrors ).toExist();

		// The non-emergency flow does not collect the 'user reported' anymore
		await expect( ReportIncidentPage.violatorFormInputErrors ).not.toExist();
		// Assert that the dialog still exists.
		await expect( ReportIncidentPage.reportIncidentDialog ).toExist();
	} );
	it( 'Should be able to submit a form with valid data', async () => {
		// Click the "More" menu containing the "Report incident" link
		await ReportIncidentPage.actionsMenu.click();
		await ReportIncidentPage.reportLinkInToolsMenu.waitForDisplayed( waitOpts );

		// Click the report link in the tools menu
		await ReportIncidentPage.reportLinkInToolsMenu.click();
		await ReportIncidentPage.reportIncidentDialog.waitForDisplayed( waitOpts );

		// Select "Unacceptable user behavior" & advance to step 2
		await ReportIncidentPage.dialogUnacceptableBehaviorsButton.click();
		await ReportIncidentPage.dialogFooterNextButton.click();
		// Assert the dialog is on the list of unacceptable behaviors
		await expect( ReportIncidentPage.typesOfBehviorScreenContent ).toExist();

		// Fill out the form
		// Check hate speech from the harassment options, then switch to something else
		await ReportIncidentPage.hateSpeechOrDiscriminationOption.click();
		await ReportIncidentPage.somethingElseOption.click();

		// Something else textbox should appear once something else is checked.
		await expect( ReportIncidentPage.somethingElseTextbox ).toExist();
		// Add something to the something else textbox
		await ReportIncidentPage.somethingElseTextbox.setValue( 'Testing1234' );

		// We don't have a field to provide the 'user reported' in the non-emergency
		// flow (it is collected only when filling the report against a given thread).
		await expect( ReportIncidentPage.violatorFormInput ).not.toExist();

		// Listen for the API request
		await browser.setupInterceptor();

		// Go to the "Get support" screen, which also triggers the API request
		await ReportIncidentPage.dialogFooterNextButton.click();

		// Attempt to submit the form
		await ReportIncidentPage.dialogFooterNextButton.click();

		// Wait until the request is started before verifying the request
		// body, url and method.
		await browser.waitUntil(
			async () => ( await browser.getRequests( { includePending: true } ) ).length !== 0,
			{ timeout: 2 * 1000, timeoutMsg: 'API was not called in a reasonable time.' }
		);

		// Get the REST API request information (which will be the request at index 0
		// as no other requests are made in the interim).
		const request = await browser.getRequest( 0, { includePending: true } );

		// Assert that the URL in the request goes to the REST API for submitting report data
		await expect( request.url ).toBe( reportUrl );

		// Assert that the request method is POST.
		await expect( request.method ).toBe( 'POST' );
		const requestBody = request.body;
		// Remove the token from our verification as it will be different each time.
		delete requestBody.token;
		// Assert that the request body matches the data entered in the form
		await expect( request.body ).toStrictEqual( {
			reportedUser: '',
			incidentType: 'unacceptable-user-behavior',
			behaviorType: 'something-else',
			somethingElseDetails: 'Testing1234',
			page: `User_talk:${ userName }`,
			revisionId: 0
		} );
	} );

	it( 'Should be able to fill a report from a thread', async () => {
		const now = new Date();
		await apiClient.edit(
			`User talk:${ userName }`,
			'== This is a test thread ==\n\nThis is the thread text ~~~ ' +
			`${ now.toTimeString().slice( 0, 5 ) }, ${
				now.getUTCDate() } ${
				now.toLocaleString( 'en', { month: 'long' } )
			} ${ now.getUTCFullYear() } (UTC)`,
			'Edit from test'
		);

		// Login as Admin again and go back to the talk page, otherwise
		// the thread options button is not shown
		await LoginPage.loginAdmin();
		await navigateToUserPage();

		// Wait for the page to load, showing the "ellipsis" button next to the
		// thread in the talk page that we've just updated. After that, wait a
		// bit more so the browser has a chance to finish the execution of
		// listeners; otherwise, it may happen that the click in the Report link
		// does not trigger the report flow.
		await ReportIncidentPage.threadOptions.waitForDisplayed( waitOpts );
		await sleep( 500 );

		// Click on the "three dots" menu to show the options menu popup, then
		// wait for the option to report a thread to be displayed.
		await ReportIncidentPage.threadOptions.click();
		await ReportIncidentPage.reportLink.waitForDisplayed( waitOpts );

		// Open the "Report Incident" dialog.
		await ReportIncidentPage.reportLink.click();
		await expect( ReportIncidentPage.reportIncidentDialog ).toExist();

		// Ensure we can fill a report when triggered from the link associated
		// with a given thread. Next steps are mostly the same as in the
		// previous test.

		// Select "Unacceptable user behavior" & advance to step 2
		await ReportIncidentPage.dialogUnacceptableBehaviorsButton.click();
		await ReportIncidentPage.dialogFooterNextButton.click();
		// Assert the dialog is on the list of unacceptable behaviors
		await expect( ReportIncidentPage.typesOfBehviorScreenContent ).toExist();

		// Fill out the form
		// Check hate speech from the harassment options, then switch to something else
		await ReportIncidentPage.hateSpeechOrDiscriminationOption.click();
		await ReportIncidentPage.somethingElseOption.click();

		// Something else textbox should appear once something else is checked.
		await expect( ReportIncidentPage.somethingElseTextbox ).toExist();
		// Add something to the something else textbox
		await ReportIncidentPage.somethingElseTextbox.setValue( 'Testing456' );

		// Listen for the API request
		await browser.setupInterceptor();

		// Go to the "Get support" screen, which also triggers the API request
		await ReportIncidentPage.dialogFooterNextButton.click();

		// Attempt to submit the form
		await ReportIncidentPage.dialogFooterNextButton.click();

		// Wait until the request is started before verifying the request
		// body, url and method.
		await browser.waitUntil(
			async () => ( await browser.getRequests( { includePending: true } ) ).length !== 0,
			{ timeout: 2 * 1000, timeoutMsg: 'API was not called in a reasonable time.' }
		);

		// Get the REST API request information (which will be the request at index 0
		// as no other requests are made in the interim).
		const request = await browser.getRequest( 0, { includePending: true } );

		// Assert that the URL in the request goes to the REST API for submitting report data
		await expect( request.url ).toBe( reportUrl );

		// Assert that the request method is POST.
		await expect( request.method ).toBe( 'POST' );
		const requestBody = request.body;

		// Check that a revision and threadId are provided
		expect( requestBody ).toHaveProperty( 'revisionId' );
		expect( requestBody ).toHaveProperty( 'threadId' );
		expect( requestBody.revisionId ).toBeGreaterThan( 0 );
		expect( requestBody.threadId.length ).toBeGreaterThan( 0 );

		// Remove the token, revisionId and threadId as they will be different each time.
		delete requestBody.token;
		delete requestBody.revisionId;
		delete requestBody.threadId;

		// Assert that the request body matches the data entered in the form
		await expect( requestBody ).toStrictEqual( {
			// The thread was created by the bot, therefore the reportedUser
			// should match the login it uses to make edits.
			reportedUser: browser.options.capabilities[ 'mw:user' ],
			incidentType: 'unacceptable-user-behavior',
			behaviorType: 'something-else',
			somethingElseDetails: 'Testing456',
			page: `User_talk:${ userName }`
		} );

		// Assert that we are at the last step of the report flow
		await ReportIncidentPage.successfulSubmissionSectionHeader.waitForDisplayed( waitOpts );
	} );
} );
