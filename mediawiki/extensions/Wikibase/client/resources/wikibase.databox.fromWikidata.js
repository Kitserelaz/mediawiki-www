'use strict';
/**
 * Add code for analytic instruments to the "From Wikidata" links in Databox
 * note that the class databox-from-wikidata-link in the module is only
 * used for this tracking so could be removed after
 *
 * Bug: T408709
 *
 * @license GPL-2.0-or-later
 */
(
	function () {
		/**
		 * @param {jQuery} $content
		 * @param {mw.testKitchen.InstrumentInterface} instrument
		 */
		function addFromWikidataTracking( $content, instrument ) {
			const $fromWikidataLink = $content.find( '.databox-from-wikidata-link' );

			$fromWikidataLink.on( 'click', () => {
				instrument.send( 'click', {
					// eslint-disable-next-line camelcase
					action_source: 'wbFromWikidataClick'
				} );
			} );
		}

		/**
		 * Adds temporary tracking for user interactions
		 *
		 * @param {jQuery} $content
		 */
		function addFromWikidataInstrument( $content ) {
			/** @type {mw.testKitchen.InstrumentInterface|undefined} */
			const fromWikidataLinkInstrument = mw.testKitchen && mw.testKitchen.getInstrument( 'databox-click-tracker' );

			if ( fromWikidataLinkInstrument && fromWikidataLinkInstrument.isInSample() ) {
				addFromWikidataTracking( $content, fromWikidataLinkInstrument );
			}
		}

		mw.hook( 'wikipage.content' ).add( addFromWikidataInstrument );
	}()
);
