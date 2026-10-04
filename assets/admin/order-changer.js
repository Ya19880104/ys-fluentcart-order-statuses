/* global ysFctStatusChanger */
/**
 * The status control on a FluentCart order page.
 *
 * The markup it drives is produced by `Admin\OrderWidget` and handed to
 * FluentCart as a `type: html` widget, which the SPA injects with `innerHTML`.
 * `innerHTML` does not execute script nodes, so there is no way to ship the
 * behaviour with the markup: it has to be a real enqueued script, and it has to
 * survive the widget appearing, disappearing and being rebuilt as the operator
 * moves around the app.
 *
 * Hence one delegated listener on `document` and no element references kept
 * anywhere. Nothing here runs, or costs anything, until a click lands inside a
 * `[data-ys-changer]` block.
 *
 * Every order-status write from this script goes through `changeStatus()`:
 * the confirmation, the request and the reading of its answer are one function,
 * whichever control the operator used.
 */
( function () {
	'use strict';

	var cfg = window.ysFctStatusChanger || {};
	var i18n = cfg.i18n || {};

	if ( ! cfg.restUrl ) {
		return;
	}

	var busy = false;

	function t( key ) {
		return Object.prototype.hasOwnProperty.call( i18n, key ) ? i18n[ key ] : key;
	}

	function say( box, message, kind ) {
		if ( ! box ) {
			return;
		}

		box.textContent = message || '';
		box.className = 'ys-fct-changer-message' + ( message ? ' is-' + ( kind || 'info' ) : '' );
	}

	/**
	 * Ask before a move the server will only make when it is confirmed.
	 *
	 * @param {string} kind  The target slug when it needs confirming, else ''.
	 * @param {string} label The target's label, for the question.
	 * @return {boolean} Whether to go ahead.
	 */
	function confirmed( kind, label ) {
		if ( ! kind ) {
			return true;
		}

		var template = 'canceled' === kind ? t( 'confirmCanceled' ) : t( 'confirmCompleted' );

		return window.confirm( String( template ).replace( '%s', label || kind ) );
	}

	/**
	 * The one write path.
	 *
	 * Resolves with `null` when the operator declined the confirmation (nothing
	 * was sent), with the server's order state on success, and rejects with an
	 * Error whose message is the sentence to show beside the control.
	 *
	 * @param {Object} move `{ orderId, axis, slug, label, confirm }`.
	 * @return {Promise<Object|null>}
	 */
	function changeStatus( move ) {
		if ( ! confirmed( move.confirm, move.label ) ) {
			return Promise.resolve( null );
		}

		var body = { axis: move.axis, status: move.slug };

		if ( move.confirm ) {
			body.confirmed = true;
		}

		return window.fetch( cfg.restUrl + 'orders/' + encodeURIComponent( move.orderId ) + '/change', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.restNonce
			},
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( payload ) {
				if ( ! response.ok ) {
					// A 422 from this plugin carries the same sentence the REST
					// veto would have produced for the same move, a 409 asks for
					// the confirmation, and a 400 from FluentCart carries core's
					// own words. Either way the operator reads why.
					throw new Error( ( payload && payload.message ) || t( 'failed' ) );
				}

				return payload || {};
			} );
		} );
	}

	/**
	 * The sidebar card's submit: busy state, then `changeStatus()`, then reload.
	 *
	 * A successful change moves more of the page than this widget: FluentCart's
	 * own header badge, its activity feed, the order-items panel's fulfilment
	 * marker and — when the new status carries a linked shipping status — the
	 * other axis of this very control. Re-rendering our own markup alone would
	 * leave every one of those stale and disagreeing with the database, which
	 * is a worse failure than a flash of reload. The error path never reloads,
	 * because there the whole point is to keep the page as it was and explain.
	 *
	 * @param {HTMLElement} root The `[data-ys-changer]` block.
	 * @param {Object}      move `{ axis, slug, label, confirm }`.
	 * @return {void}
	 */
	function submit( root, move ) {
		var box = root.querySelector( '[data-ys-changer-message]' );

		if ( ! move.slug ) {
			say( box, t( 'pick' ), 'error' );
			return;
		}

		if ( busy ) {
			return;
		}

		move.orderId = root.getAttribute( 'data-ys-order' );

		busy = true;
		root.classList.add( 'is-busy' );
		say( box, t( 'working' ), 'info' );

		changeStatus( move ).then( function ( state ) {
			if ( null === state ) {
				busy = false;
				root.classList.remove( 'is-busy' );
				say( box, '' );
				return;
			}

			say( box, state.message || '', 'success' );
			window.location.reload();
		} ).catch( function ( error ) {
			busy = false;
			root.classList.remove( 'is-busy' );
			say( box, ( error && error.message ) || t( 'failed' ), 'error' );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		var button = target.closest( '[data-ys-changer-go], [data-ys-changer-next]' );

		if ( ! button ) {
			return;
		}

		var root = button.closest( '[data-ys-changer]' );

		if ( ! root ) {
			return;
		}

		event.preventDefault();

		var next = button.getAttribute( 'data-ys-changer-next' );

		if ( next ) {
			submit( root, {
				axis: next,
				slug: button.getAttribute( 'data-ys-changer-status' ) || '',
				label: button.getAttribute( 'data-ys-changer-label' ) || '',
				confirm: button.getAttribute( 'data-ys-confirm' ) || ''
			} );
			return;
		}

		var axis = button.getAttribute( 'data-ys-changer-go' );
		var select = root.querySelector( '[data-ys-changer-select="' + axis + '"]' );
		var option = select && select.selectedIndex >= 0 ? select.options[ select.selectedIndex ] : null;

		// The button is the only trigger. Return in the select used to submit
		// too, which made it one keystroke from Completed or Canceled.
		submit( root, {
			axis: axis,
			slug: select ? select.value : '',
			label: option ? option.textContent : '',
			confirm: option ? ( option.getAttribute( 'data-ys-confirm' ) || '' ) : ''
		} );
	} );

	// A stale message after the operator changes their mind is noise, and a
	// red one is alarming noise.
	document.addEventListener( 'change', function ( event ) {
		var select = event.target && event.target.closest
			? event.target.closest( '[data-ys-changer-select]' )
			: null;

		if ( ! select ) {
			return;
		}

		var root = select.closest( '[data-ys-changer]' );

		if ( root ) {
			say( root.querySelector( '[data-ys-changer-message]' ), '' );
		}
	} );
}() );
