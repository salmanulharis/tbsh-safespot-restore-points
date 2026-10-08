/**
 * SafeSpot quick actions on the native Plugins and Themes screens.
 *
 * @package SafeSpot
 */

/* global safespotIntegrations */
( function ( wp, config ) {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const speak = wp.a11y && wp.a11y.speak ? wp.a11y.speak : function () {};
	const NS = '/safespot/v1';

	/**
	 * Shows a dismissible admin notice below the page heading.
	 *
	 * @param {string} type    Notice type.
	 * @param {string} message Message.
	 */
	function notice( type, message ) {
		const el = document.createElement( 'div' );
		el.className = 'notice notice-' + type + ' is-dismissible safespot-notice';
		const p = document.createElement( 'p' );
		p.textContent = message;
		el.appendChild( p );

		const dismiss = document.createElement( 'button' );
		dismiss.type = 'button';
		dismiss.className = 'notice-dismiss';
		const label = document.createElement( 'span' );
		label.className = 'screen-reader-text';
		label.textContent = __( 'Dismiss this notice.', 'tbsh-safespot-restore-points' );
		dismiss.appendChild( label );
		dismiss.addEventListener( 'click', () => el.remove() );
		el.appendChild( dismiss );

		const anchor = document.querySelector( '.wp-header-end' ) || document.querySelector( '#wpbody-content .wrap > h1' );
		if ( anchor && anchor.parentNode ) {
			anchor.parentNode.insertBefore( el, anchor.nextSibling );
		} else {
			document.body.prepend( el );
		}
		speak( message, 'error' === type ? 'assertive' : 'polite' );
	}

	/**
	 * Saves a SafeSpot and updates the trigger's label.
	 *
	 * @param {HTMLElement} trigger Link or button.
	 * @param {string}      type    plugin|theme.
	 * @param {string}      slug    Slug.
	 */
	async function quickSave( trigger, type, slug ) {
		if ( trigger.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}
		const original = trigger.textContent;
		trigger.setAttribute( 'aria-busy', 'true' );
		trigger.classList.add( 'is-busy' );
		trigger.textContent = __( 'Saving SafeSpot…', 'tbsh-safespot-restore-points' );

		try {
			const response = await apiFetch( { path: NS + '/points', method: 'POST', data: { type, slug } } );
			trigger.textContent = __( 'SafeSpot saved', 'tbsh-safespot-restore-points' );
			notice( 'success', response.message );
			window.setTimeout( () => {
				trigger.textContent = original;
			}, 4000 );
		} catch ( error ) {
			trigger.textContent = original;
			notice( 'error', ( error && error.message ) || __( 'Could not save the SafeSpot.', 'tbsh-safespot-restore-points' ) );
		} finally {
			trigger.removeAttribute( 'aria-busy' );
			trigger.classList.remove( 'is-busy' );
		}
	}

	document.addEventListener( 'click', async ( event ) => {
		const quick = event.target.closest( '.safespot-quick-save' );
		if ( quick ) {
			event.preventDefault();
			quickSave( quick, quick.dataset.type, quick.dataset.slug );
			return;
		}

		const rollback = event.target.closest( '.safespot-rollback-last' );
		if ( rollback ) {
			event.preventDefault();
			/* translators: %s: plugin/theme name. */
			if ( ! window.confirm( sprintf( __( 'Roll back %s to the version it had before the last restore?', 'tbsh-safespot-restore-points' ), rollback.dataset.name || '' ) ) ) {
				return;
			}
			rollback.disabled = true;
			try {
				const response = await apiFetch( { path: NS + '/rollback', method: 'POST' } );
				const box = rollback.closest( '.safespot-recovery-notice' );
				if ( box ) {
					box.remove();
				}
				notice( 'success', response.message );
			} catch ( error ) {
				rollback.disabled = false;
				notice( 'error', ( error && error.message ) || __( 'The rollback failed.', 'tbsh-safespot-restore-points' ) );
			}
		}
	} );

	/*
	 * Appearance → Themes: add buttons to the theme details overlay.
	 */
	const themes = config.themes || {};
	if ( ! Object.keys( themes ).length ) {
		return;
	}

	function currentTheme() {
		const params = new URLSearchParams( window.location.search );
		return params.get( 'theme' );
	}

	function decorateOverlay() {
		const overlay = document.querySelector( '.theme-overlay .theme-wrap' );
		if ( ! overlay || overlay.querySelector( '.safespot-theme-actions' ) ) {
			return;
		}
		const stylesheet = currentTheme();
		if ( ! stylesheet || ! themes[ stylesheet ] ) {
			return;
		}
		const actions = overlay.querySelector( '.theme-actions' );
		if ( ! actions ) {
			return;
		}

		const info = themes[ stylesheet ];
		const wrap = document.createElement( 'div' );
		wrap.className = 'safespot-theme-actions';

		const save = document.createElement( 'button' );
		save.type = 'button';
		save.className = 'button';
		save.textContent = __( 'Save SafeSpot', 'tbsh-safespot-restore-points' );
		save.addEventListener( 'click', () => quickSave( save, 'theme', stylesheet ) );
		wrap.appendChild( save );

		const history = document.createElement( 'a' );
		history.className = 'button';
		history.href = info.url;
		/* translators: %d: number of SafeSpots. */
		history.textContent = info.count ? sprintf( __( 'Restore (%d)', 'tbsh-safespot-restore-points' ), info.count ) : __( 'SafeSpots', 'tbsh-safespot-restore-points' );
		wrap.appendChild( history );

		actions.prepend( wrap );
	}

	const observer = new window.MutationObserver( decorateOverlay );
	observer.observe( document.body, { childList: true, subtree: true } );
	decorateOverlay();
} )( window.wp, window.safespotIntegrations || {} );
