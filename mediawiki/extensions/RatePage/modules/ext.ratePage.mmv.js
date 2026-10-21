$(document).ready( function () {
	function addRpWidget() {
		var title = mw.mmv.viewer.currentImageFileTitle.getPrefixedText();
		if ( title === mw.RatePage.mmvCurrentTitle ) {
			return;
		}

		( new mw.Api() ).post( {
			action: 'query',
			format: 'json',
			titles: title
		} ).done( function ( response ) {
			var id = Object.keys( response.query.pages )[0];
			var text = '<div class="ratepage-embed" data-page-id="' + id + '" data-contest></div>';

			if ( id < 0 ) {
				text = '<div></div>'
			}

			var w = $( text );
			if ( mw.RatePage.mmvCurrentWidget ) {
				mw.RatePage.mmvCurrentWidget.replaceWith( w );
			} else {
				var pBox = $( '.mw-mmv-permission-box' );
				pBox.before( w );
			}

			var starMap = {};
			if ( id >= 0 ) {
				mw.RatePage.initializeTag( w, starMap );
				mw.RatePage.submitStarMap( starMap );
			}

			mw.RatePage.mmvCurrentTitle = title;
			mw.RatePage.mmvCurrentWidget = w;
			mw.RatePage.mmvCurrentStarMap = starMap;
		} );
	}

	function mutationCallback(mutationList, observer) {
		mutationList.forEach((mutation) => {
			mutation.addedNodes.forEach( function (node) {
				if ( node.nodeType === Node.ELEMENT_NODE && node.classList.contains('mw-mmv-wrapper') ) {
					setTimeout(addRpWidget, 500);   // give mmv time to set up or something
				}
			} )
		});
	}

	var observer = new MutationObserver(mutationCallback);
	observer.observe(document.body, {childList: true});
} );
