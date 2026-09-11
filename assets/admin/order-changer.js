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
	 * Post the change, then reload.
	 *
	 * A successful change moves more of the page than this widget: FluentCart's
	 * own header badge, its activity feed, the order-items panel's fulfilment
	 * marker and — when the new status carries a linked shipping status — the
	 * other axis of this very control. Re-rendering our own markup alone would
	 * leave every one of those stale and disagreeing with the database, which
	 * is a worse failure than a flash of reload: the operator would be reading
	 * numbers that are no longer true. The error path never reloads, because
	 * there the whole point is to keep the page exactly as it was and explain.
	 *
	 * @param {HTMLElement} root  The `[data-ys-changer]` block.
	 * @param {string}      axis  'order' or 'shipping'.
	 * @param {string}      slug  Target status.
	 * @return {void}
	 */
	function submit( root, axis, slug ) {
		var box = root.querySelector( '[data-ys-changer-message]' );
		var orderId = root.getAttribute( 'data-ys-order' );

		if ( ! slug ) {
			say( box, t( 'pick' ), 'error' );
			return;
		}

		if ( busy ) {
			return;
		}

		busy = true;
		root.classList.add( 'is-busy' );
		say( box, t( 'working' ), 'info' );

		window.fetch( cfg.restUrl + 'orders/' + encodeURIComponent( orderId ) + '/change', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.restNonce
			},
			body: JSON.stringify( { axis: axis, status: slug } )
		} ).then( function ( response ) {
			return response.json().then( function ( payload ) {
				return { ok: response.ok, payload: payload || {} };
			} );
		} ).then( function ( result ) {
			if ( ! result.ok ) {
				// A 422 from this plugin carries the same sentence the REST
				// veto would have produced for the same move; a 400 from
				// FluentCart carries core's own words. Either way the operator
				// reads why, beside the control they used.
				busy = false;
				root.classList.remove( 'is-busy' );
				say( box, result.payload.message || t( 'failed' ), 'error' );
				return;
			}

			say( box, result.payload.message || '', 'success' );
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
			submit( root, next, button.getAttribute( 'data-ys-changer-status' ) || '' );
			return;
		}

		var axis = button.getAttribute( 'data-ys-changer-go' );
		var select = root.querySelector( '[data-ys-changer-select="' + axis + '"]' );

		submit( root, axis, select ? select.value : '' );
	} );

	// Choosing a status and pressing Return is what a keyboard user expects a
	// select beside a button to do, and the widget is not inside a <form> that
	// could do it for us.
	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' !== event.key ) {
			return;
		}

		var select = event.target && event.target.closest
			? event.target.closest( '[data-ys-changer-select]' )
			: null;

		if ( ! select ) {
			return;
		}

		var root = select.closest( '[data-ys-changer]' );

		if ( ! root ) {
			return;
		}

		event.preventDefault();
		submit( root, select.getAttribute( 'data-ys-changer-select' ), select.value );
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
