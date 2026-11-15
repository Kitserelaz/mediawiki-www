const status = document.getElementById( 'skywiki-creation-status' );
const wikiLink = document.getElementById( 'skywiki-creation-link' );

if ( status && wikiLink && window.EventSource ) {
	const events = new EventSource( mw.config.get( 'wgSkyWikiCreationEvents' ) );
	const delayNotice = window.setTimeout( () => {
		status.textContent = mw.msg( 'createnewwiki-progress-delayed' );
	}, 30000 );

	events.addEventListener( 'ready', () => {
		window.clearTimeout( delayNotice );
		events.close();
		window.location.replace( wikiLink.href );
	} );
	events.addEventListener( 'expired', () => {
		window.clearTimeout( delayNotice );
		events.close();
		status.textContent = mw.msg( 'createnewwiki-progress-unavailable' );
	} );
}
