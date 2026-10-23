( function () {
	'use strict';

	function init() {
		let name = document.querySelector( 'input[name="wpsitename"]' );
		let subdomain = document.querySelector( 'input[name="wpdbname"]' );
		const autoField = document.querySelector( 'input[name="wpsubdomainauto"]' );
		if ( !name || !subdomain || !autoField ) {
			return;
		}

		// Infuse first so listeners stay attached to OOUI's final input elements.
		const nameWidget = OO.ui.infuse( name.closest( '.oo-ui-inputWidget' ) );
		const subdomainWidget = OO.ui.infuse( subdomain.closest( '.oo-ui-inputWidget' ) );
		name = nameWidget.$input[ 0 ];
		subdomain = subdomainWidget.$input[ 0 ];

		const status = document.createElement( 'div' );
		status.className = 'skywiki-subdomain-status';
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		status.id = 'skywiki-subdomain-status';
		const suggestions = document.createElement( 'div' );
		const suggestionStatus = document.createElement( 'span' );
		suggestionStatus.setAttribute( 'role', 'status' );
		const useSuggestion = document.createElement( 'button' );
		useSuggestion.type = 'button';
		useSuggestion.hidden = true;
		suggestions.appendChild( suggestionStatus );
		suggestions.appendChild( useSuggestion );
		const field = subdomain.closest( '.oo-ui-fieldLayout' ) || subdomain.parentNode;
		field.appendChild( status );
		field.appendChild( suggestions );
		subdomain.setAttribute( 'aria-describedby',
			[ subdomain.getAttribute( 'aria-describedby' ), status.id ].filter( Boolean ).join( ' ' ) );

		const api = new mw.Api();
		let automatic = autoField.value === '1' || !subdomain.value.trim();
		autoField.value = automatic ? '1' : '0';
		let suggested = '';
		let lastSubdomainValue = subdomain.value;
		const channels = {
			suggest: { generation: 0 },
			check: { generation: 0 }
		};

		function cancel( channel ) {
			channel.generation++;
			clearTimeout( channel.timer );
			if ( channel.request && channel.request.abort ) {
				channel.request.abort();
			}
		}

		function applySuggestion( value ) {
			cancel( channels.check );
			automatic = true;
			autoField.value = '1';
			lastSubdomainValue = value;
			subdomainWidget.setValue( value );
			status.textContent = mw.msg( 'createnewwiki-subdomain-available', value );
			suggestionStatus.textContent = '';
			useSuggestion.hidden = true;
		}

		function schedule( suggest ) {
			const channel = suggest ? channels.suggest : channels.check;
			cancel( channel );
			const current = channel.generation;
			const value = suggest ? name.value.trim() : subdomain.value;
			const feedback = suggest && !automatic ? suggestionStatus : status;
			if ( suggest ) {
				suggested = '';
				useSuggestion.hidden = true;
			}
			if ( !value.trim() ) {
				feedback.textContent = suggest ? '' : mw.msg( 'createnewwiki-subdomain-enter' );
				return;
			}
			feedback.textContent = mw.msg( 'createnewwiki-subdomain-checking' );
			channel.timer = setTimeout( () => {
				channel.request = api.get( Object.assign( {
					action: 'skywikisubdomain',
					formatversion: 2
				}, suggest ? { name: value } : { subdomain: value } ) );
				channel.request.then( ( response ) => {
					if ( current !== channel.generation ) {
						return;
					}
					const result = response.skywikisubdomain;
					if ( suggest ) {
						suggestionStatus.textContent = '';
						if ( result.available ) {
							suggested = result.subdomain;
							if ( automatic ) {
								applySuggestion( suggested );
							} else if ( suggested !== subdomain.value ) {
								useSuggestion.textContent = mw.msg( 'createnewwiki-subdomain-use', suggested );
								useSuggestion.hidden = false;
							}
						} else {
							( automatic ? status : suggestionStatus ).textContent = result.message;
						}
					} else {
						status.textContent = result.available ?
							mw.msg( 'createnewwiki-subdomain-available', result.subdomain ) : result.message;
					}
				}, () => {
					if ( current === channel.generation ) {
						( suggest && !automatic ? suggestionStatus : status ).textContent =
							mw.msg( 'createnewwiki-subdomain-check-failed' );
					}
				} );
			}, 350 );
		}

		name.addEventListener( 'input', () => {
			if ( automatic ) {
				cancel( channels.check );
			}
			schedule( true );
		} );
		subdomain.addEventListener( 'input', () => {
			// Ignore duplicate notifications and our own widget updates.
			if ( subdomain.value === lastSubdomainValue ) {
				return;
			}
			lastSubdomainValue = subdomain.value;
			automatic = !subdomain.value.trim();
			autoField.value = automatic ? '1' : '0';
			cancel( channels.suggest );
			suggested = '';
			useSuggestion.hidden = true;
			suggestionStatus.textContent = '';
			if ( automatic ) {
				cancel( channels.check );
				schedule( true );
			} else {
				schedule( false );
			}
		} );
		useSuggestion.addEventListener( 'click', () => {
			if ( suggested ) {
				applySuggestion( suggested );
			}
		} );
		if ( automatic && !subdomain.value.trim() ) {
			schedule( true );
		} else {
			schedule( false );
			if ( !automatic ) {
				schedule( true );
			}
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init, { once: true } );
	} else {
		init();
	}
}() );
