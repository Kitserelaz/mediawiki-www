'use strict';
// TODO: Include this file in test coverage and test it?
$( () => {
	const Vue = require( 'vue' );
	const App = require( './components/App.vue' );
	const SuccessConfirmationBanner = require( './components/SuccessConfirmationBanner.vue' );
	const Pinia = require( 'pinia' );
	const pinia = Pinia.createPinia();

	Vue.createMwApp( App )
		.use( pinia )
		.mount( '#ext-reportincident-app' );

	const $successConfirmationBanner = $( '<div>' );
	$successConfirmationBanner.attr( 'id', 'ext-reportincident-successconfirmation' );
	// eslint-disable-next-line no-jquery/no-global-selector,no-jquery/no-class-state
	if ( $( 'body' ).hasClass( 'skin-timeless' ) ) {
		// The Timeless skin does not have a sitenotice in the correct place
		// so place the confirmation banner in the #mw-content div
		// eslint-disable-next-line no-jquery/no-global-selector
		$( '#mw-content' ).prepend( $successConfirmationBanner );
	} else {
		// eslint-disable-next-line no-jquery/no-global-selector
		$( '#siteNotice' ).append( $successConfirmationBanner );
	}

	Vue.createMwApp( SuccessConfirmationBanner )
		.use( pinia )
		.mount( '#ext-reportincident-successconfirmation' );
} );
