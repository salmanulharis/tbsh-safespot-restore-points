/**
 * SafeSpot admin screen.
 *
 * Vanilla JS + wp.apiFetch. Item rows are rendered server-side; REST responses
 * return refreshed row HTML so the markup lives in one place.
 *
 * @package SafeSpot
 */

/* global safespotAdmin */
( function ( wp, config ) {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const speak = wp.a11y && wp.a11y.speak ? wp.a11y.speak : function () {};
	const NS = '/safespot/v1';

	const $ = ( selector, context = document ) => context.querySelector( selector );
	const $$ = ( selector, context = document ) => Array.from( context.querySelectorAll( selector ) );

	/**
	 * Escapes a value for use in HTML text or attributes.
	 *
	 * @param {*} value Value.
	 * @return {string} Escaped string.
	 */
	function esc( value ) {
		const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
		return String( value === null || value === undefined ? '' : value ).replace( /[&<>"']/g, ( c ) => map[ c ] );
	}

	/**
	 * Human-readable message for an apiFetch error.
	 *
	 * @param {Object} error Error.
	 * @return {string} Message.
	 */
	function errorMessage( error ) {
		if ( error && error.message ) {
			return error.message;
		}
		return __( 'Something went wrong. Please try again.', 'tbsh-safespot-restore-points' );
	}

	/**
	 * Whether the request died without a JSON response (e.g. a PHP fatal error).
	 *
	 * @param {Object} error Error.
	 * @return {boolean} True for crashes.
	 */
	function isCrash( error ) {
		return ! error || [ 'invalid_json', 'fetch_error' ].includes( error.code );
	}

	/* ------------------------------------------------------------------ *
	 * Notices
	 * ------------------------------------------------------------------ */

	const Notices = {
		/**
		 * Shows a WordPress-style admin notice.
		 *
		 * @param {string} type    success|error|warning|info.
		 * @param {string} message Message.
		 * @param {Array}  actions Optional [{ label, onClick }].
		 * @return {HTMLElement} Notice.
		 */
		show( type, message, actions = [] ) {
			const container = $( '#safespot-notices' );
			const notice = document.createElement( 'div' );
			notice.className = 'notice notice-' + type + ' is-dismissible safespot-notice';

			const p = document.createElement( 'p' );
			p.textContent = message;
			notice.appendChild( p );

			if ( actions.length ) {
				const row = document.createElement( 'p' );
				actions.forEach( ( action ) => {
					const button = document.createElement( 'button' );
					button.type = 'button';
					button.className = 'button button-small';
					button.textContent = action.label;
					button.addEventListener( 'click', () => {
						notice.remove();
						action.onClick();
					} );
					row.appendChild( button );
				} );
				notice.appendChild( row );
			}

			const dismiss = document.createElement( 'button' );
			dismiss.type = 'button';
			dismiss.className = 'notice-dismiss';
			dismiss.innerHTML = '<span class="screen-reader-text">' + esc( __( 'Dismiss this notice.', 'tbsh-safespot-restore-points' ) ) + '</span>';
			dismiss.addEventListener( 'click', () => notice.remove() );
			notice.appendChild( dismiss );

			if ( container ) {
				container.prepend( notice );
				$$( '.safespot-notice', container ).slice( 4 ).forEach( ( old ) => old.remove() );
			}
			speak( message, 'error' === type ? 'assertive' : 'polite' );
			return notice;
		},
	};

	/* ------------------------------------------------------------------ *
	 * Modal (native <dialog>)
	 * ------------------------------------------------------------------ */

	const Modal = ( function () {
		const dialog = $( '#safespot-modal' );
		if ( ! dialog ) {
			return null;
		}

		const form = $( 'form', dialog );
		const title = $( '.safespot-modal__title', dialog );
		const body = $( '.safespot-modal__body', dialog );
		const confirm = $( '.safespot-modal__confirm', dialog );
		const progress = $( '.safespot-modal__progress', dialog );
		const progressText = $( '.safespot-modal__progress-text', dialog );
		const progressBar = $( '.safespot-progress', dialog );
		const stopButton = $( '.safespot-modal__stop', dialog );

		let handler = null;
		let busy = false;
		let lastFocus = null;

		function setBusy( on, text = '' ) {
			busy = on;
			progress.hidden = ! on;
			progressText.textContent = text;
			progressBar.setAttribute( 'aria-valuetext', text );
			confirm.disabled = on || confirm.dataset.disabled === '1';
			$$( '[data-safespot-close]', dialog ).forEach( ( button ) => {
				button.disabled = on;
			} );
			body.setAttribute( 'aria-busy', on ? 'true' : 'false' );
			dialog.classList.toggle( 'is-busy', on );
			if ( ! on && stopButton ) {
				stopButton.hidden = true;
				stopButton.disabled = false;
			}
		}

		/**
		 * Shows a "Stop" button while busy, for operations that can be cancelled.
		 *
		 * @param {boolean} on Whether to show it.
		 */
		function setStoppable( on ) {
			if ( stopButton ) {
				stopButton.hidden = ! on;
				stopButton.disabled = false;
			}
		}

		function setConfirm( label, disabled = false, destructive = false ) {
			confirm.hidden = ! label;
			confirm.textContent = label || '';
			confirm.dataset.disabled = disabled ? '1' : '0';
			confirm.disabled = disabled;
			confirm.classList.toggle( 'safespot-button-danger', destructive );
		}

		function setError( message, extra ) {
			let box = $( '.safespot-modal__error', body );
			if ( ! box ) {
				box = document.createElement( 'div' );
				box.className = 'notice notice-error inline safespot-modal__error';
				box.setAttribute( 'role', 'alert' );
				body.prepend( box );
			}
			box.innerHTML = '<p>' + esc( message ) + '</p>';
			if ( extra ) {
				box.appendChild( extra );
			}
			box.scrollIntoView( { block: 'nearest' } );
		}

		function open( options ) {
			lastFocus = document.activeElement;
			title.textContent = options.title || '';
			body.innerHTML = options.body || '';
			handler = options.onConfirm || null;
			setConfirm( options.confirmLabel, !! options.confirmDisabled, !! options.destructive );
			setBusy( false );
			if ( ! dialog.open ) {
				dialog.showModal();
			}
			const focusable = $( 'textarea, input:not([type=hidden]), select', body );
			( focusable || ( confirm.hidden ? $( '[data-safespot-close]', dialog ) : confirm ) ).focus();
		}

		function close() {
			busy = false;
			setBusy( false );
			if ( dialog.open ) {
				dialog.close();
			}
		}

		dialog.addEventListener( 'close', () => {
			handler = null;
			if ( lastFocus && document.contains( lastFocus ) ) {
				lastFocus.focus();
			}
		} );

		dialog.addEventListener( 'cancel', ( event ) => {
			if ( busy ) {
				event.preventDefault();
			}
		} );

		dialog.addEventListener( 'click', ( event ) => {
			if ( busy ) {
				return;
			}
			if ( event.target.closest( '[data-safespot-close]' ) || event.target === dialog ) {
				event.preventDefault();
				close();
			}
		} );

		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			if ( busy || ! handler || confirm.disabled ) {
				return;
			}
			handler();
		} );

		return {
			open,
			close,
			setBusy,
			setStoppable,
			setConfirm,
			setError,
			body,
			setHandler( fn ) {
				handler = fn;
			},
			isBusy: () => busy,
		};
	} )();

	/* ------------------------------------------------------------------ *
	 * Running job banner (what holds the SafeSpot lock) and cancelling it
	 * ------------------------------------------------------------------ */

	const Jobs = ( function () {
		const el = $( '#safespot-job' );
		let timer = null;

		function describe( status ) {
			const lock = status.lock;
			if ( ! lock ) {
				const queue = status.queue || {};
				return {
					/* translators: 1: done count, 2: total count. */
					title: sprintf( __( 'Saving SafeSpots in bulk (%1$d of %2$d done)', 'tbsh-safespot-restore-points' ), queue.done || 0, queue.total || 0 ),
					meta: '',
				};
			}
			let meta = lock.phase || '';
			if ( lock.progress && 'compressing' === lock.progress_phase ) {
				meta += ' ' + lock.progress.percent + '%';
			} else if ( lock.progress ) {
				/* translators: 1: done, 2: total. */
				meta += ' ' + sprintf( __( '(%1$s of %2$s)', 'tbsh-safespot-restore-points' ), lock.progress.done.toLocaleString(), lock.progress.total.toLocaleString() );
			}
			/* translators: 1: time span, e.g. "3 mins", 2: user name. */
			meta += ( meta ? ' · ' : '' ) + sprintf( __( 'started %1$s ago by %2$s', 'tbsh-safespot-restore-points' ), lock.started_human, lock.user_name );
			return { title: lock.label + ( lock.job ? ' (' + lock.job + ')' : '' ), meta };
		}

		function render( status ) {
			if ( ! el ) {
				return;
			}
			if ( ! status || ! status.running ) {
				el.hidden = true;
				el.innerHTML = '';
				stop();
				return;
			}

			const lock = status.lock;
			const text = describe( status );
			let html = '<span class="spinner is-active" aria-hidden="true"></span><div class="safespot-job__text"><strong>' + esc( text.title ) + '</strong><span class="safespot-job__meta">' + esc( text.meta ) + '</span>';

			if ( lock && lock.progress ) {
				html += '<span class="safespot-progress safespot-progress--determinate" aria-hidden="true"><span class="safespot-progress__bar" style="width:' + esc( lock.progress.percent ) + '%"></span></span>';
			}
			if ( lock && ! lock.responsive ) {
				html += '<span class="safespot-job__warning">' + esc( __( 'This operation has stopped responding. Its process has probably ended, so you can release it.', 'tbsh-safespot-restore-points' ) ) + '</span>';
			} else if ( lock && lock.cancel_requested ) {
				html += '<span class="safespot-job__meta">' + esc( __( 'Stopping…', 'tbsh-safespot-restore-points' ) ) + '</span>';
			} else if ( lock && ! lock.cancellable ) {
				html += '<span class="safespot-job__meta">' + esc( __( 'Files are being replaced. This step cannot be stopped safely and normally finishes within a minute.', 'tbsh-safespot-restore-points' ) ) + '</span>';
			}
			html += '</div><div class="safespot-job__actions">';
			if ( ! lock || ( lock.cancellable && lock.responsive && ! lock.cancel_requested ) ) {
				html += '<button type="button" class="button" data-safespot-action="job-cancel">' + esc( __( 'Cancel', 'tbsh-safespot-restore-points' ) ) + '</button>';
			}
			if ( lock && ! lock.responsive ) {
				html += '<button type="button" class="button" data-safespot-action="job-force">' + esc( __( 'Release', 'tbsh-safespot-restore-points' ) ) + '</button>';
			}
			html += '</div>';

			el.innerHTML = html;
			el.hidden = false;
			watch();
		}

		async function refresh() {
			try {
				render( await apiFetch( { path: NS + '/jobs' } ) );
			} catch ( e ) {
				// Status polling is best effort.
			}
		}

		function watch() {
			if ( ! timer ) {
				timer = window.setInterval( refresh, 2500 );
			}
		}

		function stop() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}
		}

		/**
		 * Cancels the running job.
		 *
		 * @param {boolean} force Release the lock of a process that stopped responding.
		 * @return {Promise<Object|null>} Response, or null on error.
		 */
		async function cancel( force = false ) {
			if ( el ) {
				$$( 'button', el ).forEach( ( button ) => ( button.disabled = true ) );
			}
			try {
				const response = await apiFetch( { path: NS + '/jobs/cancel', method: 'POST', data: { force } } );
				Notices.show( 'requested' === response.status ? 'warning' : 'success', response.message );
				render( response.jobs );
				return response;
			} catch ( error ) {
				Notices.show( 'safespot_nothing_running' === ( error && error.code ) ? 'info' : 'error', errorMessage( error ) );
				refresh();
				return null;
			}
		}

		return {
			render,
			refresh,
			cancel,
			/**
			 * Shows the job named in a "SafeSpot is busy" error and keeps it up to date.
			 *
			 * @param {Object} lock Lock info from the error.
			 */
			show( lock ) {
				render( { running: true, lock, queue: {} } );
				refresh();
			},
		};
	} )();

	/**
	 * For a "SafeSpot is busy" error: shows the running job and returns a "Cancel it" button.
	 *
	 * @param {Object} error apiFetch error.
	 * @return {HTMLElement|undefined} Paragraph with the button.
	 */
	function busyExtra( error ) {
		if ( ! error || 'safespot_locked' !== error.code ) {
			return undefined;
		}
		if ( error.data && error.data.lock ) {
			Jobs.show( error.data.lock );
		}
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button';
		button.textContent = __( 'Cancel the running job', 'tbsh-safespot-restore-points' );
		button.addEventListener( 'click', async () => {
			button.disabled = true;
			const response = await Jobs.cancel( false );
			if ( response && 'requested' !== response.status ) {
				button.closest( 'p' ).textContent = __( 'The other job was cancelled. You can try again now.', 'tbsh-safespot-restore-points' );
			} else {
				button.disabled = false;
			}
		} );
		const wrap = document.createElement( 'p' );
		wrap.appendChild( button );
		return wrap;
	}

	/**
	 * Handles an operation that ended because it was cancelled.
	 *
	 * @param {Object} error apiFetch error.
	 * @return {boolean} True when the error was a cancellation (and was handled).
	 */
	function handledCancel( error ) {
		if ( ! error || 'safespot_cancelled' !== error.code ) {
			return false;
		}
		Modal.close();
		Notices.show( 'info', errorMessage( error ) );
		Jobs.refresh();
		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Helpers for item rows
	 * ------------------------------------------------------------------ */

	function itemFrom( element ) {
		const tbody = element.closest( 'tbody.safespot-item' );
		if ( ! tbody ) {
			return null;
		}
		return {
			tbody,
			type: tbody.dataset.type,
			slug: tbody.dataset.slug,
			name: tbody.dataset.name,
			version: tbody.dataset.version,
			prefixes: tbody.dataset.prefixes || '',
		};
	}

	function pointFrom( element ) {
		const row = element.closest( 'tr.safespot-point' );
		if ( ! row ) {
			return null;
		}
		return {
			row,
			id: parseInt( row.dataset.id, 10 ),
			version: row.dataset.version,
			date: row.dataset.date,
			note: row.dataset.note || '',
			locked: '1' === row.dataset.locked,
		};
	}

	/**
	 * Replaces an item <tbody> with fresh server HTML.
	 *
	 * @param {Object} response REST response with html + item_id.
	 */
	function replaceRow( response ) {
		if ( ! response || ! response.item_id ) {
			return;
		}
		const current = document.getElementById( response.item_id );
		if ( ! current ) {
			return;
		}
		if ( ! response.html ) {
			current.remove();
			applyFilters();
			return;
		}
		const template = document.createElement( 'template' );
		template.innerHTML = response.html.trim();
		const fresh = template.content.firstElementChild;
		if ( fresh ) {
			const wasChecked = $( '.safespot-select', current );
			const box = $( '.safespot-select', fresh );
			if ( wasChecked && box ) {
				box.checked = wasChecked.checked;
			}
			current.replaceWith( fresh );
		}
		applyFilters();
		updateBulkButton();
		syncPointSelection();
	}

	async function refreshItem( type, slug ) {
		try {
			const response = await apiFetch( { path: NS + '/item?type=' + encodeURIComponent( type ) + '&slug=' + encodeURIComponent( slug ) } );
			replaceRow( response );
		} catch ( e ) {
			// Row refresh is cosmetic; ignore.
		}
	}

	/* ------------------------------------------------------------------ *
	 * Search / filter / selection
	 * ------------------------------------------------------------------ */

	function applyFilters() {
		const table = $( '.safespot-items' );
		if ( ! table ) {
			return;
		}
		const search = ( $( '#safespot-search' ) || { value: '' } ).value.trim().toLowerCase();
		const filter = ( $( '#safespot-filter' ) || { value: 'all' } ).value;
		let visible = 0;

		$$( 'tbody.safespot-item', table ).forEach( ( tbody ) => {
			const data = tbody.dataset;
			const count = parseInt( data.count, 10 ) || 0;
			let show = ! search || ( data.search || '' ).includes( search );

			if ( show && 'with' === filter ) {
				show = count > 0;
			} else if ( show && 'without' === filter ) {
				show = 0 === count;
			} else if ( show && 'updates' === filter ) {
				show = !! data.update;
			} else if ( show && 'active' === filter ) {
				show = [ 'active', 'network', 'parent' ].includes( data.status );
			} else if ( show && 'not_installed' === filter ) {
				show = 'not_installed' === data.status;
			}

			tbody.hidden = ! show;
			if ( show ) {
				visible++;
			}
		} );

		const empty = $( '.safespot-no-results' );
		if ( empty ) {
			empty.hidden = visible > 0;
		}
		const counter = $( '.safespot-toolbar__count' );
		if ( counter ) {
			/* translators: %d: number of items. */
			counter.textContent = sprintf( _n( '%d item', '%d items', visible, 'tbsh-safespot-restore-points' ), visible );
		}
	}

	function selectedItems() {
		return $$( 'tbody.safespot-item:not([hidden]) .safespot-select:checked' ).map( ( box ) => {
			const item = itemFrom( box );
			return { type: item.type, slug: item.slug };
		} );
	}

	function updateBulkButton() {
		const button = $( '[data-safespot-action="bulk-save"]' );
		if ( button ) {
			const count = selectedItems().length;
			button.disabled = 0 === count;
		}
	}

	/* ------------------------------------------------------------------ *
	 * SafeSpot selection (history rows)
	 *
	 * Selected IDs live in a Set so they survive row re-renders.
	 * ------------------------------------------------------------------ */

	const selectedPoints = new Set();
	let lastPointBox = null;

	/**
	 * Selected SafeSpots that are still on the page, with their item context.
	 *
	 * @return {Array} [{ id, locked, version, date, name }].
	 */
	function selectedPointDetails() {
		return $$( 'tr.safespot-point' )
			.filter( ( row ) => selectedPoints.has( parseInt( row.dataset.id, 10 ) ) )
			.map( ( row ) => {
				const item = itemFrom( row );
				return {
					id: parseInt( row.dataset.id, 10 ),
					locked: '1' === row.dataset.locked,
					version: row.dataset.version,
					date: row.dataset.date,
					name: item ? item.name : '',
				};
			} );
	}

	/**
	 * Applies the selection to checkboxes, row highlights, select-all boxes and the bar.
	 */
	function syncPointSelection() {
		const present = new Set();
		$$( '.safespot-point-select' ).forEach( ( box ) => {
			const id = parseInt( box.value, 10 );
			present.add( id );
			box.checked = selectedPoints.has( id );
			box.closest( 'tr' ).classList.toggle( 'is-selected', box.checked );
		} );
		Array.from( selectedPoints ).forEach( ( id ) => {
			if ( ! present.has( id ) ) {
				selectedPoints.delete( id );
			}
		} );

		$$( '.safespot-history-table' ).forEach( ( table ) => {
			const all = $( '.safespot-point-select-all', table );
			if ( ! all ) {
				return;
			}
			const boxes = $$( '.safespot-point-select', table );
			const checked = boxes.filter( ( box ) => box.checked ).length;
			all.checked = checked > 0 && checked === boxes.length;
			all.indeterminate = checked > 0 && checked < boxes.length;
		} );

		updateSelectionBar();
	}

	function updateSelectionBar() {
		const bar = $( '.safespot-selection-bar' );
		if ( ! bar ) {
			return;
		}
		const details = selectedPointDetails();
		const count = details.length;
		const locked = details.filter( ( point ) => point.locked ).length;
		const items = new Set( details.map( ( point ) => point.name ) ).size;

		bar.hidden = 0 === count;
		/* translators: %d: number of SafeSpots. */
		let text = sprintf( _n( '%d SafeSpot selected', '%d SafeSpots selected', count, 'tbsh-safespot-restore-points' ), count );
		if ( items > 1 ) {
			/* translators: %d: number of items. */
			text += ' · ' + sprintf( _n( '%d item', '%d items', items, 'tbsh-safespot-restore-points' ), items );
		}
		$( '.safespot-selection-bar__count', bar ).textContent = count ? text : '';

		const lockButton = $( '[data-safespot-action="bulk-lock"]', bar );
		const unlockButton = $( '[data-safespot-action="bulk-unlock"]', bar );
		if ( lockButton ) {
			lockButton.disabled = locked === count;
		}
		if ( unlockButton ) {
			unlockButton.disabled = 0 === locked;
		}
	}

	function clearPointSelection() {
		selectedPoints.clear();
		lastPointBox = null;
		syncPointSelection();
	}

	/**
	 * Checks or unchecks one SafeSpot; with Shift, the whole range from the last click
	 * in the same history table.
	 *
	 * @param {HTMLInputElement} box   Checkbox that was clicked.
	 * @param {boolean}          range Whether Shift was held.
	 */
	function togglePointBox( box, range ) {
		const table = box.closest( '.safespot-history-table' );
		let boxes = [ box ];
		if ( range && lastPointBox && lastPointBox !== box && table && table.contains( lastPointBox ) ) {
			const all = $$( '.safespot-point-select', table );
			const from = all.indexOf( lastPointBox );
			const to = all.indexOf( box );
			boxes = all.slice( Math.min( from, to ), Math.max( from, to ) + 1 );
		}
		boxes.forEach( ( other ) => {
			const id = parseInt( other.value, 10 );
			if ( box.checked ) {
				selectedPoints.add( id );
			} else {
				selectedPoints.delete( id );
			}
		} );
		lastPointBox = box;
		syncPointSelection();
	}

	/**
	 * Runs a bulk action and refreshes the affected rows.
	 *
	 * @param {string}  action        lock|unlock|delete.
	 * @param {Array}   ids           SafeSpot IDs.
	 * @param {boolean} includeLocked Delete locked SafeSpots too.
	 * @return {Promise<Object>} Response.
	 */
	async function runPointBulk( action, ids, includeLocked = false ) {
		const response = await apiFetch( {
			path: NS + '/points/bulk',
			method: 'POST',
			data: { action, ids, include_locked: includeLocked },
		} );
		( response.done || [] ).forEach( ( id ) => selectedPoints.delete( id ) );
		( response.rows || [] ).forEach( replaceRow );
		syncPointSelection();
		const partial = ( response.failed || [] ).length || Object.values( response.skipped || {} ).some( ( list ) => list.length );
		Notices.show( partial ? 'warning' : 'success', response.message );
		return response;
	}

	async function bulkLock( button, lock ) {
		const ids = selectedPointDetails()
			.filter( ( point ) => point.locked !== lock )
			.map( ( point ) => point.id );
		if ( ! ids.length ) {
			return;
		}
		const bar = button.closest( '.safespot-selection-bar' );
		$$( 'button', bar ).forEach( ( el ) => ( el.disabled = true ) );
		try {
			await runPointBulk( lock ? 'lock' : 'unlock', ids );
			clearPointSelection();
		} catch ( error ) {
			Notices.show( 'error', errorMessage( error ) );
		} finally {
			$$( 'button', bar ).forEach( ( el ) => ( el.disabled = false ) );
			updateSelectionBar();
		}
	}

	function openBulkDelete() {
		const details = selectedPointDetails();
		if ( ! details.length ) {
			return;
		}
		const locked = details.filter( ( point ) => point.locked ).length;

		const groups = new Map();
		details.forEach( ( point ) => {
			if ( ! groups.has( point.name ) ) {
				groups.set( point.name, [] );
			}
			groups.get( point.name ).push( point );
		} );

		let list = '<ul class="safespot-bulk-list">';
		groups.forEach( ( points, name ) => {
			list +=
				'<li><strong>' +
				esc( name ) +
				'</strong><ul>' +
				points
					.map(
						( point ) =>
							'<li>' +
							esc( point.version || '—' ) +
							' <span class="safespot-muted">· ' +
							esc( point.date ) +
							'</span>' +
							( point.locked ? ' <span class="dashicons dashicons-lock safespot-lock-icon" aria-hidden="true"></span><span class="screen-reader-text">' + esc( __( 'Locked', 'tbsh-safespot-restore-points' ) ) + '</span>' : '' ) +
							'</li>'
					)
					.join( '' ) +
				'</ul></li>';
		} );
		list += '</ul>';

		let body =
			'<p>' +
			esc(
				/* translators: %d: number of SafeSpots. */
				sprintf( _n( 'Delete %d SafeSpot? The archive is removed permanently.', 'Delete %d SafeSpots? The archives are removed permanently.', details.length, 'tbsh-safespot-restore-points' ), details.length )
			) +
			'</p>' +
			list;

		if ( locked ) {
			body +=
				'<div class="notice notice-warning inline"><p><label><input type="checkbox" id="safespot-include-locked" /> ' +
				esc(
					/* translators: %d: number of locked SafeSpots. */
					sprintf( _n( 'Also delete %d locked SafeSpot', 'Also delete %d locked SafeSpots', locked, 'tbsh-safespot-restore-points' ), locked )
				) +
				'</label></p><p class="description">' +
				esc( __( 'Locked SafeSpots are kept unless you tick this box.', 'tbsh-safespot-restore-points' ) ) +
				'</p></div>';
		}

		const label = ( includeLocked ) => {
			const count = includeLocked ? details.length : details.length - locked;
			if ( ! count ) {
				return __( 'Delete SafeSpots', 'tbsh-safespot-restore-points' );
			}
			/* translators: %d: number of SafeSpots. */
			return sprintf( _n( 'Delete %d SafeSpot', 'Delete %d SafeSpots', count, 'tbsh-safespot-restore-points' ), count );
		};

		Modal.open( {
			title: __( 'Delete selected SafeSpots?', 'tbsh-safespot-restore-points' ),
			body,
			confirmLabel: label( false ),
			confirmDisabled: locked === details.length,
			destructive: true,
			onConfirm: async () => {
				const includeBox = $( '#safespot-include-locked', Modal.body );
				const includeLocked = !! ( includeBox && includeBox.checked );
				const ids = details.filter( ( point ) => includeLocked || ! point.locked ).map( ( point ) => point.id );
				Modal.setBusy( true, __( 'Deleting…', 'tbsh-safespot-restore-points' ) );
				try {
					await runPointBulk( 'delete', ids, includeLocked );
					Modal.close();
					clearPointSelection();
				} catch ( error ) {
					Modal.setBusy( false );
					Modal.setError( errorMessage( error ) );
				}
			},
		} );

		const includeBox = $( '#safespot-include-locked', Modal.body );
		if ( includeBox ) {
			includeBox.addEventListener( 'change', () => {
				Modal.setConfirm( label( includeBox.checked ), ! includeBox.checked && locked === details.length, true );
			} );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Actions
	 * ------------------------------------------------------------------ */

	function openSave( item ) {
		const body =
			'<p>' +
			esc(
				/* translators: 1: plugin/theme name, 2: version. */
				sprintf( __( 'Save a restore point of %1$s %2$s. You can restore this exact version later with one click.', 'tbsh-safespot-restore-points' ), item.name, item.version )
			) +
			'</p>' +
			'<p><label for="safespot-note" class="safespot-label">' +
			esc( __( 'Note (optional)', 'tbsh-safespot-restore-points' ) ) +
			'</label><textarea id="safespot-note" class="large-text" rows="2" maxlength="1000" placeholder="' +
			esc( __( 'e.g. Stable before v3.0 update', 'tbsh-safespot-restore-points' ) ) +
			'"></textarea></p>' +
			'<fieldset class="safespot-fieldset"><legend class="screen-reader-text">' +
			esc( __( 'Settings snapshot', 'tbsh-safespot-restore-points' ) ) +
			'</legend><label><input type="checkbox" id="safespot-include-settings" ' +
			( config.settingsSnapshot ? 'checked' : '' ) +
			' /> ' +
			esc( __( 'Also save a settings snapshot (wp_options rows)', 'tbsh-safespot-restore-points' ) ) +
			'</label><div class="safespot-prefixes" ' +
			( config.settingsSnapshot ? '' : 'hidden' ) +
			'><label for="safespot-prefixes" class="safespot-label">' +
			esc( __( 'Option name prefixes', 'tbsh-safespot-restore-points' ) ) +
			'</label><input type="text" id="safespot-prefixes" class="regular-text" value="' +
			esc( item.prefixes ) +
			'" aria-describedby="safespot-prefixes-desc" /><p class="description" id="safespot-prefixes-desc">' +
			esc( __( 'Comma-separated. Options whose names start with these prefixes are exported. Custom database tables are not included.', 'tbsh-safespot-restore-points' ) ) +
			'</p></div></fieldset>';

		Modal.open( {
			/* translators: %s: plugin/theme name. */
			title: sprintf( __( 'Save SafeSpot: %s', 'tbsh-safespot-restore-points' ), item.name ),
			body,
			confirmLabel: __( 'Save SafeSpot', 'tbsh-safespot-restore-points' ),
			onConfirm: async () => {
				const include = $( '#safespot-include-settings', Modal.body ).checked;
				const data = {
					type: item.type,
					slug: item.slug,
					note: $( '#safespot-note', Modal.body ).value,
					include_settings: include,
				};
				if ( include ) {
					data.prefixes = $( '#safespot-prefixes', Modal.body ).value;
				}
				Modal.setBusy( true, __( 'Creating the archive… large plugins can take a minute.', 'tbsh-safespot-restore-points' ) );
				Modal.setStoppable( true );
				try {
					const response = await apiFetch( { path: NS + '/points', method: 'POST', data } );
					Modal.close();
					replaceRow( response );
					Notices.show( response.reused ? 'info' : 'success', response.message );
				} catch ( error ) {
					if ( handledCancel( error ) ) {
						return;
					}
					Modal.setBusy( false );
					Modal.setError( errorMessage( error ), busyExtra( error ) );
				}
			},
		} );

		const toggle = $( '#safespot-include-settings', Modal.body );
		toggle.addEventListener( 'change', () => {
			$( '.safespot-prefixes', Modal.body ).hidden = ! toggle.checked;
		} );
	}

	const DIRECTION_TEXT = {
		downgrade: __( 'Roll back to an older version.', 'tbsh-safespot-restore-points' ),
		reinstall: __( 'Reinstall the same version (replaces any edited or corrupted files).', 'tbsh-safespot-restore-points' ),
		upgrade: __( 'Restore a newer version than the one installed.', 'tbsh-safespot-restore-points' ),
		install: __( 'This item is not installed. It will be reinstalled from the SafeSpot.', 'tbsh-safespot-restore-points' ),
	};

	const CHECK_ICONS = { pass: 'yes-alt', warn: 'warning', fail: 'dismiss', info: 'info-outline' };

	function confirmLabel( pre ) {
		const version = pre.target_version;
		switch ( pre.direction ) {
			case 'downgrade':
				/* translators: %s: version. */
				return sprintf( __( 'Roll back to %s', 'tbsh-safespot-restore-points' ), version );
			case 'reinstall':
			case 'install':
				/* translators: %s: version. */
				return sprintf( __( 'Reinstall %s', 'tbsh-safespot-restore-points' ), version );
			default:
				/* translators: %s: version. */
				return sprintf( __( 'Restore version %s', 'tbsh-safespot-restore-points' ), version );
		}
	}

	function renderDiff( diff ) {
		if ( ! diff ) {
			return '';
		}
		const c = diff.counts;
		const groups = [
			[ 'modified', __( 'Will change', 'tbsh-safespot-restore-points' ) ],
			[ 'restored', __( 'Will be added back', 'tbsh-safespot-restore-points' ) ],
			[ 'removed', __( 'Will be removed', 'tbsh-safespot-restore-points' ) ],
		];
		if ( ! c.modified && ! c.restored && ! c.removed ) {
			return '<p class="safespot-diff-summary"><span class="dashicons dashicons-yes" aria-hidden="true"></span> ' + esc( __( 'The files on disk are identical to this SafeSpot.', 'tbsh-safespot-restore-points' ) ) + '</p>';
		}
		let html =
			'<details class="safespot-diff"><summary>' +
			esc(
				/* translators: 1: changed files, 2: added files, 3: removed files, 4: unchanged files. */
				sprintf( __( 'File changes: %1$d changed, %2$d added back, %3$d removed (%4$d unchanged)', 'tbsh-safespot-restore-points' ), c.modified, c.restored, c.removed, c.unchanged )
			) +
			'</summary>';
		groups.forEach( ( [ key, label ] ) => {
			if ( diff[ key ] && diff[ key ].length ) {
				html += '<h4>' + esc( label ) + ' (' + esc( c[ key ] ) + ')</h4><ul>' + diff[ key ].map( ( path ) => '<li><code>' + esc( path ) + '</code></li>' ).join( '' ) + '</ul>';
			}
		} );
		if ( diff.truncated ) {
			html += '<p class="description">' + esc( __( 'List shortened.', 'tbsh-safespot-restore-points' ) ) + '</p>';
		}
		return html + '</details>';
	}

	function renderPreflight( pre, item ) {
		const point = pre.point;
		const noun = 'theme' === point.type ? __( 'theme', 'tbsh-safespot-restore-points' ) : __( 'plugin', 'tbsh-safespot-restore-points' );
		let html =
			'<div class="safespot-compare">' +
			'<div class="safespot-compare__side"><span class="safespot-compare__label">' +
			esc( __( 'Current', 'tbsh-safespot-restore-points' ) ) +
			'</span><span class="safespot-compare__version">' +
			esc( pre.current_version || __( 'Not installed', 'tbsh-safespot-restore-points' ) ) +
			'</span><span class="safespot-compare__meta">' +
			esc( pre.status_label ) +
			'</span></div>' +
			'<span class="dashicons dashicons-arrow-right-alt safespot-compare__arrow" aria-hidden="true"></span>' +
			'<div class="safespot-compare__side is-target"><span class="safespot-compare__label">' +
			esc( __( 'SafeSpot', 'tbsh-safespot-restore-points' ) ) +
			'</span><span class="safespot-compare__version">' +
			esc( pre.target_version || '—' ) +
			'</span><span class="safespot-compare__meta">' +
			esc( point.created_human ) +
			'</span></div></div>' +
			'<p class="safespot-direction safespot-direction--' +
			esc( pre.direction ) +
			'">' +
			esc( DIRECTION_TEXT[ pre.direction ] || '' ) +
			'</p>';

		if ( point.note ) {
			html += '<blockquote class="safespot-quote">' + esc( point.note ) + '</blockquote>';
		}

		html += '<h3 class="safespot-subheading">' + esc( __( 'Pre-restore checks', 'tbsh-safespot-restore-points' ) ) + '</h3><ul class="safespot-checks">';
		pre.checks.forEach( ( check ) => {
			html +=
				'<li class="safespot-check safespot-check--' +
				esc( check.status ) +
				'"><span class="dashicons dashicons-' +
				esc( CHECK_ICONS[ check.status ] || 'info-outline' ) +
				'" aria-hidden="true"></span><div><strong>' +
				esc( check.label ) +
				'</strong><span class="screen-reader-text">: ' +
				esc( check.status ) +
				'</span> <span>' +
				esc( check.message ) +
				'</span></div></li>';
		} );
		html += '</ul>';

		html += renderDiff( pre.diff );

		if ( pre.has_settings ) {
			html +=
				'<fieldset class="safespot-fieldset safespot-mode"><legend>' +
				esc( __( 'What to restore', 'tbsh-safespot-restore-points' ) ) +
				'</legend><label><input type="radio" name="safespot-mode" value="files" checked /> ' +
				esc( __( 'Files only', 'tbsh-safespot-restore-points' ) ) +
				'</label><label><input type="radio" name="safespot-mode" value="files_settings" /> ' +
				esc(
					/* translators: %d: number of settings rows. */
					sprintf( __( 'Files + settings (%d option rows)', 'tbsh-safespot-restore-points' ), point.settings_count )
				) +
				'</label><p class="description">' +
				esc( __( 'Settings restore overwrites the matching wp_options rows. Custom database tables are not included.', 'tbsh-safespot-restore-points' ) ) +
				'</p></fieldset>';
		} else {
			html += '<p class="description">' + esc( __( 'Only files are restored. Database changes made by a newer version (custom tables, migrated options) are not undone.', 'tbsh-safespot-restore-points' ) ) + '</p>';
		}

		if ( pre.installed ) {
			html +=
				'<p><label><input type="checkbox" id="safespot-pause" ' +
				( 'downgrade' === pre.direction || pre.hold ? 'checked' : '' ) +
				' /> ' +
				esc(
					/* translators: %s: "plugin" or "theme". */
					sprintf( __( 'Pause updates for this %s afterwards, so it is not updated straight back', 'tbsh-safespot-restore-points' ), noun )
				) +
				'</label></p>';
		}

		if ( pre.will_backup ) {
			html += '<p class="safespot-info"><span class="dashicons dashicons-shield" aria-hidden="true"></span> ' + esc( __( 'A SafeSpot of the current version is saved first, so you can undo this restore.', 'tbsh-safespot-restore-points' ) ) + '</p>';
		}

		return html;
	}

	let restoreInProgress = false;

	window.addEventListener( 'beforeunload', ( event ) => {
		if ( restoreInProgress ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	async function openRestore( item, point ) {
		Modal.open( {
			/* translators: %s: plugin/theme name. */
			title: sprintf( __( 'Restore %s', 'tbsh-safespot-restore-points' ), item.name ),
			body: '<p class="safespot-loading"><span class="spinner is-active" aria-hidden="true"></span> ' + esc( __( 'Running pre-restore checks…', 'tbsh-safespot-restore-points' ) ) + '</p>',
			confirmLabel: __( 'Restore', 'tbsh-safespot-restore-points' ),
			confirmDisabled: true,
		} );

		let pre;
		try {
			pre = await apiFetch( { path: NS + '/points/' + point.id + '/preflight' } );
		} catch ( error ) {
			Modal.body.innerHTML = '';
			Modal.setError( errorMessage( error ) );
			return;
		}

		Modal.body.innerHTML = renderPreflight( pre, item );
		const label = confirmLabel( pre );
		Modal.setConfirm( label, ! pre.can_restore );

		Modal.setHandler( async () => {
			const modeInput = $( 'input[name="safespot-mode"]:checked', Modal.body );
			const pauseInput = $( '#safespot-pause', Modal.body );
			const data = {
				mode: modeInput ? modeInput.value : 'files',
				pause_updates: pauseInput ? pauseInput.checked : false,
			};

			const steps = [
				pre.will_backup ? __( 'Saving a safety SafeSpot of the current version…', 'tbsh-safespot-restore-points' ) : __( 'Verifying the archive…', 'tbsh-safespot-restore-points' ),
				__( 'Verifying the archive checksum…', 'tbsh-safespot-restore-points' ),
				__( 'Replacing files…', 'tbsh-safespot-restore-points' ),
				__( 'Checking that the site still loads…', 'tbsh-safespot-restore-points' ),
			];
			let step = 0;
			Modal.setBusy( true, steps[ 0 ] );
			Modal.setStoppable( true );
			const timer = window.setInterval( () => {
				step = Math.min( step + 1, steps.length - 1 );
				Modal.setBusy( true, steps[ step ] );
				// The server refuses to stop once files are being replaced.
				Modal.setStoppable( step < 2 );
			}, 3500 );
			restoreInProgress = true;

			try {
				const response = await apiFetch( { path: NS + '/points/' + point.id + '/restore', method: 'POST', data } );
				window.clearInterval( timer );
				restoreInProgress = false;
				Modal.close();
				replaceRow( response );
				const message = [ response.message ].concat( response.warnings || [] ).join( ' ' );
				Notices.show(
					response.warnings && response.warnings.length ? 'warning' : 'success',
					message,
					response.can_undo ? [ { label: __( 'Undo this restore', 'tbsh-safespot-restore-points' ), onClick: () => rollbackLast( item.name ) } ] : []
				);
			} catch ( error ) {
				window.clearInterval( timer );
				restoreInProgress = false;
				if ( handledCancel( error ) ) {
					return;
				}
				Modal.setBusy( false );
				Modal.setConfirm( label, true );
				if ( error && error.data && error.data.html ) {
					replaceRow( error.data );
				}
				if ( isCrash( error ) ) {
					const button = document.createElement( 'button' );
					button.type = 'button';
					button.className = 'button button-primary';
					button.textContent = __( 'Roll back this restore', 'tbsh-safespot-restore-points' );
					button.addEventListener( 'click', () => rollbackLast( item.name, true ) );
					const wrap = document.createElement( 'p' );
					wrap.appendChild( button );
					Modal.setError( __( 'The server stopped responding during the restore. The restored version may have caused a fatal error. You can roll back to the safety SafeSpot now.', 'tbsh-safespot-restore-points' ), wrap );
				} else {
					Modal.setError( errorMessage( error ), busyExtra( error ) );
				}
			}
		} );
	}

	function rollbackLast( name, skipConfirm = false ) {
		const run = async () => {
			Modal.setBusy( true, __( 'Rolling back…', 'tbsh-safespot-restore-points' ) );
			try {
				const response = await apiFetch( { path: NS + '/rollback', method: 'POST' } );
				Modal.close();
				replaceRow( response );
				$$( '[data-safespot-action="rollback-last"], .safespot-recovery-notice' ).forEach( ( el ) => el.remove() );
				Notices.show( 'success', response.message );
			} catch ( error ) {
				Modal.setBusy( false );
				Modal.setError( errorMessage( error ) );
			}
		};

		if ( skipConfirm && Modal.body.closest( 'dialog' ).open ) {
			run();
			return;
		}

		Modal.open( {
			title: __( 'Roll back the last restore?', 'tbsh-safespot-restore-points' ),
			body:
				'<p>' +
				esc(
					/* translators: %s: plugin/theme name. */
					sprintf( __( '%s will be put back exactly as it was before the last restore, using the automatic safety SafeSpot.', 'tbsh-safespot-restore-points' ), name || '' )
				) +
				'</p>',
			confirmLabel: __( 'Roll back', 'tbsh-safespot-restore-points' ),
			onConfirm: run,
		} );
	}

	function openNote( item, point ) {
		Modal.open( {
			/* translators: %s: version. */
			title: sprintf( __( 'Edit note – %s', 'tbsh-safespot-restore-points' ), item.name + ' ' + point.version ),
			body:
				'<p><label for="safespot-edit-note" class="safespot-label">' +
				esc( __( 'Note', 'tbsh-safespot-restore-points' ) ) +
				'</label><textarea id="safespot-edit-note" class="large-text" rows="3" maxlength="1000">' +
				esc( point.note ) +
				'</textarea></p>',
			confirmLabel: __( 'Save note', 'tbsh-safespot-restore-points' ),
			onConfirm: async () => {
				Modal.setBusy( true, __( 'Saving…', 'tbsh-safespot-restore-points' ) );
				try {
					const response = await apiFetch( {
						path: NS + '/points/' + point.id,
						method: 'PATCH',
						data: { note: $( '#safespot-edit-note', Modal.body ).value },
					} );
					Modal.close();
					replaceRow( response );
					Notices.show( 'success', response.message );
				} catch ( error ) {
					Modal.setBusy( false );
					Modal.setError( errorMessage( error ) );
				}
			},
		} );
	}

	async function toggleLock( button, point ) {
		button.disabled = true;
		try {
			const response = await apiFetch( {
				path: NS + '/points/' + point.id,
				method: 'PATCH',
				data: { locked: ! point.locked },
			} );
			replaceRow( response );
			Notices.show( 'success', response.message );
		} catch ( error ) {
			button.disabled = false;
			Notices.show( 'error', errorMessage( error ) );
		}
	}

	function openDelete( item, point ) {
		let body =
			'<p>' +
			esc(
				/* translators: 1: plugin/theme name, 2: version, 3: date. */
				sprintf( __( 'Delete the SafeSpot of %1$s %2$s saved on %3$s? The archive is removed permanently.', 'tbsh-safespot-restore-points' ), item.name, point.version, point.date )
			) +
			'</p>';
		if ( point.locked ) {
			body += '<div class="notice notice-warning inline"><p>' + esc( __( 'This SafeSpot is locked to protect it from cleanup.', 'tbsh-safespot-restore-points' ) ) + '</p></div>';
		}

		Modal.open( {
			title: __( 'Delete SafeSpot?', 'tbsh-safespot-restore-points' ),
			body,
			confirmLabel: __( 'Delete permanently', 'tbsh-safespot-restore-points' ),
			destructive: true,
			onConfirm: async () => {
				Modal.setBusy( true, __( 'Deleting…', 'tbsh-safespot-restore-points' ) );
				try {
					const response = await apiFetch( { path: NS + '/points/' + point.id, method: 'DELETE' } );
					Modal.close();
					replaceRow( response );
					Notices.show( 'success', response.message );
				} catch ( error ) {
					Modal.setBusy( false );
					Modal.setError( errorMessage( error ) );
				}
			},
		} );
	}

	async function setExclusion( input, type, slug, excluded ) {
		input.disabled = true;
		try {
			const response = await apiFetch( { path: NS + '/exclusions', method: 'POST', data: { type, slug, excluded } } );
			Notices.show( excluded || false !== response.auto_enabled ? 'success' : 'warning', response.message );
			return true;
		} catch ( error ) {
			Notices.show( 'error', errorMessage( error ) );
			return false;
		} finally {
			input.disabled = false;
		}
	}

	async function resumeUpdates( button, type, slug ) {
		button.disabled = true;
		try {
			const response = await apiFetch( { path: NS + '/holds', method: 'POST', data: { type, slug, hold: false } } );
			replaceRow( response );
			const chip = button.closest( '.safespot-chip' );
			if ( chip ) {
				chip.remove();
			}
			Notices.show( 'success', response.message );
		} catch ( error ) {
			button.disabled = false;
			Notices.show( 'error', errorMessage( error ) );
		}
	}

	/**
	 * Queues items and drives the queue from the browser until it finishes.
	 *
	 * @param {Array}       items    Items [{ type, slug }].
	 * @param {HTMLElement} statusEl Status container.
	 * @param {HTMLElement} trigger  Button that started the run.
	 */
	async function runQueue( items, statusEl, trigger ) {
		if ( ! items.length ) {
			return;
		}
		trigger.disabled = true;
		statusEl.hidden = false;

		const render = ( status ) => {
			/* translators: 1: done count, 2: total count. */
			const text = sprintf( __( 'Saving SafeSpots… %1$d of %2$d', 'tbsh-safespot-restore-points' ), status.done, status.total );
			statusEl.innerHTML =
				'<div class="safespot-progress safespot-progress--determinate" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' +
				esc( status.progress ) +
				'" aria-label="' +
				esc( text ) +
				'"><span class="safespot-progress__bar" style="width:' +
				esc( status.progress ) +
				'%"></span></div><p>' +
				esc( text ) +
				'</p>';
		};

		try {
			let status = await apiFetch( { path: NS + '/queue', method: 'POST', data: { items } } );
			render( status );
			while ( status.running ) {
				status = await apiFetch( { path: NS + '/queue/run', method: 'POST' } );
				render( status );
			}

			statusEl.hidden = true;
			if ( ! status.total ) {
				Notices.show( 'info', __( 'Bulk saving was cancelled.', 'tbsh-safespot-restore-points' ) );
				for ( const item of items ) {
					await refreshItem( item.type, item.slug );
				}
				return;
			}
			const failed = status.failed || [];
			const unchanged = status.unchanged || 0;
			const saved = Math.max( 0, status.done - failed.length - unchanged );
			let summary = failed.length
				? /* translators: 1: number saved, 2: number failed. */
				  sprintf( __( '%1$d SafeSpots saved, %2$d failed. See the Activity Log for details.', 'tbsh-safespot-restore-points' ), saved, failed.length )
				: /* translators: %d: number of SafeSpots. */
				  sprintf( _n( '%d SafeSpot saved.', '%d SafeSpots saved.', saved, 'tbsh-safespot-restore-points' ), saved );
			if ( unchanged ) {
				summary +=
					' ' +
					/* translators: %d: number of items. */
					sprintf( _n( '%d item had not changed since its last SafeSpot, so no duplicate was saved.', '%d items had not changed since their last SafeSpot, so no duplicates were saved.', unchanged, 'tbsh-safespot-restore-points' ), unchanged );
			}
			Notices.show( failed.length ? 'warning' : 'success', summary );

			for ( const item of items ) {
				await refreshItem( item.type, item.slug );
			}
			$$( '.safespot-pending li' ).forEach( ( li ) => li.classList.add( 'is-done' ) );
		} catch ( error ) {
			statusEl.hidden = true;
			Notices.show( 'error', errorMessage( error ) );
			busyExtra( error );
		} finally {
			trigger.disabled = false;
			updateBulkButton();
		}
	}

	function clearLog() {
		Modal.open( {
			title: __( 'Clear the activity log?', 'tbsh-safespot-restore-points' ),
			body: '<p>' + esc( __( 'All log entries are deleted. SafeSpots themselves are not affected.', 'tbsh-safespot-restore-points' ) ) + '</p>',
			confirmLabel: __( 'Clear log', 'tbsh-safespot-restore-points' ),
			destructive: true,
			onConfirm: async () => {
				Modal.setBusy( true, __( 'Clearing…', 'tbsh-safespot-restore-points' ) );
				try {
					const response = await apiFetch( { path: NS + '/log', method: 'DELETE' } );
					Modal.close();
					$$( '.safespot-log, .tablenav.bottom, [data-safespot-action="clear-log"]' ).forEach( ( el ) => el.remove() );
					const empty = document.createElement( 'div' );
					empty.className = 'safespot-empty';
					empty.innerHTML = '<span class="dashicons dashicons-list-view" aria-hidden="true"></span><p>' + esc( __( 'No activity recorded yet.', 'tbsh-safespot-restore-points' ) ) + '</p>';
					$( '.safespot-main' ).appendChild( empty );
					Notices.show( 'success', response.message );
				} catch ( error ) {
					Modal.setBusy( false );
					Modal.setError( errorMessage( error ) );
				}
			},
		} );
	}

	async function saveSettings( form ) {
		const data = {};
		$$( 'input[name]', form ).forEach( ( input ) => {
			data[ input.name ] = 'checkbox' === input.type ? input.checked : input.value;
		} );
		const submit = $( '[type="submit"]', form );
		submit.disabled = true;
		try {
			const response = await apiFetch( { path: NS + '/settings', method: 'POST', data } );
			config.settingsSnapshot = !! response.settings.settings_snapshot;
			Notices.show( 'success', response.message );
		} catch ( error ) {
			Notices.show( 'error', errorMessage( error ) );
		} finally {
			submit.disabled = false;
		}
	}

	/* ------------------------------------------------------------------ *
	 * Event wiring
	 * ------------------------------------------------------------------ */

	document.addEventListener( 'click', ( event ) => {
		const pointBox = event.target.closest( '.safespot-point-select' );
		if ( pointBox ) {
			togglePointBox( pointBox, event.shiftKey );
			return;
		}

		// Job controls must work while a dialog is busy (that is when they are needed).
		const jobButton = event.target.closest( '[data-safespot-action^="job-"]' );
		if ( jobButton ) {
			const jobAction = jobButton.dataset.safespotAction;
			if ( 'job-stop' === jobAction ) {
				jobButton.disabled = true;
				Modal.setBusy( true, __( 'Stopping…', 'tbsh-safespot-restore-points' ) );
				Jobs.cancel( false ).then( ( response ) => {
					if ( ! response ) {
						Modal.setStoppable( false );
					}
				} );
			} else if ( 'job-force' === jobAction ) {
				// eslint-disable-next-line no-alert
				if ( window.confirm( __( 'Release this operation? Only do this if it has really stopped, for example because the server timed out.', 'tbsh-safespot-restore-points' ) ) ) {
					Jobs.cancel( true );
				}
			} else {
				Jobs.cancel( false );
			}
			return;
		}

		const target = event.target.closest( '[data-safespot-action], .safespot-rollback-last' );
		if ( ! target || ( Modal && Modal.isBusy() ) ) {
			return;
		}

		if ( target.classList.contains( 'safespot-rollback-last' ) ) {
			event.preventDefault();
			rollbackLast( target.dataset.name );
			return;
		}

		const action = target.dataset.safespotAction;
		const item = itemFrom( target );
		const point = pointFrom( target );

		switch ( action ) {
			case 'toggle': {
				const expanded = 'true' === target.getAttribute( 'aria-expanded' );
				target.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
				document.getElementById( target.getAttribute( 'aria-controls' ) ).hidden = expanded;
				item.tbody.classList.toggle( 'is-expanded', ! expanded );
				break;
			}
			case 'save':
				openSave( item );
				break;
			case 'restore':
				openRestore( item, point );
				break;
			case 'note':
				openNote( item, point );
				break;
			case 'lock':
				toggleLock( target, point );
				break;
			case 'delete':
				openDelete( item, point );
				break;
			case 'resume':
				resumeUpdates( target, item ? item.type : target.dataset.type, item ? item.slug : target.dataset.slug );
				break;
			case 'include':
				setExclusion( target, target.dataset.type, target.dataset.slug, false ).then( ( ok ) => ok && target.closest( '.safespot-chip' ).remove() );
				break;
			case 'rollback-last':
				rollbackLast( target.dataset.name );
				break;
			case 'bulk-save':
				runQueue( selectedItems(), $( '.safespot-queue-status' ), target );
				break;
			case 'save-pending':
				runQueue(
					$$( '.safespot-pending li' ).map( ( li ) => ( { type: li.dataset.type, slug: li.dataset.slug } ) ),
					$( '.safespot-queue-status' ),
					target
				);
				break;
			case 'clear-log':
				clearLog();
				break;
			case 'bulk-lock':
				bulkLock( target, true );
				break;
			case 'bulk-unlock':
				bulkLock( target, false );
				break;
			case 'bulk-delete':
				openBulkDelete();
				break;
			case 'clear-selection':
				clearPointSelection();
				break;
		}
	} );

	document.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' === event.key && selectedPoints.size && ! ( Modal && Modal.body.closest( 'dialog' ).open ) && ! event.target.closest( 'input, textarea, select' ) ) {
			clearPointSelection();
		}
	} );

	document.addEventListener( 'change', ( event ) => {
		const target = event.target;

		if ( target.form && 'safespot-settings' === target.form.id && target.name ) {
			$$( '[data-safespot-requires="' + target.name + '"]', target.form ).forEach( ( input ) => {
				input.disabled = ! target.checked;
			} );
		}

		if ( 'auto' === target.dataset.safespotAction ) {
			const item = itemFrom( target );
			setExclusion( target, item.type, item.slug, ! target.checked ).then( ( ok ) => {
				if ( ! ok ) {
					target.checked = ! target.checked;
				}
			} );
			return;
		}

		if ( target.classList.contains( 'safespot-select-all' ) ) {
			$$( 'tbody.safespot-item:not([hidden]) .safespot-select' ).forEach( ( box ) => {
				box.checked = target.checked;
			} );
			updateBulkButton();
			return;
		}

		if ( target.classList.contains( 'safespot-select' ) ) {
			updateBulkButton();
			return;
		}

		if ( target.classList.contains( 'safespot-point-select-all' ) ) {
			$$( '.safespot-point-select', target.closest( '.safespot-history-table' ) ).forEach( ( box ) => {
				const id = parseInt( box.value, 10 );
				if ( target.checked ) {
					selectedPoints.add( id );
				} else {
					selectedPoints.delete( id );
				}
			} );
			syncPointSelection();
			return;
		}

		if ( 'safespot-filter' === target.id ) {
			applyFilters();
		}
	} );

	const search = $( '#safespot-search' );
	if ( search ) {
		search.addEventListener( 'input', applyFilters );
	}

	const settingsForm = $( '#safespot-settings' );
	if ( settingsForm ) {
		settingsForm.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			saveSettings( settingsForm );
		} );
	}

	applyFilters();
	syncPointSelection();
	Jobs.render( config.jobs );

	const expanded = $( 'tbody.safespot-item.is-expanded' );
	if ( expanded ) {
		expanded.scrollIntoView( { block: 'center' } );
		const toggle = $( '.safespot-toggle', expanded );
		if ( toggle ) {
			toggle.focus( { preventScroll: true } );
		}
	}
} )( window.wp, window.safespotAdmin || {} );
