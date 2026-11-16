'use strict';

const preference = 'userjs-example-toolbar';
const defaults = {
	bar: [ { id: 'watch' } ],
	menu: [ { id: 'history' }, { id: 'whatlinkshere' } ]
};
const popular = [ 'edit', 'history', 'whatlinkshere', 'recentchanges', 'upload', 'specialpages' ];

module.exports = function initToolbar( toolbar ) {
	const dialog = document.getElementById( 'example-toolbar-dialog' );
	const catalog = new Map( Array.from(
		document.querySelector( '#example-toolbar-catalog' ).content.querySelectorAll( '[data-key]' ),
		( element ) => [ element.dataset.key, { label: element.textContent, url: element.dataset.url } ]
	) );
	const customize = toolbar.querySelector( '.example-toolbar-customize' );
	const items = toolbar.querySelector( '.example-toolbar-items' );
	const search = dialog.querySelector( '#example-toolbar-search' );
	const matches = dialog.querySelector( '.example-toolbar-matches' );
	const popularList = dialog.querySelector( '.example-toolbar-popular ul' );
	const options = dialog.querySelector( '.example-toolbar-options' );
	const save = dialog.querySelector( '[data-toolbar-save]' );
	const body = dialog.querySelector( '.example-toolbar-dialog-body' );
	const error = dialog.querySelector( '.example-toolbar-error' );
	const labels = dialog.dataset;
	const groups = [ 'bar', 'menu' ];
	let saving = false;

	function normalize( configuration ) {
		if ( !configuration || !groups.every( ( group ) => Array.isArray( configuration[ group ] ) ) ) {
			configuration = defaults;
		}
		const seen = new Set();
		const clean = {};
		for ( const group of groups ) {
			clean[ group ] = [];
			for ( const entry of configuration[ group ] ) {
				if ( !catalog.has( entry?.id ) || seen.has( entry.id ) ) {
					continue;
				}
				seen.add( entry.id );
				const label = typeof entry.label === 'string' ? entry.label.trim().slice( 0, 48 ) : '';
				clean[ group ].push( label ? { id: entry.id, label } : { id: entry.id } );
			}
		}
		return clean;
	}

	let saved;
	try {
		saved = normalize( JSON.parse( toolbar.dataset.preference ) );
	} catch ( e ) {
		saved = normalize( defaults );
	}
	let draft;

	function element( tag, text ) {
		const node = document.createElement( tag );
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function button( text, onClick ) {
		const node = element( 'button', text );
		node.type = 'button';
		node.addEventListener( 'click', onClick );
		return node;
	}

	function toolLabel( entry ) {
		return entry.label || catalog.get( entry.id ).label;
	}

	function toolLink( entry ) {
		const url = catalog.get( entry.id ).url;
		if ( !url ) {
			return null;
		}
		const link = element( 'a', toolLabel( entry ) );
		link.href = url;
		return link;
	}

	function renderToolbar() {
		const menu = element( 'details' );
		menu.className = 'example-toolbar-menu';
		const summary = element( 'summary', toolbar.dataset.menuLabel );
		menu.append( summary );
		const list = element( 'ul' );
		for ( const entry of saved.menu ) {
			const link = toolLink( entry );
			if ( link ) {
				const row = element( 'li' );
				row.append( link );
				list.append( row );
			}
		}
		menu.append( list );
		menu.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Escape' && menu.open ) {
				event.preventDefault();
				menu.open = false;
				summary.focus();
			}
		} );

		items.replaceChildren( ...saved.bar.map( toolLink ).filter( Boolean ) );
		if ( list.childElementCount ) {
			items.append( menu );
		}
	}

	function renderPicker( list, candidates ) {
		const chosen = new Set( groups.flatMap( ( group ) => draft[ group ].map( ( entry ) => entry.id ) ) );
		list.replaceChildren();
		for ( const id of candidates ) {
			const tool = catalog.get( id );
			if ( !tool?.url || chosen.has( id ) ) {
				continue;
			}
			const row = element( 'li' );
			const add = button( tool.label, () => {
				draft.menu.push( { id } );
				search.value = '';
				renderEditor();
				focusTool( id );
			} );
			add.setAttribute( 'aria-label', labels.add + ': ' + tool.label );
			row.append( add );
			list.append( row );
		}
		if ( !list.childElementCount ) {
			list.append( element( 'li', labels.empty ) );
		}
	}

	function renderSearch() {
		const query = search.value.trim().toLocaleLowerCase();
		matches.hidden = !query;
		if ( query ) {
			renderPicker( matches, Array.from( catalog.keys() ).filter(
				( id ) => catalog.get( id ).label.toLocaleLowerCase().includes( query )
			) );
		}
	}

	function focusTool( id ) {
		const row = Array.from( options.children ).find( ( child ) => child.dataset.key === id );
		const control = row?.querySelector( 'button:not(:disabled)' );
		( control || search ).focus();
	}

	function renderEditor() {
		options.replaceChildren();
		for ( const group of groups ) {
			if ( group === 'menu' ) {
				options.append( element( 'li', toolbar.dataset.menuLabel ) );
			}
			draft[ group ].forEach( ( entry, index, groupItems ) => {
				const row = element( 'li' );
				row.className = group === 'menu' ? 'example-toolbar-nested' : '';
				row.dataset.key = entry.id;
				const label = element( 'span', toolLabel( entry ) );
				const controls = element( 'div' );
				controls.className = 'example-toolbar-item-actions';
				const addAction = ( glyph, name, action, disabled ) => {
					const control = button( glyph, action );
					control.title = name;
					control.setAttribute( 'aria-label', name + ': ' + toolLabel( entry ) );
					control.disabled = !!disabled;
					controls.append( control );
					return control;
				};
				const move = ( offset ) => {
					groupItems.splice( index, 1 );
					groupItems.splice( index + offset, 0, entry );
					renderEditor();
					focusTool( entry.id );
				};
				addAction( '↑', labels.up, () => move( -1 ), index === 0 );
				addAction( '↓', labels.down, () => move( 1 ), index === groupItems.length - 1 );
				addAction( group === 'menu' ? '←' : '→', group === 'menu' ? labels.moveBar : labels.moveMenu, () => {
					groupItems.splice( index, 1 );
					draft[ group === 'menu' ? 'bar' : 'menu' ].push( entry );
					renderEditor();
					focusTool( entry.id );
				} );
				const rename = addAction( '✎', labels.rename, () => {
					const input = element( 'input' );
					input.type = 'text';
					input.maxLength = 48;
					input.value = toolLabel( entry );
					input.setAttribute( 'aria-label', labels.rename );
					label.replaceWith( input );
					const commitLabel = () => {
						entry.label = input.value.trim();
						label.textContent = toolLabel( entry );
						input.replaceWith( label );
						for ( const control of controls.children ) {
							control.setAttribute( 'aria-label', control.title + ': ' + label.textContent );
						}
					};
					input.addEventListener( 'blur', commitLabel, { once: true } );
					input.addEventListener( 'keydown', ( event ) => {
						if ( event.key === 'Escape' ) {
							input.value = toolLabel( entry );
						}
						if ( event.key === 'Enter' || event.key === 'Escape' ) {
							event.preventDefault();
							rename.focus();
						}
					} );
					input.focus();
					input.select();
				} );
				addAction( '×', labels.remove, () => {
					groupItems.splice( index, 1 );
					renderEditor();
					focusTool( groupItems[ index ]?.id || groupItems[ index - 1 ]?.id );
				} );
				row.append( label, controls );
				options.append( row );
			} );
		}
		renderSearch();
		renderPicker( popularList, popular );
	}

	customize.addEventListener( 'click', () => {
		draft = normalize( saved );
		search.value = '';
		error.hidden = true;
		dialog.querySelector( '.example-toolbar-popular' ).open = false;
		renderEditor();
		dialog.showModal();
		search.focus();
	} );
	search.addEventListener( 'input', renderSearch );
	dialog.querySelector( '[data-toolbar-reset]' ).addEventListener( 'click', () => {
		draft = normalize( defaults );
		renderEditor();
	} );
	for ( const cancel of dialog.querySelectorAll( '[data-toolbar-cancel]' ) ) {
		cancel.addEventListener( 'click', () => {
			if ( !saving ) {
				dialog.close();
			}
		} );
	}
	dialog.addEventListener( 'cancel', ( event ) => {
		if ( saving ) {
			event.preventDefault();
		}
	} );
	dialog.addEventListener( 'close', () => customize.focus() );
	save.addEventListener( 'click', async () => {
		const next = normalize( draft );
		saving = true;
		save.disabled = true;
		body.inert = true;
		error.hidden = true;
		try {
			const result = await new mw.Api().saveOption( preference, JSON.stringify( next ), { assert: 'user' } );
			if ( result.options !== 'success' || result.warnings?.options ) {
				throw new Error( 'Toolbar preference was not saved.' );
			}
			saved = next;
			renderToolbar();
			dialog.close();
		} catch ( e ) {
			error.textContent = labels.error;
			error.hidden = false;
		} finally {
			saving = false;
			save.disabled = false;
			body.inert = false;
			if ( !error.hidden ) {
				error.scrollIntoView( { block: 'nearest' } );
				save.focus();
			}
		}
	} );

	document.addEventListener( 'click', ( event ) => {
		const menu = items.querySelector( '.example-toolbar-menu[open]' );
		if ( menu && !menu.contains( event.target ) ) {
			menu.open = false;
		}
	} );
	renderToolbar();
	customize.hidden = false;
};
