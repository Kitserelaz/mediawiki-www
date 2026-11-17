'use strict';

const config = mw.config.get( 'wgMijoviaThemeDesigner' );
const form = document.getElementById( 'mijovia-theme-designer' );
const frame = document.getElementById( 'theme-preview-frame' );
const modeSelect = document.getElementById( 'theme-preview-mode' );
const pageSelect = document.getElementById( 'theme-preview-page' );
const article = document.getElementById( 'theme-preview-article' );
const status = document.getElementById( 'theme-preview-status' );
const suggestions = document.getElementById( 'mijovia-theme-images' );
const colorPickers = form.querySelectorAll( '[data-color-for]' );
const api = new mw.Api();
const canvas = document.createElement( 'canvas' );
canvas.width = 1;
canvas.height = 1;
const pixel = canvas.getContext( '2d', { willReadFrequently: true } );
let preview;
let previewRequest;
let refreshTimer;
let imageRequest;
let imageTimer;

function readColor( color ) {
	pixel.clearRect( 0, 0, 1, 1 );
	pixel.fillStyle = '#000000';
	pixel.fillStyle = color.trim();
	pixel.fillRect( 0, 0, 1, 1 );
	const [ r, g, b, a ] = pixel.getImageData( 0, 0, 1, 1 ).data;
	return a === 0 ? null : [ r, g, b ];
}

function hexColor( channels ) {
	return '#' + channels.map( ( channel ) => channel.toString( 16 ).padStart( 2, '0' ) ).join( '' );
}

function luminance( channels ) {
	const [ r, g, b ] = channels.map( ( channel ) => {
		const value = channel / 255;
		return value <= 0.04045 ? value / 12.92 : ( ( value + 0.055 ) / 1.055 ) ** 2.4;
	} );
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast( foreground, background ) {
	const first = luminance( foreground );
	const second = luminance( background );
	return ( Math.max( first, second ) + 0.05 ) / ( Math.min( first, second ) + 0.05 );
}

function renderFeedback( doc ) {
	const feedback = new Map( Object.entries( preview.errors ) );
	const swatchVariables = {
		site: '--theme-body-background-color',
		header: '--theme-sticky-nav-background-color',
		article: '--theme-page-background-color',
		rail: '--mijovia-theme-rail-color',
		text: '--theme-page-text-color',
		link: '--theme-link-color',
		accent: '--theme-accent-color'
	};
	const rail = doc.querySelector( '.mijovia-article-rail' );
	for ( const mode of [ 'light', 'dark' ] ) {
		doc.documentElement.dataset.theme = mode;
		const surface = doc.defaultView.getComputedStyle( doc.body );
		const colors = {};
		for ( const [ field, variable ] of Object.entries( swatchVariables ) ) {
			colors[ field ] = readColor( surface.getPropertyValue( variable ) || 'transparent' );
		}
		if ( !colors.rail ) {
			colors.rail = rail ?
				readColor( doc.defaultView.getComputedStyle( rail ).backgroundColor ) || colors.site :
				( mode === 'light' && colors.article && colors.site ?
					colors.article.map( ( channel, index ) => Math.round( channel * 0.75 + colors.site[ index ] * 0.25 ) ) :
					colors.site );
		}

		for ( const [ text, bg ] of [ [ 'text', 'article' ], [ 'link', 'article' ], [ 'text', 'rail' ], [ 'link', 'rail' ] ] ) {
			if ( colors[ bg ] && colors[ text ] && contrast( colors[ text ], colors[ bg ] ) < 4.5 ) {
				const key = mode + '-' + text;
				const warning = mw.msg( 'mijovia-theme-contrast-' + bg );
				feedback.set( key, [ feedback.get( key ), warning ].filter( Boolean ).join( ' ' ) );
			}
		}
		for ( const [ field, channels ] of Object.entries( colors ) ) {
			if ( channels ) {
				const name = mode + '-' + field;
				const picker = form.querySelector( '[data-color-for="theme-' + name + '"]' );
				const hex = hexColor( channels );
				picker.value = hex;
				if ( field === 'rail' ) {
					const input = form.elements.namedItem( name );
					const prefill = form.elements.namedItem( 'prefill[' + name + ']' );
					if ( prefill && input.value === prefill.value ) {
						input.value = hex;
						prefill.value = hex;
					}
				}
			}
		}
	}
	showFieldFeedback( feedback );
}

function showFieldFeedback( feedback ) {
	for ( const input of form.querySelectorAll( 'input[aria-describedby]' ) ) {
		const error = preview.errors[ input.name ];
		input.setAttribute( 'aria-invalid', String( Boolean( error ) ) );
		document.getElementById( input.getAttribute( 'aria-describedby' ) ).textContent = feedback.get( input.name ) || '';
	}
}

function applyPreview() {
	if ( !preview ) {
		return;
	}
	const doc = frame.contentDocument;
	if ( !doc?.body?.classList.contains( 'skin-mijovia' ) ) {
		if ( frame.getAttribute( 'src' ) ) {
			status.textContent = mw.msg( 'mijovia-theme-preview-unavailable' );
		}
		return;
	}
	let styles = doc.getElementById( 'mijovia-published-theme' );
	if ( !styles ) {
		styles = doc.createElement( 'style' );
		styles.id = 'mijovia-published-theme';
		doc.head.append( styles );
	}
	styles.textContent = preview.css;
	for ( const logo of doc.querySelectorAll( 'img[data-mijovia-default-logo]' ) ) {
		logo.parentElement.hidden = preview.hideLogo;
		logo.closest( '.fandom-community-header' )?.classList.toggle( 'has-no-logo', preview.hideLogo );
		if ( preview.hideLogo ) {
			logo.removeAttribute( 'src' );
		} else {
			logo.src = preview.logoUrl || logo.dataset.mijoviaDefaultLogo;
		}
	}
	// The sandbox blocks scripts and form submission. Keep plain links in the
	// frame from navigating away while still allowing the preview to scroll.
	doc.addEventListener( 'click', preventNavigation, true );
	doc.addEventListener( 'auxclick', preventNavigation, true );
	if ( pixel ) {
		renderFeedback( doc );
	}
	doc.documentElement.dataset.theme = modeSelect.value;
	status.textContent = '';
}

function preventNavigation( event ) {
	if ( event.target.closest?.( 'a' ) ) {
		event.preventDefault();
	}
}

function showPage() {
	if ( !preview ) {
		return;
	}
	const url = pageSelect.value === 'home' ? config.homeUrl : preview.articleUrl;
	if ( !url ) {
		frame.hidden = true;
		status.textContent = mw.msg( 'mijovia-theme-preview-choose-article' );
		return;
	}
	frame.hidden = false;
	if ( frame.getAttribute( 'src' ) === url ) {
		applyPreview();
	} else {
		status.textContent = mw.msg( 'mijovia-theme-preview-loading' );
		frame.src = url;
	}
}

async function refreshPreview() {
	const controller = new AbortController();
	previewRequest = controller;
	try {
		const response = await fetch( config.previewUrl, {
			method: 'POST',
			body: new FormData( form ),
			signal: controller.signal
		} );
		if ( !response.ok ) {
			throw new Error( 'Could not load preview settings' );
		}
		const result = await response.json();
		if ( previewRequest === controller ) {
			preview = result;
			showFieldFeedback( new Map( Object.entries( preview.errors ) ) );
			showPage();
		}
	} catch ( error ) {
		if ( previewRequest === controller && !controller.signal.aborted ) {
			status.textContent = mw.msg( 'mijovia-theme-preview-unavailable' );
		}
	}
}

function schedulePreview() {
	previewRequest?.abort();
	clearTimeout( refreshTimer );
	refreshTimer = setTimeout( refreshPreview, 180 );
}

form.addEventListener( 'input', ( event ) => {
	if ( !event.target.matches( 'input' ) ) {
		return;
	}
	if ( event.target.dataset.colorFor ) {
		document.getElementById( event.target.dataset.colorFor ).value = event.target.value;
	}
	if ( event.target.dataset.hideImageFor ) {
		document.getElementById( event.target.dataset.hideImageFor ).disabled = event.target.checked;
	}
	schedulePreview();
} );
frame.addEventListener( 'load', applyPreview );
pageSelect.addEventListener( 'change', showPage );
modeSelect.addEventListener( 'change', () => {
	if ( !frame.hidden ) {
		applyPreview();
	}
} );
for ( const toggle of form.querySelectorAll( '[data-hide-image-for]' ) ) {
	document.getElementById( toggle.dataset.hideImageFor ).disabled = toggle.checked;
}
for ( const input of form.querySelectorAll( '[data-theme-image]' ) ) {
	input.addEventListener( 'input', () => {
		clearTimeout( imageTimer );
		imageRequest?.abort();
		suggestions.replaceChildren();
		const prefix = input.value.trim().replace( /^[^:]+:\s*/, '' );
		if ( !prefix ) {
			return;
		}
		imageTimer = setTimeout( () => {
			const request = api.get( {
				action: 'query',
				list: 'allimages',
				aiprefix: prefix.replaceAll( ' ', '_' ),
				ailimit: 10,
				formatversion: 2
			} );
			imageRequest = request;
			request.then( ( response ) => {
				if ( imageRequest === request && document.activeElement === input ) {
					for ( const image of response.query.allimages ) {
						const option = document.createElement( 'option' );
						option.value = image.title;
						suggestions.append( option );
					}
				}
			}, () => {} );
		}, 200 );
	} );
}
for ( const picker of colorPickers ) {
	const value = document.getElementById( picker.dataset.colorFor ).value;
	if ( /^#[0-9a-f]{6}$/i.test( value ) ) {
		picker.value = value;
	}
}
refreshPreview();
