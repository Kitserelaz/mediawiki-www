( function () {
	'use strict';

	const icons = new Map( [
		[ 'games', 'gamepad' ],
		[ 'animemanga', 'heart' ],
		[ 'moviestv', 'film' ],
		[ 'music', 'music' ],
		[ 'booksliterature', 'book' ],
		[ 'technology', 'computer' ],
		[ 'science', 'flask' ],
		[ 'history', 'landmark-dome' ],
		[ 'geographytravel', 'map' ],
		[ 'education', 'school' ],
		[ 'artdesign', 'paintbrush' ],
		[ 'sport', 'baseball' ],
		[ 'communitiesfandoms', 'comments' ],
		[ 'originalfiction', 'dragon' ],
		[ 'societyculture', 'people-group' ],
		[ 'lifestylehobbies', 'chess' ],
		[ 'other', 'question' ],
		[ 'uncategorised', 'folder' ]
	] );

	$( () => {
		const field = document.getElementById( 'mw-input-wpcategory' );
		const container = document.getElementById( 'mw-input-wpcategoryIcon' );
		if ( !field || !container ) {
			return;
		}

		const category = OO.ui.infuse( field );
		function updateIcon( id ) {
			const icon = document.createElement( 'i' );
			icon.className = 'fa-solid fa-' + ( icons.get( id ) || 'question' );
			icon.setAttribute( 'aria-hidden', 'true' );
			container.replaceChildren( icon );
		}

		updateIcon( category.getValue() );
		category.on( 'change', updateIcon );
	} );
}() );
