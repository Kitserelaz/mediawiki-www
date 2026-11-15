const { setActivePinia, createPinia } = require( 'pinia' );
const useInstrument = require( '../../resources/ext.reportIncident/composables/useInstrument.js' );
const useFormStore = require( '../../resources/ext.reportIncident/stores/Form.js' );

describe( 'useInstrument', () => {
	let submitInteraction;
	let newInstrument;

	beforeAll( () => {
		submitInteraction = jest.fn();
		newInstrument = jest.fn( () => ( {
			submitInteraction
		} ) );
		mw.eventLog = { newInstrument };
	} );

	beforeEach( () => {
		setActivePinia( createPinia() );
		mw.user.getName = () => 'Foo';
	} );

	afterEach( () => {
		jest.resetAllMocks();
		delete mw.user.getName;
	} );

	it( 'should record events with new funnel entry token if enabled', () => {
		const mwConfigGet = jest.spyOn( mw.config, 'get' ).mockImplementation( ( key ) => {
			switch ( key ) {
				case 'wgReportIncidentEnableInstrumentation':
					return true;
				case 'wgReportIncidentE2ETesterUsers':
					return [];
				case 'wgReportIncidentEnabledNonEmergencyCategories':
					return [
						'INTIMIDATION',
						'SEXUAL_HARASSMENT',
						'DOXING',
						'TROLLING',
						'HATE_SPEECH',
						'SPAM',
						'SOMETHING_ELSE'
					];
				default:
					return true;
			}
		} );
		const mwName = jest.spyOn( mw.user, 'getName' );
		const store = useFormStore();

		const logEvent = useInstrument();

		logEvent( 'view' );
		logEvent( 'click', { context: 'something' } );
		logEvent( 'click', { source: 'form', subType: 'foo', context: 'something' } );

		expect( mwName ).toHaveBeenCalledTimes( 1 );

		expect( typeof ( store.funnelEntryToken ) ).toEqual( 'string' );
		expect( store.funnelEntryToken.length ).toBeGreaterThan( 0 );

		expect( mwConfigGet ).toHaveBeenCalledTimes( 3 );
		expect( mwConfigGet ).toHaveBeenCalledWith( 'wgReportIncidentEnableInstrumentation' );

		expect( newInstrument ).toHaveBeenCalledTimes( 1 );
		expect( newInstrument ).toHaveBeenCalledWith(
			'mediawiki.product_metrics.incident_reporting_system_interaction',
			'/analytics/product_metrics/web/base/1.3.0'
		);

		expect( submitInteraction ).toHaveBeenCalledTimes( 3 );
		expect( submitInteraction ).toHaveBeenNthCalledWith( 1, 'view', {
			// eslint-disable-next-line camelcase
			funnel_entry_token: store.funnelEntryToken
		} );
		expect( submitInteraction ).toHaveBeenNthCalledWith( 2, 'click', {
			// eslint-disable-next-line camelcase
			action_context: 'something',
			// eslint-disable-next-line camelcase
			funnel_entry_token: store.funnelEntryToken
		} );
		expect( submitInteraction ).toHaveBeenNthCalledWith( 3, 'click', {
			// eslint-disable-next-line camelcase
			action_source: 'form',
			// eslint-disable-next-line camelcase
			action_subtype: 'foo',
			// eslint-disable-next-line camelcase
			action_context: 'something',
			// eslint-disable-next-line camelcase
			funnel_entry_token: store.funnelEntryToken
		} );
	} );

	it( 'should reuse preexisting funnel entry token', () => {
		jest.spyOn( mw.config, 'get' ).mockImplementation( ( key ) => {
			switch ( key ) {
				case 'wgReportIncidentEnableInstrumentation':
					return true;
				case 'wgReportIncidentE2ETesterUsers':
					return [];
				case 'wgReportIncidentEnabledNonEmergencyCategories':
					return [
						'INTIMIDATION',
						'SEXUAL_HARASSMENT',
						'DOXING',
						'TROLLING',
						'HATE_SPEECH',
						'SPAM',
						'SOMETHING_ELSE'
					];
				default:
					return true;
			}
		} );
		const store = useFormStore();
		store.funnelEntryToken = 'test';

		const logEvent = useInstrument();

		logEvent( 'view' );

		expect( submitInteraction ).toHaveBeenCalledTimes( 1 );
		expect( submitInteraction ).toHaveBeenCalledWith( 'view', {
			// eslint-disable-next-line camelcase
			funnel_entry_token: 'test'
		} );
	} );

	it( 'should generate new funnel entry token if reset after setup', () => {
		jest.spyOn( mw.config, 'get' ).mockImplementation( ( key ) => {
			switch ( key ) {
				case 'wgReportIncidentEnableInstrumentation':
					return true;
				case 'wgReportIncidentE2ETesterUsers':
					return [];
				case 'wgReportIncidentEnabledNonEmergencyCategories':
					return [
						'INTIMIDATION',
						'SEXUAL_HARASSMENT',
						'DOXING',
						'TROLLING',
						'HATE_SPEECH',
						'SPAM',
						'SOMETHING_ELSE'
					];
				default:
					return true;
			}
		} );
		const store = useFormStore();
		store.funnelEntryToken = 'test';

		const logEvent = useInstrument();

		store.funnelEntryToken = '';

		logEvent( 'view' );

		expect( typeof ( store.funnelEntryToken ) ).toEqual( 'string' );
		expect( store.funnelEntryToken.length ).toBeGreaterThan( 0 );

		expect( submitInteraction ).toHaveBeenCalledTimes( 1 );
		expect( submitInteraction ).toHaveBeenCalledWith( 'view', {
			// eslint-disable-next-line camelcase
			funnel_entry_token: store.funnelEntryToken
		} );
	} );

	it( 'should provide funnel name if set', () => {
		jest.spyOn( mw.config, 'get' ).mockImplementation( ( key ) => {
			switch ( key ) {
				case 'wgReportIncidentEnableInstrumentation':
					return true;
				case 'wgReportIncidentE2ETesterUsers':
					return [];
				case 'wgReportIncidentEnabledNonEmergencyCategories':
					return [
						'INTIMIDATION',
						'SEXUAL_HARASSMENT',
						'DOXING',
						'TROLLING',
						'HATE_SPEECH',
						'SPAM',
						'SOMETHING_ELSE'
					];
				default:
					return true;
			}
		} );
		const store = useFormStore();
		store.funnelName = 'test';

		const logEvent = useInstrument();

		logEvent( 'view' );

		expect( submitInteraction ).toHaveBeenCalledTimes( 1 );
		expect( submitInteraction ).toHaveBeenCalledWith( 'view', {
			// eslint-disable-next-line camelcase
			funnel_entry_token: store.funnelEntryToken,
			// eslint-disable-next-line camelcase
			funnel_name: 'test'
		} );
	} );

	it( 'should reuse instrument between calls', () => {
		jest.spyOn( mw.config, 'get' ).mockImplementation( ( key ) => {
			switch ( key ) {
				case 'wgReportIncidentEnableInstrumentation':
					return true;
				case 'wgReportIncidentE2ETesterUsers':
					return [];
				case 'wgReportIncidentEnabledNonEmergencyCategories':
					return [
						'INTIMIDATION',
						'SEXUAL_HARASSMENT',
						'DOXING',
						'TROLLING',
						'HATE_SPEECH',
						'SPAM',
						'SOMETHING_ELSE'
					];
				default:
					return true;
			}
		} );

		useInstrument();
		useInstrument();

		expect( newInstrument.mock.calls.length ).toBeLessThanOrEqual( 1 );
	} );

	it( 'should truncate overly long context values', () => {
		jest.spyOn( mw.config, 'get' ).mockImplementation( ( key ) => {
			switch ( key ) {
				case 'wgReportIncidentEnableInstrumentation':
					return true;
				case 'wgReportIncidentE2ETesterUsers':
					return [];
				case 'wgReportIncidentEnabledNonEmergencyCategories':
					return [
						'INTIMIDATION',
						'SEXUAL_HARASSMENT',
						'DOXING',
						'TROLLING',
						'HATE_SPEECH',
						'SPAM',
						'SOMETHING_ELSE'
					];
				default:
					return true;
			}
		} );

		const logEvent = useInstrument();
		const store = useFormStore();

		logEvent( 'view', { context: 'test' } );
		logEvent( 'view', { context: 'e'.repeat( 512 ) } );

		expect( submitInteraction ).toHaveBeenCalledTimes( 2 );
		expect( submitInteraction ).toHaveBeenNthCalledWith( 1, 'view', {
			// eslint-disable-next-line camelcase
			action_context: 'test',
			// eslint-disable-next-line camelcase
			funnel_entry_token: store.funnelEntryToken
		} );
		expect( submitInteraction ).toHaveBeenNthCalledWith( 2, 'view', {
			// eslint-disable-next-line camelcase
			action_context: 'e'.repeat( 200 ),
			// eslint-disable-next-line camelcase
			funnel_entry_token: store.funnelEntryToken
		} );
	} );

	it( 'should not record events if user is considered a test user', () => {
		const mwConfigGet = jest.spyOn( mw.config, 'get' ).mockImplementation( ( key ) => {
			switch ( key ) {
				case 'wgReportIncidentEnableInstrumentation':
					return true;
				case 'wgReportIncidentE2ETesterUsers':
					return [ 'Foo' ];
				case 'wgReportIncidentEnabledNonEmergencyCategories':
					return [
						'INTIMIDATION',
						'SEXUAL_HARASSMENT',
						'DOXING',
						'TROLLING',
						'HATE_SPEECH',
						'SPAM',
						'SOMETHING_ELSE'
					];
				default:
					return true;
			}
		} );

		const store = useFormStore();

		const logEvent = useInstrument();

		logEvent( 'view' );

		expect( store.funnelEntryToken ).toBe( '' );

		expect( mwConfigGet ).toHaveBeenCalledTimes( 3 );
		expect( mwConfigGet ).toHaveBeenCalledWith( 'wgReportIncidentEnableInstrumentation' );
		expect( mwConfigGet ).toHaveBeenCalledWith( 'wgReportIncidentE2ETesterUsers' );

		expect( newInstrument ).not.toHaveBeenCalled();
		expect( submitInteraction ).not.toHaveBeenCalled();
	} );

	it( 'should not record events if not enabled', () => {
		const mwConfigGet = jest.spyOn( mw.config, 'get' ).mockImplementation( ( key ) => {
			switch ( key ) {
				case 'wgReportIncidentEnableInstrumentation':
					return false;
				case 'wgReportIncidentE2ETesterUsers':
					return [];
				case 'wgReportIncidentEnabledNonEmergencyCategories':
					return [
						'INTIMIDATION',
						'SEXUAL_HARASSMENT',
						'DOXING',
						'TROLLING',
						'HATE_SPEECH',
						'SPAM',
						'SOMETHING_ELSE'
					];
				default:
					return true;
			}
		} );
		const store = useFormStore();

		const logEvent = useInstrument();

		logEvent( 'view' );

		expect( store.funnelEntryToken ).toBe( '' );

		expect( mwConfigGet ).toHaveBeenCalledTimes( 2 );
		expect( mwConfigGet ).toHaveBeenCalledWith( 'wgReportIncidentEnableInstrumentation' );

		expect( newInstrument ).not.toHaveBeenCalled();
		expect( submitInteraction ).not.toHaveBeenCalled();
	} );
} );
