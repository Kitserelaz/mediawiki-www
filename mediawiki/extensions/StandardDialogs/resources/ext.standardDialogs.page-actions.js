( function () {
	function showPlusIcon( item ) {
		const icon = item.querySelector( '.create-subpage-item' );

		if ( icon ) {
			icon.style.display = '';
			return;
		}

		let name = item.dataset.name;
		if ( mw.config.get( 'wgNamespaceNumber' ) === 0 ) {
			name = name.slice( 1 );
		}
		const plusIcon = new OO.ui.ButtonWidget( {
			framed: false,
			icon: 'add',
			label: mw.msg( 'standarddialogs-create-subpage-btn-label' ),
			invisibleLabel: true,
			title: mw.msg( 'standarddialogs-create-subpage-btn-title', name ),
			classes: [ 'create-subpage-item' ]
		} );

		plusIcon.connect( this, {
			click: () => {
				mw.loader.using( [ 'ext.standardDialogs.ui.NewSubpageDialog' ] ).done( () => {
					const diag = new StandardDialogs.ui.NewSubpageDialog( {
						id: 'standarddialogs-dlg-new-subpage',
						pageName: name
					} );

					diag.on( 'actioncompleted', ( newTitle ) => {
						window.location.href = newTitle.getUrl();
					} );

					diag.show();
				} );
			}
		} );

		const label = item.querySelector( '.oojsplus-data-tree-label' );
		if ( label ) {
			label.after( plusIcon.$element[ 0 ] );
		}
	}

	function hidePlusIcon( item ) {
		const icon = item.querySelector( '.create-subpage-item' );
		if ( icon ) {
			icon.style.display = 'none';
		}
	}

	// Hover
	document.addEventListener( 'mouseover', ( e ) => {
		const item = e.target.closest( '.oojsplus-data-navigation-tree .oojs-ui-data-tree-item' );
		if ( !item ) {
			return;
		}

		const from = e.relatedTarget && e.relatedTarget.closest( '.oojs-ui-data-tree-item' );

		if ( from === item ) {
			return;
		}

		showPlusIcon( item );
	} );

	document.addEventListener( 'mouseout', ( e ) => {
		const item = e.target.closest( '.oojsplus-data-navigation-tree .oojs-ui-data-tree-item' );
		if ( !item ) {
			return;
		}

		const to = e.relatedTarget && e.relatedTarget.closest( '.oojs-ui-data-tree-item' );
		if ( to === item ) {
			return;
		}

		hidePlusIcon( item );
	} );

	// Keyboard focus
	document.addEventListener( 'focusin', ( e ) => {
		const item = e.target.closest( '.oojsplus-data-navigation-tree .oojs-ui-data-tree-item' );
		if ( !item ) {
			return;
		}

		showPlusIcon( item );
	} );

	document.addEventListener( 'focusout', ( e ) => {
		const item = e.target.closest( '.oojsplus-data-navigation-tree .oojs-ui-data-tree-item' );
		if ( !item ) {
			return;
		}

		const to = e.relatedTarget && e.relatedTarget.closest( '.oojs-ui-data-tree-item' );
		if ( to === item ) {
			return;
		}

		hidePlusIcon( item );
	} );

}( document ) );
