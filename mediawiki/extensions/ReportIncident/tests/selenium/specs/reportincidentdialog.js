'use strict';

const assert = require( 'assert' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage.js' ),
	ReportIncidentPage = require( '../pageobjects/reportincident.page' );

describe( 'ReportIncident dialog', () => {
	before( async () => {
		await LoginPage.loginAdmin();
	} );
	beforeEach( async () => {
		await ReportIncidentPage.open( { withconfirmedemail: 1 } );
	} );
	it( 'Should open the dialog if the tools menu is used', async () => {
		// The tools link should exist, otherwise fail.
		assert( await ReportIncidentPage.reportLinkInToolsMenu.isExisting() );
		// Initially the dialog should not be open
		assert.strictEqual( await ReportIncidentPage.reportIncidentDialog.isExisting(), false );
		// Click the report link in the tools menu
		await ReportIncidentPage.reportLinkInToolsMenu.click();
		// The dialog should initially be on step 1.
		assert( await ReportIncidentPage.reportIncidentDialog.isExisting() );
		assert( await ReportIncidentPage.stepOneContent.isExisting() );
		assert( await ReportIncidentPage.dialogFooterNextButton.isExisting() );
		assert( await ReportIncidentPage.dialogFooterBackButton.isExisting() );
	} );
	it( 'Should be able to advance to step 2 and see a form with the expected fields', async () => {
		// Click the report link in the tools menu
		await ReportIncidentPage.reportLinkInToolsMenu.click();
		// Advance to step 2.
		await ReportIncidentPage.dialogFooterNextButton.click();
		// Step two content (which is the form) should exist, along with
		// all the form items.
		assert( await ReportIncidentPage.stepTwoContent.isExisting() );
		assert( await ReportIncidentPage.harassmentOptionsFormFieldset.isExisting() );
		assert( await ReportIncidentPage.hateSpeechOrDiscriminationOption.isExisting() );
		assert( await ReportIncidentPage.sexualHarassmentOption.isExisting() );
		assert( await ReportIncidentPage.threatsOrViolenceOption.isExisting() );
		assert( await ReportIncidentPage.intimidationAggressionOption.isExisting() );
		assert( await ReportIncidentPage.somethingElseOption.isExisting() );
		assert( await ReportIncidentPage.additionalContentFormInput.isExisting() );
		assert( await ReportIncidentPage.violatorFormInput.isExisting() );
	} );
	it( 'Should display form errors when submit attempted with no form data', async () => {
		// Click the report link in the tools menu
		await ReportIncidentPage.reportLinkInToolsMenu.click();
		// Advance to step 2.
		await ReportIncidentPage.dialogFooterNextButton.click();
		// Go back to step 1.
		await ReportIncidentPage.dialogFooterBackButton.click();
		// Advance to step 2.
		await ReportIncidentPage.dialogFooterNextButton.click();
		// Assert the dialog is on step 2.
		assert( await ReportIncidentPage.stepTwoContent.isExisting() );
		// Attempt to submit the form with no data specified.
		await ReportIncidentPage.dialogFooterNextButton.click();
		// Check that the form displays errors on the required fields.
		assert( await ReportIncidentPage.harassmentOptionsFieldsetFormErrors.isExisting() );
		assert( await ReportIncidentPage.violatorFormInputErrors.isExisting() );
		// Assert that the dialog still exists.
		assert( await ReportIncidentPage.reportIncidentDialog.isExisting() );
	} );
	it( 'Should be able to submit a form with valid data', async () => {
		// Click the report link in the tools menu
		await ReportIncidentPage.reportLinkInToolsMenu.click();
		// Advance to step 2.
		await ReportIncidentPage.dialogFooterNextButton.click();
		// Assert the dialog is on step 2.
		assert( await ReportIncidentPage.stepTwoContent.isExisting() );
		// Fill out the form
		// Check some of the harassment options
		await ReportIncidentPage.hateSpeechOrDiscriminationOption.click();
		await ReportIncidentPage.somethingElseOption.click();
		// Wait a bit of time for the something else textarea to appear
		// Something else textbox should appear once something else is checked.
		assert( await ReportIncidentPage.somethingElseTextbox.isExisting() );
		// Add something to the something else textbox
		await ReportIncidentPage.somethingElseTextbox.setValue( 'Testing1234' );
		// Add a username to the violator input
		await ReportIncidentPage.violatorFormInput.setValue( 'Admin' );
		// Add something to the additional details box
		await ReportIncidentPage.additionalContentFormInput.setValue( 'Additional details.' );
		// Listen for the API request
		await browser.setupInterceptor();
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
		assert.strictEqual( request.url, '/rest.php/reportincident/v0/report' );
		// Assert that the request method is POST.
		assert.strictEqual( request.method, 'POST' );
		const requestBody = request.body;
		// Remove the token from our verification as it will be different each time.
		delete requestBody.token;
		// Assert that the request body matches the data entered in the form
		assert.deepStrictEqual(
			request.body,
			{
				reportedUser: 'Admin',
				details: 'Additional details.',
				behaviors: [ 'hate-speech-or-discrimination', 'something-else' ],
				somethingElseDetails: 'Testing1234',
				revisionId: 0
			}
		);
	} );
} );
