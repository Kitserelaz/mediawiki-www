'use strict';
// TODO: Include this file in test coverage and test it?
$( () => {
	const Vue = require( 'vue' );
	const App = require( './components/App.vue' );
	const Pinia = require( 'pinia' );
	const pinia = Pinia.createPinia();

	const reportIncidentApp = Vue.createMwApp( App, {} )
		.use( pinia )
		.mount( '#ext-reportincident-app' );

	// eslint-disable-next-line no-jquery/no-global-selector
	$( '.ext-reportincident-link' ).on( 'click', ( event ) => {
		event.preventDefault();
		reportIncidentApp.reportLinkInToolsMenuHandler();
	} );

	mw.hook( 'discussionToolsOverflowMenuOnChoose' )
		.add( reportIncidentApp.discussionToolsOverflowMenuOnChooseHandler );
} );
