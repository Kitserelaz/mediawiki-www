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

const themeToggle = document.querySelector( '.wiki-tools__theme-switch' );
const widthToggle = document.querySelector( '.content-size-toggle' );
const themeRoot = document.documentElement;

function setTheme( theme ) {
	themeRoot.dataset.theme = theme;
	themeToggle.setAttribute( 'aria-label',
		theme === 'light' ? themeToggle.dataset.labelDark : themeToggle.dataset.labelLight
	);
}

const initialTheme = themeRoot.dataset.theme || mw.storage.get( 'example-theme' );
setTheme( initialTheme === 'light' ? 'light' : 'dark' );
themeToggle.addEventListener( 'click', () => {
	const theme = themeRoot.dataset.theme === 'light' ? 'dark' : 'light';
	setTheme( theme );
	mw.storage.set( 'example-theme', theme );
} );

widthToggle.addEventListener( 'click', () => {
	const expanded = document.body.classList.toggle( 'is-content-expanded' );
	widthToggle.setAttribute( 'aria-pressed', String( expanded ) );
} );

const disclosures = new Map();
document.querySelectorAll( '.example-disclosure' ).forEach( ( menu ) => {
	const toggle = menu.querySelector( 'button' );
	const panel = document.getElementById( toggle.getAttribute( 'aria-controls' ) );
	const setOpen = ( open ) => {
		menu.classList.toggle( 'wds-is-active', open );
		toggle.setAttribute( 'aria-expanded', String( open ) );
		panel.hidden = !open;
	};
	if ( toggle.id ) {
		disclosures.set( toggle.id, setOpen );
	}

	toggle.addEventListener( 'click', () => {
		setOpen( panel.hidden );
		if ( !panel.hidden ) {
			toggle.focus();
		}
	} );
	document.addEventListener( 'click', ( event ) => {
		if ( !menu.contains( event.target ) &&
			event.target.closest( '.example-page-tools-trigger' )?.dataset.disclosure !== toggle.id
		) {
			setOpen( false );
		}
	} );
	menu.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Escape' && !panel.hidden ) {
			setOpen( false );
			toggle.focus();
		}
	} );
} );

document.querySelector( '#example-contents' )?.addEventListener( 'click', ( event ) => {
	if ( event.target.closest( 'a' ) ) {
		document.querySelector( '[aria-controls="example-contents"]' ).click();
	}
} );

document.querySelectorAll( '.example-page-tools-trigger' ).forEach( ( trigger ) => {
	trigger.addEventListener( 'click', () => {
		const toggle = document.getElementById( trigger.dataset.disclosure );
		disclosures.get( toggle.id )( true );
		toggle.focus();
	} );
} );

const toolbar = document.getElementById( 'example-article-toolbar' );
if ( toolbar ) {
	require( './toolbar.js' )( toolbar );
	const closeToolbar = toolbar.querySelector( '.example-toolbar-close' );
	const showToolbar = document.querySelector( '.example-toolbar-show' );
	closeToolbar.addEventListener( 'click', () => {
		toolbar.hidden = true;
		showToolbar.hidden = false;
		showToolbar.focus();
	} );
	showToolbar.addEventListener( 'click', () => {
		toolbar.hidden = false;
		showToolbar.hidden = true;
		closeToolbar.focus();
	} );
}

const railToggle = document.querySelector( '.example-rail-toggle' );
if ( railToggle ) {
	const rail = railToggle.closest( '.example-article-rail' );
	const overview = document.getElementById( railToggle.getAttribute( 'aria-controls' ) );
	const desktopRail = window.matchMedia( '(min-width: 1280px)' );
	let collapsed = false;

	const updateRail = () => {
		const hideOverview = desktopRail.matches && collapsed;
		const label = hideOverview ? railToggle.dataset.labelShow : railToggle.dataset.labelHide;
		rail.classList.toggle( 'is-rail-hidden', hideOverview );
		overview.hidden = hideOverview;
		railToggle.setAttribute( 'aria-expanded', String( !hideOverview ) );
		railToggle.setAttribute( 'aria-label', label );
	};

	railToggle.addEventListener( 'click', () => {
		collapsed = !collapsed;
		updateRail();
	} );
	desktopRail.addEventListener( 'change', updateRail );
}

const recentActivity = document.querySelector( '.example-recent-activity' );
if ( recentActivity ) {
	new mw.Api().get( {
		action: 'query',
		list: 'recentchanges',
		rctype: 'edit|new',
		rcprop: 'title|user',
		rclimit: 4,
		formatversion: 2
	} ).then( ( response ) => {
		( response.query?.recentchanges || [] ).forEach( ( change ) => {
			const item = document.createElement( 'li' );
			const link = document.createElement( 'a' );
			link.href = mw.util.getUrl( change.title );
			link.textContent = change.title;
			item.append( link );
			if ( change.user ) {
				const editor = document.createElement( 'span' );
				editor.className = 'example-editor';
				editor.textContent = change.user;
				item.append( editor );
			}
			recentActivity.append( item );
		} );
	} ).catch( () => {
		// The link to Special:RecentChanges remains available.
	} );
}
