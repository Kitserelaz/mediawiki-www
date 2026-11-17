'use strict';

module.exports = function initNotifications( panel, toggle ) {
	const api = new mw.Api();
	const badge = toggle.querySelector( '.notifications__counter' );
	const list = panel.querySelector( '.mijovia-notifications-list' );
	const markRead = panel.querySelector( '.mijovia-notifications-mark-read' );
	const retry = panel.querySelector( '.mijovia-notifications-retry' );
	const statuses = panel.querySelectorAll( '[data-status]' );
	const numberFormat = new Intl.NumberFormat();
	const dateFormat = new Intl.DateTimeFormat( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
	const message = document.createElement( 'template' );
	let busy = false;
	let unread = 0;

	function showStatus( status ) {
		for ( const element of statuses ) {
			element.hidden = element.dataset.status !== status;
		}
	}

	function textFromMessage( html ) {
		message.innerHTML = html || '';
		return message.content.textContent.trim();
	}

	function renderNotification( entry ) {
		const model = entry[ '*' ] || {};
		const primary = model.links?.primary;
		const link = document.createElement( 'a' );
		link.href = primary?.url || '';
		const row = document.createElement( 'li' );
		const hasLink = primary?.url && ( link.protocol === 'https:' || link.protocol === 'http:' );
		const heading = hasLink ? link : document.createElement( 'span' );
		heading.textContent = textFromMessage( model.header ) || primary?.label || '';
		row.append( heading );
		const body = textFromMessage( model.body );
		if ( body ) {
			const snippet = document.createElement( 'span' );
			snippet.className = 'mijovia-notification-snippet';
			snippet.textContent = body;
			row.append( snippet );
		}
		if ( entry.timestamp?.utcunix ) {
			const date = new Date( Number( entry.timestamp.utcunix ) * 1000 );
			const time = document.createElement( 'time' );
			time.dateTime = date.toISOString();
			time.textContent = dateFormat.format( date );
			row.append( time );
		}
		return row;
	}

	function updateCount( rawCount ) {
		unread = Number( rawCount ) || 0;
		badge.textContent = unread ? numberFormat.format( Math.min( unread, 99 ) ) + ( unread > 99 ? '+' : '' ) : '';
	}

	async function refresh( markAll = false ) {
		if ( busy ) {
			return;
		}
		busy = true;
		markRead.disabled = true;
		list.setAttribute( 'aria-busy', 'true' );
		showStatus( 'loading' );
		try {
			if ( markAll ) {
				const result = await api.postWithToken( 'csrf', {
					action: 'echomarkread',
					all: true,
					assert: 'user'
				} );
				updateCount( result.query.echomarkread.rawcount );
				list.replaceChildren();
			}
			const result = await api.get( {
				action: 'query',
				meta: 'notifications',
				notsections: 'alert|message',
				notformat: 'model',
				notfilter: '!read',
				notprop: 'list|count',
				notlimit: 25
			} );
			const notifications = result.query.notifications;
			const entries = notifications.list || [];
			list.replaceChildren( ...entries.map( renderNotification ) );
			updateCount( notifications.rawcount );
			showStatus( entries.length ? null : 'empty' );
		} catch ( e ) {
			showStatus( 'error' );
		} finally {
			busy = false;
			list.removeAttribute( 'aria-busy' );
			markRead.disabled = unread === 0;
		}
	}

	markRead.addEventListener( 'click', () => {
		toggle.focus();
		refresh( true );
	} );
	retry.addEventListener( 'click', () => {
		toggle.focus();
		refresh();
	} );

	return () => refresh();
};
