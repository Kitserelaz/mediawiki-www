'use strict';

const ReportIncidentDialog = require( '../../../resources/ext.reportIncident/components/ReportIncidentDialog.vue' ),
	Constants = require( '../../../resources/ext.reportIncident/Constants.js' ),
	utils = require( '@vue/test-utils' ),
	{ createTestingPinia } = require( '@pinia/testing' ),
	useFormStore = require( '../../../resources/ext.reportIncident/stores/Form.js' );

const steps = {
	[ Constants.DIALOG_STEP_1 ]: '<p>Step 1</p>',
	[ Constants.DIALOG_STEP_2 ]: '<p>Step 2</p>'
};

/**
 * Mocks mw.Rest().post() and returns a jest.fn()
 * that is used as the post() method. This can
 * be used to expect that the post() method is
 * called with the correct arguments.
 *
 * If a function is provided as the returnValue,
 * the return value of that function is used.
 *
 * @param {*} returnValue
 * @return {jest.fn}
 */
function mockRestPost( returnValue ) {
	mw.Rest = () => {};
	const restPost = jest.fn();
	restPost.mockImplementation( () => {
		if ( returnValue instanceof Function ) {
			return returnValue();
		}
		return returnValue;
	} );
	jest.spyOn( mw, 'Rest' ).mockImplementation( () => ( {
		post: restPost
	} ) );
	return restPost;
}

const renderComponent = ( props, slots ) => {
	const defaultProps = { open: false, showPaginator: false };
	const defaultSlots = { title: '<h3>Report Harassment</h3>' };
	return utils.mount( ReportIncidentDialog, {
		global: {
			plugins: [ createTestingPinia( { stubActions: false } ) ]
		},
		props: Object.assign( {}, defaultProps, props ),
		slots: Object.assign( {}, defaultSlots, slots )
	} );
};

describe( 'Report Incident Dialog', () => {
	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it( 'mounts the component', () => {
		const wrapper = renderComponent( { open: true } );
		expect( wrapper.find( '.ext-reportincident-dialog' ).exists() ).toBe( true );
	} );

	it( 'should open the dialog based on "open" prop state', () => {
		const wrapper = renderComponent();
		expect( wrapper.find( '.ext-reportincident-dialog__content' ).exists() ).toBe( false );
		return wrapper.setProps( { open: true } ).then( () => {
			expect( wrapper.find( '.ext-reportincident-dialog__content' ).exists() ).toBe( true );
		} );
	} );

	it( 'should render content passed as step 1 by default', () => {
		const wrapper = renderComponent( { open: true }, steps );
		expect( wrapper.html() ).toContain( 'Step 1' );
		expect( wrapper.text() ).not.toContain( 'Step 2' );
	} );

	describe( 'footer messages', () => {
		it( 'Should display help text only on step 1', () => {
			const wrapper = renderComponent( { open: true } );
			expect( wrapper.vm.showFooterHelpText ).toBe( true );
		} );

		it( 'Should not display form error messages on step 1', () => {
			const wrapper = renderComponent( { open: true } );
			wrapper.vm.footerErrorMessage = 'test';
			expect( wrapper.vm.showFooterErrorText ).toBe( false );
		} );

		it( 'Should display form error messages on step 2', () => {
			const wrapper = renderComponent( { open: true, initialStep: Constants.DIALOG_STEP_2 } );
			wrapper.vm.footerErrorMessage = 'test';
			expect( wrapper.vm.showFooterErrorText ).toBe( true );
		} );

		describe( 'footer server error messages', () => {
			it( 'Should add footer error message on call to onReportSubmitFailure with no data', () => {
				const wrapper = renderComponent( { open: true, initialStep: Constants.DIALOG_STEP_2 } );
				jest.spyOn( navigator, 'onLine', 'get' ).mockReturnValue( true );
				// No JSON in the error object should lead the generic error to display.
				wrapper.vm.onReportSubmitFailure( 'http', {
					xhr: { status: 0 }
				} );
				expect( wrapper.vm.showFooterErrorText ).toBe( true );
				expect( wrapper.vm.footerErrorMessage ).toBe( 'reportincident-dialog-generic-error' );
			} );

			it( 'Should add footer error message on call to onReportSubmitFailure with no data when offline', () => {
				const wrapper = renderComponent( { open: true, initialStep: Constants.DIALOG_STEP_2 } );
				// Mock that navigator.onLine is false.
				jest.spyOn( navigator, 'onLine', 'get' ).mockReturnValue( false );
				wrapper.vm.onReportSubmitFailure( 'http', {
					xhr: { status: 0 }
				} );
				expect( wrapper.vm.showFooterErrorText ).toBe( true );
				// As navigator.onLine is false, the internet disconnected error should be shown
				expect( wrapper.vm.footerErrorMessage ).toBe( 'reportincident-dialog-internet-disconnected-error' );
			} );

			it( 'Should add footer error message on call to onReportSubmitFailure with xhr indicating server error', () => {
				const wrapper = renderComponent( { open: true, initialStep: Constants.DIALOG_STEP_2 } );
				jest.spyOn( navigator, 'onLine', 'get' ).mockReturnValue( true );
				// Set the HTTP status code to 501, which is a 5XX code.
				wrapper.vm.onReportSubmitFailure( 'http', {
					xhr: { status: 501 }
				} );
				expect( wrapper.vm.showFooterErrorText ).toBe( true );
				// As the HTTP status code is 5XX, the server error message should be shown
				expect( wrapper.vm.footerErrorMessage ).toBe( 'reportincident-dialog-server-error' );
			} );

			it( 'Should add form specific error message on call to onReportSubmitFailure with errorKey indicating reported user does not exist', () => {
				const wrapper = renderComponent( { open: true, initialStep: Constants.DIALOG_STEP_2 } );
				const store = useFormStore();
				// The error will cause the field to be un-disabled if it was
				// disabled, so set it to be disabled so that the test can
				// observe a change in value.
				store.inputReportedUserDisabled = true;
				wrapper.vm.onReportSubmitFailure( 'http', {
					xhr: { status: 404, responseJSON: { errorKey: 'reportincident-dialog-violator-nonexistent' } }
				} );
				// This error is shown for the reported user field, so the footer error
				// message should be empty.
				expect( wrapper.vm.showFooterErrorText ).toBe( false );
				expect( wrapper.vm.footerErrorMessage ).toBe( '' );
				// Expect that the error is shown for the reported user field.
				expect( store.reportedUserDoesNotExist ).toBe( true );
				expect( store.inputReportedUserDisabled ).toBe( false );
			} );

			it( 'Should add footer error message on call to onReportSubmitFailure with errorKey that is not otherwise handled', () => {
				const wrapper = renderComponent( { open: true, initialStep: Constants.DIALOG_STEP_2 } );
				wrapper.vm.onReportSubmitFailure( 'http', {
					xhr: { status: 403, responseJSON: { errorKey: 'apierror-permissiondenied' } }
				} );
				// This error is not handled separately, so the generic error should be shown.
				expect( wrapper.vm.showFooterErrorText ).toBe( true );
				expect( wrapper.vm.footerErrorMessage ).toBe( 'reportincident-dialog-generic-error' );
			} );
		} );
	} );

	describe( 'footer navigation', () => {
		beforeEach( () => {
			jest.spyOn( mw.config, 'get' ).mockImplementation( ( key ) => {
				switch ( key ) {
					case 'wgReportIncidentAdministratorsPage':
						return 'Wikipedia:Administrators';
					case 'wgReportIncidentUserHasConfirmedEmail':
						return true;
					case 'wgCurRevisionId':
						return 1;
					default:
						throw new Error( 'Unknown key: ' + key );
				}
			} );
		} );
		it( 'navigates from STEP 1 to STEP 2 when the next button is clicked', () => {
			const wrapper = renderComponent( { open: true } );
			expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_1 );

			return wrapper.get( '.ext-reportincident-dialog-footer__next-btn' ).trigger( 'click' ).then( () => {
				expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_2 );
			} );
		} );

		it( 'navigates from STEP 2 to STEP 1 when the back button is clicked', () => {
			const wrapper = renderComponent( { open: true, initialStep: Constants.DIALOG_STEP_2 } );
			expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_2 );

			return wrapper.get( '.ext-reportincident-dialog-footer__back-btn' ).trigger( 'click' ).then( () => {
				expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_1 );
			} );
		} );

		it( 'Clears any form data if navigating back twice from STEP 2', () => {
			const wrapper = renderComponent( { open: true, initialStep: Constants.DIALOG_STEP_2 } );
			expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_2 );

			const store = useFormStore();

			store.inputBehaviors = [ Constants.harassmentTypes.INTIMIDATION_AGGRESSION ];
			store.inputReportedUser = 'test user';

			return wrapper.get( '.ext-reportincident-dialog-footer__back-btn' ).trigger( 'click' ).then( () => {
				// Clicking back once should put us on STEP 1
				expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_1 );

				wrapper.get( '.ext-reportincident-dialog-footer__back-btn' ).trigger( 'click' ).then( () => {
					// Clicking back should clear the form store data
					// as the dialog was closed.
					expect( store.inputBehaviors ).toHaveLength( 0 );
					expect( store.inputReportedUser ).toBe( '' );
				} );
			} );
		} );

		it( 'attempts to submit form when next is clicked on STEP 2 and has invalid form data', () => {
			const wrapper = renderComponent( { open: true, initialStep: Constants.DIALOG_STEP_2 } );
			expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_2 );

			const store = useFormStore();

			store.inputBehaviors = [ Constants.harassmentTypes.OTHER ];
			expect( store.isFormValidForSubmission() ).toBe( false );

			// Set the footerErrorMessage value as it should be cleared if the
			// client side validation fails after a user presses submit.
			wrapper.vm.footerErrorMessage = 'test';

			return wrapper.get( '.ext-reportincident-dialog-footer__next-btn' ).trigger( 'click' ).then( () => {
				expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_2 );
				expect( wrapper.vm.footerErrorMessage ).toBe( '' );
			} );
		} );

		it( 'attempts to submit form when next is clicked on STEP 2 and has valid form data', () => {
			const wrapper = renderComponent( { open: true, initialStep: Constants.DIALOG_STEP_2 } );
			expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_2 );

			const store = useFormStore();
			const consoleSpy = jest.spyOn( console, 'log' );
			const restPost = mockRestPost( Promise.resolve() );

			store.inputBehaviors = [ Constants.harassmentTypes.HATE_SPEECH ];
			store.inputReportedUser = 'test user';
			expect( store.isFormValidForSubmission() ).toBe( true );

			return wrapper.get( '.ext-reportincident-dialog-footer__next-btn' ).trigger( 'click' ).then( () => {
				// Should be dialog step one if the form submitted correctly.
				expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_1 );
				expect( consoleSpy ).not.toHaveBeenCalled();
				expect( restPost ).toHaveBeenCalledWith(
					'/reportincident/v0/report',
					{
						behaviors: [ Constants.harassmentTypes.HATE_SPEECH ], details: '',
						reportedUser: 'test user', revisionId: 1
					}
				);
			} );
		} );

		it( 'attempts to submit form when next is clicked on STEP 2 and has valid form data in developer mode', () => {
			const wrapper = renderComponent( { open: true, initialStep: Constants.DIALOG_STEP_2 } );
			expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_2 );

			const store = useFormStore();

			const consoleSpy = jest.spyOn( console, 'log' );

			const restPost = mockRestPost(
				Promise.resolve( { sentEmail: {
					to: [ { address: 'test@test.com' }, { address: 'testing@example.com' } ],
					from: { address: 'b@example.com' },
					subject: 'Testing subject',
					body: 'Testing email body.\nTesting.'
				} } )
			);

			store.inputBehaviors = [ Constants.harassmentTypes.INTIMIDATION_AGGRESSION ];
			store.inputReportedUser = 'test user';
			expect( store.isFormValidForSubmission() ).toBe( true );

			return wrapper.get( '.ext-reportincident-dialog-footer__next-btn' ).trigger( 'click' ).then( () => {
				// Should be dialog step one if the form submitted correctly.
				expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_1 );
				// Should have outputted the form data to the console.
				expect( consoleSpy ).toHaveBeenNthCalledWith( 1, 'An email has been sent for this report' );
				expect( consoleSpy ).toHaveBeenNthCalledWith( 2, 'Sent from:\nb@example.com' );
				expect( consoleSpy ).toHaveBeenNthCalledWith( 3, 'Sent to:\ntest@test.com, testing@example.com' );
				expect( consoleSpy ).toHaveBeenNthCalledWith( 4, 'Subject of the email:\nTesting subject' );
				expect( consoleSpy ).toHaveBeenNthCalledWith( 5, 'Body of the email:\nTesting email body.\nTesting.' );
				// Should have called Rest().post()
				expect( restPost ).toHaveBeenCalledWith(
					'/reportincident/v0/report',
					{
						behaviors: [ Constants.harassmentTypes.INTIMIDATION_AGGRESSION ], details: '',
						reportedUser: 'test user', revisionId: 1
					}
				);
			} );
		} );

		it( 'attempts to submit form when next is clicked on STEP 2 and has valid form data', () => {
			const wrapper = renderComponent( { open: true, initialStep: Constants.DIALOG_STEP_2 } );
			expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_2 );

			const store = useFormStore();
			const consoleSpy = jest.spyOn( console, 'log' );
			const userTokensSpy = jest.spyOn( mw.user.tokens, 'get' ).mockImplementation( ( tokenType ) => {
				switch ( tokenType ) {
					case 'csrfToken':
						return 'csrf-token';
					default:
						throw new Error( 'Unknown token type: ' + tokenType );
				}
			} );
			const restPost = mockRestPost( () => {
				// Form should be in submission when the REST API is called.
				expect( wrapper.vm.formSubmissionInProgress ).toBe( true );
				return Promise.resolve();
			} );

			store.inputBehaviors = [ Constants.harassmentTypes.INTIMIDATION_AGGRESSION ];
			store.inputReportedUser = 'test user';
			expect( store.isFormValidForSubmission() ).toBe( true );

			// Form should not be in submission if the form was not submitted yet.
			expect( wrapper.vm.formSubmissionInProgress ).toBe( false );

			return wrapper.get( '.ext-reportincident-dialog-footer__next-btn' ).trigger( 'click' ).then( () => {
				// Should be dialog step one if the form submitted correctly.
				expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_1 );
				// Should not have outputted the form data to the console.
				expect( consoleSpy ).not.toHaveBeenCalled();
				expect( userTokensSpy ).toHaveBeenCalledWith( 'csrfToken' );
				expect( restPost ).toHaveBeenCalledWith(
					'/reportincident/v0/report',
					{
						behaviors: [ Constants.harassmentTypes.INTIMIDATION_AGGRESSION ], details: '',
						reportedUser: 'test user', revisionId: 1, token: 'csrf-token'
					}
				);
				// Form should not be in submission if the form has finished submitting.
				expect( wrapper.vm.formSubmissionInProgress ).toBe( false );
			} );
		} );

		it( 'attempts to submit form when next is clicked on STEP 2 and has valid form data but API rejects in developer mode', () => {
			const wrapper = renderComponent( { open: true, initialStep: Constants.DIALOG_STEP_2 } );
			expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_2 );

			const store = useFormStore();
			const consoleSpy = jest.spyOn( console, 'log' );
			const userTokensSpy = jest.spyOn( mw.user.tokens, 'get' ).mockImplementation( ( tokenType ) => {
				switch ( tokenType ) {
					case 'csrfToken':
						return 'csrf-token';
					default:
						throw new Error( 'Unknown token type: ' + tokenType );
				}
			} );
			const restPost = mockRestPost( () => {
				// Form should be in submission when the REST API is called.
				expect( wrapper.vm.formSubmissionInProgress ).toBe( true );
				return {
					then: ( _resolveHandler, rejectHandler ) => {
						rejectHandler(
							'http',
							{ xhr: { responseJSON: { sentEmail: {
								to: [ { address: 'test@test.com' }, { address: 'testing@example.com' } ],
								from: { address: 'b@example.com' },
								subject: 'Testing subject',
								body: 'Testing email body.\nTesting.'
							} } } }
						);
					}
				};
			} );

			store.inputBehaviors = [ Constants.harassmentTypes.INTIMIDATION_AGGRESSION ];
			store.inputLink = 'test';
			store.inputReportedUser = 'test user';
			expect( store.isFormValidForSubmission() ).toBe( true );

			return wrapper.get( '.ext-reportincident-dialog-footer__next-btn' ).trigger( 'click' ).then( () => {
				// Should be dialog step two as the REST API call returned a rejected promise
				// which indicates a failure.
				expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_2 );
				// Should have outputted the form data to the console.
				expect( consoleSpy ).toHaveBeenNthCalledWith( 1, 'An email has been sent for this report' );
				expect( consoleSpy ).toHaveBeenNthCalledWith( 2, 'Sent from:\nb@example.com' );
				expect( consoleSpy ).toHaveBeenNthCalledWith( 3, 'Sent to:\ntest@test.com, testing@example.com' );
				expect( consoleSpy ).toHaveBeenNthCalledWith( 4, 'Subject of the email:\nTesting subject' );
				expect( consoleSpy ).toHaveBeenNthCalledWith( 5, 'Body of the email:\nTesting email body.\nTesting.' );
				// Should have asked for a CSRF token.
				expect( userTokensSpy ).toHaveBeenCalledWith( 'csrfToken' );
				expect( restPost ).toHaveBeenCalledWith(
					'/reportincident/v0/report',
					{
						behaviors: [ Constants.harassmentTypes.INTIMIDATION_AGGRESSION ], details: '',
						reportedUser: 'test user', revisionId: 1, token: 'csrf-token'
					}
				);
			} );
		} );

		it( 'attempts to submit form when next is clicked on STEP 2 and has valid form data but API rejects', () => {
			const wrapper = renderComponent( { open: true, initialStep: Constants.DIALOG_STEP_2 } );
			expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_2 );

			const store = useFormStore();
			const consoleSpy = jest.spyOn( console, 'log' );
			const userTokensSpy = jest.spyOn( mw.user.tokens, 'get' ).mockImplementation( ( tokenType ) => {
				switch ( tokenType ) {
					case 'csrfToken':
						return 'csrf-token';
					default:
						throw new Error( 'Unknown token type: ' + tokenType );
				}
			} );
			const restPost = mockRestPost( () => {
				// Form should be in submission when the REST API is called.
				expect( wrapper.vm.formSubmissionInProgress ).toBe( true );
				return {
					then: ( _resolveHandler, rejectHandler ) => {
						rejectHandler(
							'http',
							{ xhr: { responseJSON: {} } }
						);
					}
				};
			} );

			store.inputBehaviors = [ Constants.harassmentTypes.INTIMIDATION_AGGRESSION ];
			store.inputReportedUser = 'test user';
			expect( store.isFormValidForSubmission() ).toBe( true );

			expect( wrapper.vm.formSubmissionInProgress ).toBe( false );

			return wrapper.get( '.ext-reportincident-dialog-footer__next-btn' ).trigger( 'click' ).then( () => {
				// Should be dialog step one if the form submitted correctly.
				expect( wrapper.vm.currentSlotName ).toBe( Constants.DIALOG_STEP_2 );
				// Should have outputted the form data to the console.
				expect( consoleSpy ).not.toHaveBeenCalled();
				expect( restPost ).toHaveBeenCalledWith(
					'/reportincident/v0/report',
					{
						behaviors: [ Constants.harassmentTypes.INTIMIDATION_AGGRESSION ], details: '',
						reportedUser: 'test user', revisionId: 1, token: 'csrf-token'
					}
				);
				expect( userTokensSpy ).toHaveBeenCalledWith( 'csrfToken' );
				// Form should not be in submission if the form has finished submitting.
				expect( wrapper.vm.formSubmissionInProgress ).toBe( false );
			} );
		} );
	} );
} );
