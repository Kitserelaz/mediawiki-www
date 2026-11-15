'use strict';

const { storeToRefs } = require( 'pinia' );

const useFormStore = require( '../stores/Form.js' );

/**
 * Additional context for an instrumentation event.
 *
 * @typedef {Object} InteractionData
 * @property {string} [subType]
 * @property {string} [source]
 * @property {string} [context]
 */

/**
 * @callback LogEvent Log an event to the IRS event stream.
 *
 * @param {string} action
 * @param {InteractionData} [data]
 */

/**
 * Lazy singleton instance of the underlying Metrics Platform instrument.
 */
let instrument;

/**
 * Composable to create an event logging function configured to log events to the IRS event stream.
 * Submitted interaction events will have an associated funnel entry token
 * that persists across the flow until the form is submitted or reset.
 *
 * This composable should only be invoked after the Pinia store backing the IRS form
 * has been set up.
 *
 * @return {LogEvent}
 */
const useInstrument = () => {
	if (
		// Disable instrumentation by default pending approval (T372823).
		!mw.config.get( 'wgReportIncidentEnableInstrumentation' ) ||
		// Ignore instrumentation if the user is a tester (T414213)
		mw.config.get( 'wgReportIncidentE2ETesterUsers' ).includes( mw.user.getName() )
	) {
		return () => {};
	}

	// Reuse the underlying Metrics Platform instrument independently of form state
	// to preserve event sequence positions.
	if ( !instrument ) {
		instrument = mw.eventLog.newInstrument(
			'mediawiki.product_metrics.incident_reporting_system_interaction',
			'/analytics/product_metrics/web/base/1.3.0'
		);
	}

	const store = useFormStore();
	const { funnelEntryToken, funnelName } = storeToRefs( store );

	return ( action, data = {} ) => {
		// Generate a new funnel entry token if none was set.
		// This is reset when the form is itself submitted or reset.
		if ( funnelEntryToken.value === '' ) {
			funnelEntryToken.value = mw.user.generateRandomSessionId();
		}

		const interactionData = {
			// eslint-disable-next-line camelcase
			funnel_entry_token: funnelEntryToken.value
		};

		if ( data.subType ) {
			// eslint-disable-next-line camelcase
			interactionData.action_subtype = data.subType;
		}

		if ( data.source ) {
			// eslint-disable-next-line camelcase
			interactionData.action_source = data.source;
		}

		if ( data.context ) {
			// eslint-disable-next-line camelcase
			interactionData.action_context = data.context.slice( 0, 200 );
		}

		if ( funnelName.value ) {
			// eslint-disable-next-line camelcase
			interactionData.funnel_name = funnelName.value;
		}

		instrument.submitInteraction( action, interactionData );
	};
};

module.exports = useInstrument;
