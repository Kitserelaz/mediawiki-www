const { setActivePinia, createPinia } = require( 'pinia' );
const useFormStore = require( '../../../resources/ext.reportIncident/stores/Form.js' );
const Constants = require( '../../../resources/ext.reportIncident/Constants.js' );

describe( 'Form Store', () => {
	beforeEach( () => {
		setActivePinia( createPinia() );
	} );

	it( 'validates a form correctly', () => {
		const form = useFormStore();
		expect( form.isFormValidForSubmission() ).toBe( false );

		form.inputBehaviors = [ Constants.harassmentTypes.HATE_SPEECH ];
		form.inputReportedUser = 'test value';

		expect( form.isFormValidForSubmission() ).toBe( true );
	} );

	it( 'resets the form properly on call to $reset', () => {
		const form = useFormStore();
		form.inputBehaviors = [
			Constants.harassmentTypes.HATE_SPEECH, Constants.harassmentTypes.INTIMIDATION_AGGRESSION
		];
		form.inputReportedUser = 'test value';
		form.inputDetails = 'test details';
		form.inputSomethingElseDetails = 'test something else details';
		form.overflowMenuData = { test: 'testing' };
		form.inputReportedUserDisabled = true;
		form.displayReportedUserRequiredError = true;
		form.displaySomethingElseTextboxRequiredError = true;
		form.displayBehaviorsRequiredError = true;
		form.reportedUserDoesNotExist = true;

		form.$reset();
		// Form fields should be empty
		expect( form.inputBehaviors ).toStrictEqual( [] );
		expect( form.inputReportedUser ).toBe( '' );
		expect( form.inputDetails ).toBe( '' );
		expect( form.inputSomethingElseDetails ).toBe( '' );
		// DiscussionTools overflowMenuData should be empty
		expect( form.overflowMenuData ).toStrictEqual( {} );
		// Required field checks should be disabled again (they are enabled on
		// pressing submit or un-focusing that required field).
		expect( form.displayReportedUserRequiredError ).toBe( false );
		expect( form.displaySomethingElseTextboxRequiredError ).toBe( false );
		expect( form.displayBehaviorsRequiredError ).toBe( false );
		// Username field should be un-disabled
		expect( form.inputReportedUserDisabled ).toBe( false );
		// Reported user field shouldn't have the username doesn't exist error
		expect( form.reportedUserDoesNotExist ).toBe( false );
	} );

	it( 'Generates correct rest data', () => {
		const form = useFormStore();
		form.inputBehaviors = [
			Constants.harassmentTypes.HATE_SPEECH, Constants.harassmentTypes.INTIMIDATION_AGGRESSION
		];
		form.inputReportedUser = 'test user';
		form.inputDetails = 'test details';
		form.inputSomethingElseDetails = 'test something else details';

		// Something else details should not be specified as it is not in the behaviours array.
		expect( form.restPayload ).toStrictEqual( {
			reportedUser: 'test user',
			details: 'test details',
			behaviors: [
				Constants.harassmentTypes.HATE_SPEECH, Constants.harassmentTypes.INTIMIDATION_AGGRESSION
			]
		} );

		form.inputBehaviors = [ Constants.harassmentTypes.OTHER ];
		expect( form.restPayload ).toStrictEqual( {
			reportedUser: 'test user',
			details: 'test details',
			behaviors: [ Constants.harassmentTypes.OTHER ],
			somethingElseDetails: 'test something else details'
		} );

		form.overflowMenuData = { 'thread-id': 'c-test_user-20230605040302' };
		expect( form.restPayload ).toStrictEqual( {
			reportedUser: 'test user',
			details: 'test details',
			behaviors: [ Constants.harassmentTypes.OTHER ],
			somethingElseDetails: 'test something else details',
			threadId: 'c-test_user-20230605040302'
		} );
	} );

	it( 'Generates no error messages before user has interacted with the form', () => {
		const form = useFormStore();

		// Test no errors are present before the user has interacted with the form (such
		// as attempting to submit or focusing the required fields).
		expect( form.formErrorMessages ).toStrictEqual( {} );
	} );

	it( 'Generates correct error messages for required fields', () => {
		const form = useFormStore();

		// Test that no error messages are generated when the data is correct
		form.inputBehaviors = [
			Constants.harassmentTypes.HATE_SPEECH, Constants.harassmentTypes.INTIMIDATION_AGGRESSION
		];
		form.inputReportedUser = 'test value';
		form.inputDetails = 'test details';
		form.inputSomethingElseDetails = 'test something else details';
		expect( form.formErrorMessages ).toStrictEqual( {} );

		// Test that emptying all the required fields generates error messages
		form.inputReportedUser = '';
		form.inputBehaviors = [];
		expect( form.formErrorMessages ).toStrictEqual( {
			inputBehaviors: { error: mw.msg( 'reportincident-dialog-harassment-empty' ) },
			inputReportedUser: { error: mw.msg( 'reportincident-dialog-violator-empty' ) }
		} );
	} );

	it( 'Generates something-else error for empty something else textbox', () => {
		const form = useFormStore();
		// Test that emptying the something-else field while 'Something else' is a selected
		// behaviour causes an error
		form.inputBehaviors = [ Constants.harassmentTypes.OTHER ];
		form.inputReportedUser = 'test value';
		form.inputDetails = 'test details';
		form.inputSomethingElseDetails = 'test something else details';
		form.isFormValidForSubmission();
		form.inputSomethingElseDetails = '';
		expect( form.formErrorMessages ).toStrictEqual( {
			inputBehaviors: { error: mw.msg( 'reportincident-dialog-something-else-empty' ) }
		} );
	} );

	it( 'Generates correct error message for non-existent user', () => {
		const form = useFormStore();

		// Test that when reportedUserDoesNotExist is true and inputReportedUser is
		// not an empty string, the error message is displayed.
		form.inputReportedUser = 'test';
		form.reportedUserDoesNotExist = true;
		expect( form.formErrorMessages ).toStrictEqual( {
			inputReportedUser: { error: mw.msg( 'reportincident-dialog-violator-nonexistent' ) }
		} );
	} );
} );
