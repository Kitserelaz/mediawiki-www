/* Nested disclosure navigation: pointer, keyboard and touch. */
document.querySelectorAll( '.fandom-community-header__local-navigation' ).forEach( ( nav ) => {
	const toggleSelector = ':scope > .wds-dropdown__toggle, :scope > .wds-dropdown__placeholder, :scope > .wds-dropdown-level-nested__toggle';
	const panelSelector = ':scope > .wds-dropdown__content, :scope > .wds-dropdown-level-nested__content';
	const focusableSelector = 'a[href], [role="button"]';
	const close = ( item ) => {
		item.classList.remove( 'wds-is-active' );
		const toggle = item.querySelector( toggleSelector );
		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', 'false' );
		}
		item.querySelectorAll( 'li.wds-is-active' ).forEach( close );
	};
	const open = ( item ) => {
		Array.from( item.parentElement.children ).filter( ( sibling ) => sibling !== item ).forEach( close );
		item.classList.add( 'wds-is-active' );
		item.querySelector( toggleSelector ).setAttribute( 'aria-expanded', 'true' );
		const panel = item.querySelector( panelSelector );
		item.classList.remove( 'opens-left' );
		panel.style.removeProperty( 'left' );
		if ( window.matchMedia( '(max-width: 700px)' ).matches ) {
			return;
		}
		const bounds = panel.getBoundingClientRect();
		if ( bounds.right > window.innerWidth - 12 ) {
			if ( item.parentElement === nav.querySelector( '.wds-tabs' ) ) {
				panel.style.left = `${ Math.min( 0, window.innerWidth - 12 - bounds.right ) }px`;
			} else {
				item.classList.add( 'opens-left' );
			}
		}
	};
	nav.querySelectorAll( '.wds-dropdown, .wds-dropdown-level-nested' ).forEach( ( item ) => {
		const toggle = item.querySelector( toggleSelector );
		if ( !item.querySelector( panelSelector ) ) {
			return;
		}
		item.addEventListener( 'pointerenter', ( event ) => {
			if ( event.pointerType === 'mouse' ) {
				open( item );
			}
		} );
		item.addEventListener( 'pointerleave', ( event ) => {
			if ( event.pointerType === 'mouse' && !item.contains( document.activeElement ) ) {
				close( item );
			}
		} );
		toggle.addEventListener( 'click', ( event ) => {
			if ( toggle.matches( 'a[href]' ) ) {
				// First touch opens a linked group; a second touch follows its link.
				if ( !item.classList.contains( 'wds-is-active' ) ) {
					event.preventDefault();
					open( item );
				}
			} else if ( item.classList.contains( 'wds-is-active' ) ) {
				close( item );
			} else {
				open( item );
			}
		} );
		toggle.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'ArrowDown' || event.key === 'ArrowRight' ) {
				event.preventDefault();
				open( item );
				item.querySelector( panelSelector ).querySelector( focusableSelector )?.focus();
			} else if ( event.key === ' ' || ( event.key === 'Enter' && toggle.matches( '[role="button"]' ) ) ) {
				event.preventDefault();
				toggle.click();
			}
		} );
	} );
	nav.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Escape' ) {
			const item = event.target.closest( 'li.wds-is-active' );
			if ( item ) {
				event.preventDefault();
				event.stopPropagation();
				close( item );
				item.querySelector( toggleSelector ).focus();
			}
		}
	} );
	nav.addEventListener( 'focusout', () => {
		setTimeout( () => {
			nav.querySelectorAll( 'li.wds-is-active' ).forEach( ( item ) => {
				if ( !item.contains( document.activeElement ) && !item.matches( ':hover' ) ) {
					close( item );
				}
			} );
		}, 0 );
	} );
	document.addEventListener( 'click', ( event ) => {
		if ( !nav.contains( event.target ) ) {
			nav.querySelectorAll( 'li.wds-is-active' ).forEach( close );
		}
	} );
} );
