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
 * Hence delegated listeners on `document` and no element references kept
 * anywhere. Nothing here runs, or costs anything, until a click lands inside a
 * `[data-ys-changer]` block or on FluentCart's More Action menu — apart from
 * one MutationObserver whose callback only queues a look for a newly rendered
 * card's hidden fallback (see "the card's fallback" at the end).
 *
 * Since 0.7 the card shows where the order stands and its next step; any other
 * order status is chosen from the More Action entry this script adds, and the
 * card's own list is shown only when that entry cannot be used.
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

	// ── after a change: tell, then bring the page up to date ─────────────────

	var leaving = false;
	var STAY_WAIT = 5000;

	window.addEventListener( 'beforeunload', function () {
		leaving = true;
	} );

	function hardReload() {
		if ( ! leaving ) {
			leaving = true;
			window.location.reload();
		}
	}

	/** FluentCart's Vue app, when the page has one. Production builds keep `__vue_app__` on the mount. */
	function vueApp() {
		var mount = document.getElementById( 'fluent_cart_plugin_app' );

		return mount && mount.__vue_app__ ? mount.__vue_app__ : null;
	}

	/**
	 * The first component instance `test` accepts, walking the rendered tree
	 * from the app's root. Bounded, and every step optional: any of these
	 * internals can change in a FluentCart release, and a miss only means the
	 * caller falls back to something plainer.
	 *
	 * @param {Function} test Receives a component instance.
	 * @return {Object|null}
	 */
	function findInstance( test ) {
		var app = vueApp();
		var root = app && app._instance ? app._instance : null;

		if ( ! root ) {
			return null;
		}

		var stack = [ root.subTree ];
		var visited = 0;

		while ( stack.length && visited < 20000 ) {
			var vnode = stack.pop();

			visited++;

			if ( ! vnode || 'object' !== typeof vnode ) {
				continue;
			}

			if ( vnode.component ) {
				try {
					if ( test( vnode.component ) ) {
						return vnode.component;
					}
				} catch ( e ) {
					// A component that throws on inspection is simply not the one.
				}

				stack.push( vnode.component.subTree );
				continue;
			}

			if ( vnode.suspense && vnode.suspense.activeBranch ) {
				stack.push( vnode.suspense.activeBranch );
			}

			if ( Array.isArray( vnode.children ) ) {
				for ( var i = vnode.children.length - 1; i >= 0; i-- ) {
					stack.push( vnode.children[ i ] );
				}
			}
		}

		return null;
	}

	/** FluentCart's own toast (Element Plus' `$notify`), when it can be reached. */
	function notify( message, kind ) {
		try {
			var app = vueApp();
			var notifier = app && app.config && app.config.globalProperties ? app.config.globalProperties.$notify : null;

			if ( notifier && 'function' === typeof notifier[ kind ] ) {
				notifier[ kind ]( message );
				return true;
			}
		} catch ( e ) {
			// No toast is not worth failing a successful change over.
		}

		return false;
	}

	/**
	 * Ask FluentCart's own order-actions component to reload the order.
	 *
	 * It is the component behind the More Action menu: it has a
	 * `handleCommandAction` method and an `order` prop, and FluentCart's own
	 * dialogs emit `reload` on it after every change. (In 1.6.0 and 1.6.3 the
	 * order screen answers that with `window.location.reload()`.)
	 *
	 * @param {string} orderId Order id.
	 * @return {boolean} Whether the event was emitted.
	 */
	function emitReload( orderId ) {
		var actions = findInstance( function ( instance ) {
			return instance.proxy && 'function' === typeof instance.proxy.handleCommandAction &&
				'function' === typeof instance.emit &&
				instance.props && instance.props.order && String( instance.props.order.id ) === String( orderId );
		} );

		if ( ! actions ) {
			return false;
		}

		try {
			actions.emit( 'reload' );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * `GET orders/{id}/state`. A refusal rejects with an Error carrying the
	 * server's sentence and the HTTP `status`; a network failure rejects
	 * without one.
	 */
	function fetchState( orderId ) {
		return window.fetch( cfg.restUrl + 'orders/' + encodeURIComponent( orderId ) + '/state', {
			method: 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.restNonce }
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( payload ) {
				if ( ! response.ok ) {
					var error = new Error( ( payload && payload.message ) || t( 'notLoaded' ) );

					error.status = response.status;
					throw error;
				}

				return payload || {};
			} );
		} );
	}

	/** Every Order workflow card on the page for this order. */
	function cardsFor( orderId ) {
		return document.querySelectorAll( '[data-ys-changer][data-ys-order="' + String( orderId ).replace( /[^0-9]/g, '' ) + '"]' );
	}

	/** Re-render every Order workflow card for this order from the server's own markup. */
	function refreshCards( orderId ) {
		var cards = cardsFor( orderId );

		if ( ! cards.length ) {
			return Promise.resolve();
		}

		return fetchState( orderId ).then( function ( state ) {
			if ( ! state || 'string' !== typeof state.html || ! state.html ) {
				throw new Error( 'stale' );
			}

			Array.prototype.forEach.call( cards, function ( card ) {
				card.outerHTML = state.html;
			} );
		} );
	}

	/** A change from the card is over: its controls answer again. */
	function release( orderId ) {
		busy = false;

		Array.prototype.forEach.call( cardsFor( orderId ), function ( card ) {
			card.classList.remove( 'is-busy' );
		} );
	}

	/**
	 * What every successful change does next, whichever control made it.
	 *
	 * FluentCart's toast, then FluentCart's own `reload` on its order-actions
	 * component — the same two steps its own status dialogs take. Should a
	 * later FluentCart refresh the order in place instead of reloading the
	 * page, the Order workflow card is brought up to date from the server too.
	 * Anything missing or failing ends in a full page reload: the page must
	 * never go on showing a status the order no longer has.
	 *
	 * @param {string} orderId Order id.
	 * @param {Object} state   The change route's answer.
	 * @return {void}
	 */
	function afterChange( orderId, state ) {
		notify( ( state && state.message ) || t( 'changed' ), 'success' );

		if ( ! emitReload( orderId ) ) {
			hardReload();
		}

		window.setTimeout( function () {
			catchUp( orderId, false );
		}, 1500 );
	}

	/**
	 * Bring the cards up to date in place, unless the page is on its way out.
	 *
	 * `beforeunload` does not prove that it is: FluentCart asks before leaving
	 * an order with unsaved edits, and an operator who answers "Stay" cancels
	 * the unload with no event to say so. A page still here STAY_WAIT ms after
	 * it said it was leaving has stayed: its cards are refreshed and released,
	 * and never reloaded from here again, so that question is not asked twice.
	 *
	 * @param {string}  orderId Order id.
	 * @param {boolean} waited  Whether this is the look after STAY_WAIT.
	 * @return {void}
	 */
	function catchUp( orderId, waited ) {
		if ( leaving && ! waited ) {
			window.setTimeout( function () {
				catchUp( orderId, true );
			}, STAY_WAIT );
			return;
		}

		leaving = false;

		refreshCards( orderId ).then( function () {
			release( orderId );
		}, function () {
			if ( waited ) {
				release( orderId );
				return;
			}

			hardReload();
			catchUp( orderId, false );
		} );
	}

	/**
	 * The sidebar card's submit: busy state, then `changeStatus()`, then
	 * `afterChange()`.
	 *
	 * A successful change moves more of the page than this widget: FluentCart's
	 * own header badge, its activity feed, the order-items panel's fulfilment
	 * marker and — when the new status carries a linked shipping status — the
	 * other axis of this very control. Re-rendering our own markup alone would
	 * leave every one of those stale and disagreeing with the database, which
	 * is why `afterChange()` hands the page back to FluentCart. The error path
	 * never reloads, because there the whole point is to keep the page as it
	 * was and explain.
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
			afterChange( move.orderId, state );
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

	// ── FluentCart's own place: an entry in the More Action menu ─────────────
	//
	// FluentCart has no extension point for order actions, and its status
	// dialog holds only the shipping select. So the entry is added to its menu
	// when the menu is opened, and opens a dialog drawn with the same Element
	// Plus classes as "Update Shipping Status". Nothing here touches the header
	// button group itself: another add-on edits it by text, and its structure
	// is left exactly as FluentCart rendered it.

	var NATIVE = 'data-ys-native-status';

	var ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 21V4"/><path d="M5 4h11l-2.5 4.5L16 13H5"/></svg>';

	var CLOSE_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024" aria-hidden="true" focusable="false"><path fill="currentColor" d="M764.288 214.592 512 466.88 259.712 214.592a31.936 31.936 0 0 0-45.12 45.12L466.752 512 214.528 764.224a31.936 31.936 0 1 0 45.12 45.184L512 557.184l252.288 252.288a31.936 31.936 0 0 0 45.12-45.12L557.12 512.064l252.288-252.352a31.936 31.936 0 1 0-45.12-45.184z"/></svg>';

	var CARET_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024" aria-hidden="true" focusable="false"><path fill="currentColor" d="M831.872 340.864 512 652.672 192.128 340.864a30.592 30.592 0 0 0-42.752 0 29.12 29.12 0 0 0 0 41.6L489.664 714.24a32 32 0 0 0 44.672 0l340.288-331.712a29.12 29.12 0 0 0 0-41.728 30.592 30.592 0 0 0-42.752 0z"/></svg>';

	var polling = false;
	var pollTries = 0;
	var pollTrigger = null;
	var pollOrder = '';
	var dialog = null;

	/** The order on screen, from FluentCart's `#/orders/<id>/view` route, or ''. */
	function viewedOrderId() {
		var match = /^#\/orders\/(\d+)\/view(?:[/?]|$)/.exec( window.location.hash || '' );

		return match ? match[ 1 ] : '';
	}

	/** The More Action trigger an event came from, or null. */
	function moreTrigger( node ) {
		var wrap = node && node.closest ? node.closest( '.fct-order-bulk-action-modal .fct-more-option-wrap' ) : null;

		if ( ! wrap ) {
			return null;
		}

		return wrap.querySelector( '[aria-controls]' ) || wrap.querySelector( '[aria-haspopup], .more-btn, .el-button, [role="button"]' );
	}

	function make( tag, attrs, children ) {
		var node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( 'text' === key ) {
				node.textContent = attrs[ key ];
			} else if ( null !== attrs[ key ] && undefined !== attrs[ key ] && false !== attrs[ key ] ) {
				node.setAttribute( key, true === attrs[ key ] ? '' : attrs[ key ] );
			}
		} );

		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( 'string' === typeof child ? document.createTextNode( child ) : child );
			}
		} );

		return node;
	}

	/** A static icon: the markup is a constant in this file, never data. */
	function icon( className, svg ) {
		var node = make( className ? 'i' : 'div', { class: className || 'icon' } );

		node.innerHTML = svg;

		return node;
	}

	/**
	 * Add the entry to the menu the trigger controls, once.
	 *
	 * @return {boolean} Whether the menu exists (and so has the entry now).
	 */
	function inject( trigger, orderId ) {
		var id = trigger.getAttribute( 'aria-controls' );
		var menu = id ? document.getElementById( id ) : null;

		if ( ! menu ) {
			return false;
		}

		if ( menu.querySelector( '[' + NATIVE + ']' ) ) {
			return true;
		}

		var host = menu;

		for ( var i = 0; i < menu.children.length; i++ ) {
			if ( 'DIV' === menu.children[ i ].tagName ) {
				host = menu.children[ i ];
				break;
			}
		}

		var item = make( 'li', {
			'data-el-collection-item': true,
			'aria-disabled': 'false',
			class: 'el-dropdown-menu__item',
			tabindex: '-1',
			role: 'menuitem'
		}, [ icon( '', ICON ), ' ' + t( 'menuLabel' ) ] );

		item.setAttribute( NATIVE, orderId );
		host.insertBefore( item, host.firstChild );

		return true;
	}

	/**
	 * The menu is created on first open, so look for it a few times.
	 *
	 * More activity on the trigger while a look is running starts its count
	 * again, so a click near the end of a hover's look still gets a full
	 * second. When the look runs out — or `inject()` keeps throwing — while
	 * the menu is open, the entry cannot be added: the menu exists but is not
	 * where `aria-controls` says. The card's own list is shown instead.
	 */
	function scheduleInject( trigger, orderId ) {
		pollTries = 0;
		pollTrigger = trigger;
		pollOrder = orderId;

		if ( polling ) {
			return;
		}

		polling = true;

		( function attempt() {
			var done = false;

			try {
				done = inject( pollTrigger, pollOrder );
			} catch ( e ) {
				done = false;
			}

			if ( done ) {
				polling = false;
				return;
			}

			if ( ++pollTries > 20 ) {
				polling = false;

				if ( 'true' === pollTrigger.getAttribute( 'aria-expanded' ) ) {
					revealFallback( pollOrder );
				}

				return;
			}

			window.setTimeout( attempt, 50 );
		}() );
	}

	function onTriggerActivity( event ) {
		var orderId = viewedOrderId();

		if ( ! orderId ) {
			return;
		}

		var trigger = moreTrigger( event.target );

		if ( trigger ) {
			scheduleInject( trigger, orderId );
		}
	}

	// Capture, because `mouseenter` does not bubble and because the menu's own
	// handlers must not get the chance to swallow the events first.
	[ 'click', 'mouseenter', 'focusin', 'keydown' ].forEach( function ( type ) {
		document.addEventListener( type, onTriggerActivity, true );
	} );

	/**
	 * Close the More Action menu through the dropdown component itself, or —
	 * when that cannot be reached — by toggling its trigger, which is what a
	 * second click on it does.
	 */
	function closeMenu( item ) {
		var menu = item.closest( '[role="menu"]' );
		var trigger = menu && menu.id ? document.querySelector( '[aria-controls="' + menu.id + '"]' ) : null;
		var dropdownEl = trigger ? trigger.closest( '.el-dropdown' ) : null;

		var dropdown = dropdownEl ? findInstance( function ( instance ) {
			return instance.proxy && 'function' === typeof instance.proxy.handleClose &&
				( instance.vnode.el === dropdownEl || ( instance.subTree && instance.subTree.el === dropdownEl ) );
		} ) : null;

		if ( dropdown ) {
			try {
				dropdown.proxy.handleClose();
				return trigger;
			} catch ( e ) {
				// Fall through to the trigger.
			}
		}

		if ( trigger && 'true' === trigger.getAttribute( 'aria-expanded' ) ) {
			trigger.click();
		}

		return trigger;
	}

	function activateItem( event ) {
		var item = event.target && event.target.closest ? event.target.closest( '[' + NATIVE + ']' ) : null;

		if ( ! item ) {
			return;
		}

		if ( 'keydown' === event.type && 'Enter' !== event.key && ' ' !== event.key && 'Spacebar' !== event.key ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();

		var orderId = item.getAttribute( NATIVE ) || viewedOrderId();
		var trigger = closeMenu( item );

		openDialog( orderId, trigger );
	}

	document.addEventListener( 'click', activateItem, true );
	document.addEventListener( 'keydown', activateItem, true );

	// ── the dialog ────────────────────────────────────────────────────────────

	/** Above every Element Plus overlay and popper already on the page. */
	function nextZ() {
		var max = 2000;

		Array.prototype.forEach.call( document.querySelectorAll( '.el-overlay, .el-popper' ), function ( node ) {
			var z = parseInt( window.getComputedStyle( node ).zIndex, 10 );

			if ( z > max ) {
				max = z;
			}
		} );

		return max + 2;
	}

	function closeDialog() {
		if ( ! dialog ) {
			return;
		}

		var current = dialog;

		dialog = null;
		closeList( current );

		if ( current.overlay.parentNode ) {
			current.overlay.parentNode.removeChild( current.overlay );
		}

		if ( current.lockedBody ) {
			document.body.classList.remove( 'el-popup-parent--hidden' );
		}

		if ( current.returnFocus && current.returnFocus.focus ) {
			try {
				current.returnFocus.focus();
			} catch ( e ) {
				// The trigger may have been re-rendered away; focus is a courtesy.
			}
		}
	}

	function setError( d, message ) {
		d.error.textContent = message || '';
		d.error.hidden = ! message;
	}

	function closeList( d ) {
		if ( ! d || ! d.popper ) {
			return;
		}

		if ( d.popper.parentNode ) {
			d.popper.parentNode.removeChild( d.popper );
		}

		d.popper = null;
		d.combo.setAttribute( 'aria-expanded', 'false' );
		d.combo.removeAttribute( 'aria-activedescendant' );
		d.caret.classList.remove( 'is-reverse' );
	}

	function highlight( d, index ) {
		if ( ! d.popper ) {
			return;
		}

		var items = d.popper.querySelectorAll( '[role="option"]' );

		if ( ! items.length ) {
			return;
		}

		d.hover = ( index + items.length ) % items.length;

		Array.prototype.forEach.call( items, function ( item, position ) {
			item.classList.toggle( 'is-hovering', position === d.hover );
		} );

		d.combo.setAttribute( 'aria-activedescendant', items[ d.hover ].id );

		if ( items[ d.hover ].scrollIntoView ) {
			items[ d.hover ].scrollIntoView( { block: 'nearest' } );
		}
	}

	function choose( d, index ) {
		var target = d.targets[ index ];

		if ( ! target ) {
			return;
		}

		d.selected = index;
		d.placeholder.classList.remove( 'is-transparent' );
		d.placeholder.firstChild.textContent = target.label;
		setError( d, '' );
		closeList( d );
		d.combo.focus();
	}

	function openList( d ) {
		if ( d.popper || ! d.targets.length || d.busy ) {
			return;
		}

		var rect = d.wrapper.getBoundingClientRect();
		var options = d.targets.map( function ( target, index ) {
			return make( 'li', {
				id: d.listId + '-' + index,
				class: 'el-select-dropdown__item' + ( index === d.selected ? ' is-selected' : '' ),
				role: 'option',
				'aria-selected': index === d.selected ? 'true' : 'false'
			}, [ make( 'span', { text: target.label } ) ] );
		} );

		var list = make( 'ul', {
			id: d.listId,
			class: 'el-scrollbar__view el-select-dropdown__list',
			role: 'listbox',
			'aria-labelledby': d.labelId
		}, options );

		d.popper = make( 'div', {
			class: 'el-popper is-pure is-light el-tooltip el-select__popper ys-fct-native-popper',
			'data-popper-placement': 'bottom-start',
			style: 'position:fixed;z-index:' + ( d.z + 1 ) + ';top:' + Math.round( rect.bottom + 4 ) + 'px;left:' + Math.round( rect.left ) + 'px;min-width:' + Math.round( rect.width ) + 'px'
		}, [
			make( 'div', { class: 'el-select-dropdown' }, [
				make( 'div', { class: 'el-scrollbar' }, [
					make( 'div', { class: 'el-select-dropdown__wrap el-scrollbar__wrap' }, [ list ] )
				] )
			] )
		] );

		d.popper.addEventListener( 'mousedown', function ( event ) {
			// Keep focus on the combobox while choosing or scrolling with the
			// mouse: a blur would close the list under the pointer.
			event.preventDefault();
		} );

		list.addEventListener( 'click', function ( event ) {
			var option = event.target.closest ? event.target.closest( '[role="option"]' ) : null;

			if ( option ) {
				choose( d, Array.prototype.indexOf.call( list.children, option ) );
			}
		} );

		list.addEventListener( 'mousemove', function ( event ) {
			var option = event.target.closest ? event.target.closest( '[role="option"]' ) : null;

			if ( option ) {
				highlight( d, Array.prototype.indexOf.call( list.children, option ) );
			}
		} );

		document.body.appendChild( d.popper );
		d.combo.setAttribute( 'aria-expanded', 'true' );
		d.caret.classList.add( 'is-reverse' );
		highlight( d, d.selected >= 0 ? d.selected : 0 );
	}

	function onComboKey( d, event ) {
		var key = event.key;

		if ( d.popper ) {
			if ( 'ArrowDown' === key || 'ArrowUp' === key ) {
				event.preventDefault();
				highlight( d, d.hover + ( 'ArrowDown' === key ? 1 : -1 ) );
			} else if ( 'Home' === key || 'End' === key ) {
				event.preventDefault();
				highlight( d, 'Home' === key ? 0 : d.targets.length - 1 );
			} else if ( 'Enter' === key || ' ' === key ) {
				event.preventDefault();
				choose( d, d.hover );
			} else if ( 'Escape' === key ) {
				event.preventDefault();
				event.stopPropagation();
				closeList( d );
			} else if ( 'Tab' === key ) {
				closeList( d );
			}

			return;
		}

		if ( 'ArrowDown' === key || 'ArrowUp' === key || 'Enter' === key || ' ' === key ) {
			event.preventDefault();
			openList( d );
		}
	}

	function submitDialog( d ) {
		if ( d.busy ) {
			return;
		}

		var target = d.targets[ d.selected ];

		if ( ! target ) {
			setError( d, t( 'pick' ) );
			d.combo.focus();
			return;
		}

		d.busy = true;
		d.update.disabled = true;
		d.update.classList.add( 'is-loading', 'is-disabled' );
		setError( d, '' );

		changeStatus( {
			orderId: d.orderId,
			axis: 'order',
			slug: target.slug,
			label: target.label,
			confirm: target.confirm ? target.slug : ''
		} ).then( function ( state ) {
			d.busy = false;
			d.update.disabled = false;
			d.update.classList.remove( 'is-loading', 'is-disabled' );

			if ( null === state ) {
				return;
			}

			closeDialog();
			afterChange( d.orderId, state );
		} ).catch( function ( error ) {
			d.busy = false;
			d.update.disabled = false;
			d.update.classList.remove( 'is-loading', 'is-disabled' );
			setError( d, ( error && error.message ) || t( 'failed' ) );
		} );
	}

	/** Fill the dialog from `GET orders/{id}/state`. */
	function fillDialog( d, state ) {
		var axis = state && state.axes ? state.axes.order : null;

		if ( ! axis ) {
			throw new Error( t( 'notLoaded' ) );
		}

		d.placeholder.firstChild.textContent = axis.label || t( 'noStatus' );
		d.wrapper.classList.remove( 'is-disabled' );
		d.combo.setAttribute( 'aria-disabled', 'false' );

		if ( axis.locked || ! axis.targets || ! axis.targets.length ) {
			d.note.textContent = axis.locked || t( 'nowhere' );
			d.note.hidden = false;
			d.wrapper.classList.add( 'is-disabled' );
			d.combo.setAttribute( 'aria-disabled', 'true' );
			d.footer.hidden = true;
			return;
		}

		d.targets = axis.targets.map( function ( target ) {
			return { slug: String( target.slug ), label: String( target.label ), confirm: !! target.confirm };
		} );
		d.footer.hidden = false;
	}

	/**
	 * The dialog, drawn with the classes of FluentCart's "Update Shipping
	 * Status" dialog so its own stylesheet gives it the same look.
	 *
	 * @param {string}           orderId     Order id.
	 * @param {HTMLElement|null} returnFocus Where focus goes back to on close.
	 */
	function openDialog( orderId, returnFocus ) {
		closeDialog();

		if ( ! orderId ) {
			return;
		}

		var uid = 'ys-fct-native-' + String( Date.now() );
		var z = nextZ();

		var d = {
			orderId: orderId,
			returnFocus: returnFocus || null,
			z: z,
			listId: uid + '-list',
			labelId: uid + '-label',
			targets: [],
			selected: -1,
			hover: 0,
			busy: false,
			popper: null,
			lockedBody: false
		};

		d.placeholder = make( 'div', { class: 'el-select__selected-item el-select__placeholder is-transparent' }, [ make( 'span', { text: t( 'loading' ) } ) ] );
		d.caret = icon( 'el-icon el-select__caret el-select__icon', CARET_ICON );

		d.combo = d.wrapper = make( 'div', {
			class: 'el-select__wrapper el-tooltip__trigger is-disabled',
			tabindex: '0',
			role: 'combobox',
			'aria-haspopup': 'listbox',
			'aria-expanded': 'false',
			'aria-controls': d.listId,
			'aria-labelledby': d.labelId,
			'aria-disabled': 'true'
		}, [
			make( 'div', { class: 'el-select__selection' }, [ d.placeholder ] ),
			make( 'div', { class: 'el-select__suffix' }, [ d.caret ] )
		] );

		d.note = make( 'p', { class: 'ys-fct-native-note', hidden: true } );
		d.error = make( 'p', { class: 'ys-fct-native-error', role: 'alert', hidden: true } );
		d.update = make( 'button', { type: 'button', class: 'el-button el-button--primary' }, [ make( 'span', { text: t( 'update' ) } ) ] );
		d.footer = make( 'span', { class: 'dialog-footer', hidden: true }, [ d.update ] );

		var closeButton = make( 'button', { type: 'button', class: 'el-dialog__headerbtn', 'aria-label': t( 'close' ) }, [ icon( 'el-icon el-dialog__close', CLOSE_ICON ) ] );

		var form = make( 'form', { class: 'el-form el-form--default el-form--label-top' }, [
			make( 'div', { class: 'el-form-item asterisk-left el-form-item--label-top' }, [
				make( 'label', { class: 'el-form-item__label', id: d.labelId, text: t( 'fieldLabel' ) } ),
				make( 'div', { class: 'el-form-item__content' }, [
					make( 'div', { class: 'el-select' }, [ d.wrapper ] )
				] )
			] )
		] );

		d.box = make( 'div', { class: 'el-dialog', tabindex: '-1' }, [
			make( 'header', { class: 'el-dialog__header show-close' }, [
				make( 'span', { role: 'heading', 'aria-level': '2', class: 'el-dialog__title', id: uid + '-title', text: t( 'dialogTitle' ) } ),
				closeButton
			] ),
			make( 'div', { class: 'el-dialog__body' }, [ d.note, form, d.error, d.footer ] )
		] );

		d.frame = make( 'div', { role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': uid + '-title', class: 'el-overlay-dialog' }, [ d.box ] );
		d.overlay = make( 'div', { class: 'el-overlay ys-fct-native', style: 'z-index:' + z }, [ d.frame ] );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
		} );

		d.wrapper.addEventListener( 'click', function () {
			if ( d.wrapper.classList.contains( 'is-disabled' ) ) {
				return;
			}

			if ( d.popper ) {
				closeList( d );
			} else {
				openList( d );
			}
		} );

		d.wrapper.addEventListener( 'focus', function () {
			d.wrapper.classList.add( 'is-focused' );
		} );

		d.wrapper.addEventListener( 'blur', function () {
			d.wrapper.classList.remove( 'is-focused' );
			closeList( d );
		} );

		d.wrapper.addEventListener( 'keydown', function ( event ) {
			if ( ! d.wrapper.classList.contains( 'is-disabled' ) ) {
				onComboKey( d, event );
			}
		} );

		d.update.addEventListener( 'click', function () {
			submitDialog( d );
		} );

		closeButton.addEventListener( 'click', closeDialog );

		// A click on the dimmed area outside the box closes it, as in
		// FluentCart's own dialogs; one that started inside the box does not.
		var pressedOutside = false;

		d.overlay.addEventListener( 'mousedown', function ( event ) {
			pressedOutside = event.target === d.overlay || event.target === d.frame;
		} );

		d.overlay.addEventListener( 'click', function ( event ) {
			if ( pressedOutside && ( event.target === d.overlay || event.target === d.frame ) ) {
				closeDialog();
			}

			pressedOutside = false;
		} );

		d.overlay.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				closeDialog();
				return;
			}

			if ( 'Tab' !== event.key ) {
				return;
			}

			// Keep Tab inside the dialog.
			var focusable = [ closeButton, d.wrapper, d.update ].filter( function ( node ) {
				return node && ! node.disabled && ! node.closest( '[hidden]' ) && ! node.classList.contains( 'is-disabled' );
			} );

			if ( ! focusable.length ) {
				return;
			}

			var first = focusable[ 0 ];
			var last = focusable[ focusable.length - 1 ];

			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		} );

		document.body.appendChild( d.overlay );

		if ( ! document.body.classList.contains( 'el-popup-parent--hidden' ) ) {
			document.body.classList.add( 'el-popup-parent--hidden' );
			d.lockedBody = true;
		}

		dialog = d;
		d.box.focus();

		fetchState( orderId ).then( function ( state ) {
			if ( dialog !== d ) {
				return;
			}

			fillDialog( d, state );

			if ( ! d.wrapper.classList.contains( 'is-disabled' ) ) {
				d.wrapper.focus();
			}
		} ).catch( function ( error ) {
			if ( dialog !== d ) {
				return;
			}

			d.placeholder.firstChild.textContent = '—';

			var status = error && error.status ? error.status : 0;
			var reason = ( error && error.message ) || t( 'notLoaded' );

			// A 401 or 403 is this page's session — the nonce it was loaded
			// with has expired, or the login has — and the card's list would
			// post with the same nonce and be refused the same way. Reloading
			// the page is what helps, so that is what the sentence says.
			if ( 401 === status || 403 === status ) {
				setError( d, String( t( 'reloadPage' ) ).replace( '%s', function () {
					return reason;
				} ) );
				return;
			}

			// Any other refusal (a 4xx) is the server's reason about this
			// order, and the list would meet the same rules. Only a network
			// failure, a server error or an answer without the order axis
			// means the entry cannot be used, so only then is the card's own
			// list shown, and the sentence says where. When the card has no
			// list to show — it offers no move, or it is not on the page —
			// the reason is all there is to say.
			if ( status && status < 500 ) {
				setError( d, reason );
				return;
			}

			setError( d, revealFallback( orderId ) > 0 ? t( 'loadFailed' ) : reason );
		} );
	}

	window.addEventListener( 'hashchange', closeDialog );

	window.addEventListener( 'resize', function () {
		closeList( dialog );
	} );

	// ── the card's fallback: its order-status list, when the entry cannot be used
	//
	// The entry above rests on FluentCart markup this plugin does not own, so
	// the card still carries its "Move to…" list for the order axis, hidden
	// (`[data-ys-changer-fallback]`), and it is shown when the entry cannot be
	// used:
	//
	// - FALLBACK_WAIT ms after the card appeared, the page still has no More
	//   Action trigger with `aria-controls` — the only kind `inject()` can add
	//   the entry through — or is not this order's `#/orders/<id>/view`, the
	//   only route the entry is added on;
	// - the menu was opened and the entry could not be added to it
	//   (`scheduleInject()` above);
	// - the entry's dialog could not load the order for a reason other than the
	//   page's session or the server's refusal (`openDialog()` above).
	//
	// Once shown for an order it stays shown while the page lives, on every card
	// for that order the app or `refreshCards()` renders again, and it replaces
	// its axis's "Other statuses" line. The list posts through `submit()` and
	// `changeStatus()`, like the Next step button. The shipping axis's list on a
	// canceled order is not a fallback: it is rendered on screen.

	var TRIGGER = '.fct-order-bulk-action-modal .fct-more-option-wrap [aria-controls]';
	var FALLBACK = 'data-ys-changer-fallback';
	var WATCHED = 'data-ys-changer-watched';
	var FALLBACK_WAIT = 4000;
	var FALLBACK_POLL = 250;
	var revealed = {};
	var scanQueued = false;

	/** The order id of the card a node is in, or ''. */
	function cardOrder( node ) {
		var card = node && node.closest ? node.closest( '[data-ys-changer]' ) : null;

		return card ? String( card.getAttribute( 'data-ys-order' ) || '' ) : '';
	}

	/** Whether the More Action entry can be added for this order now. */
	function entryUsable( orderId ) {
		return '' !== orderId && viewedOrderId() === orderId && null !== document.querySelector( TRIGGER );
	}

	/**
	 * Show one list, and take away its axis's "Other statuses: More Action →"
	 * line, which the list's own note now contradicts.
	 *
	 * @param {HTMLElement} node The `[data-ys-changer-fallback]` block.
	 * @return {void}
	 */
	function showList( node ) {
		var axis = node.closest ? node.closest( '[data-ys-axis]' ) : null;
		var hint = axis ? axis.querySelector( '[data-ys-changer-hint]' ) : null;

		node.hidden = false;

		if ( hint ) {
			hint.hidden = true;
		}
	}

	/**
	 * Show the list on every card for this order, and keep showing it.
	 *
	 * @param {string} orderId Order id.
	 * @return {number} How many cards for this order show it now.
	 */
	function revealFallback( orderId ) {
		var shown = 0;

		orderId = String( orderId || '' );

		if ( ! orderId ) {
			return 0;
		}

		revealed[ orderId ] = true;

		Array.prototype.forEach.call( document.querySelectorAll( '[' + FALLBACK + ']' ), function ( node ) {
			if ( cardOrder( node ) === orderId ) {
				showList( node );
				shown++;
			}
		} );

		return shown;
	}

	/** Watch one newly rendered list until the entry proves usable or the wait runs out. */
	function watchFallback( node ) {
		var orderId = cardOrder( node );
		var started = Date.now();

		if ( revealed[ orderId ] ) {
			showList( node );
			return;
		}

		( function check() {
			// Shown already (by a dialog that could not load), re-rendered away,
			// or the entry is there: nothing left to decide.
			if ( ! node.hidden || ! document.documentElement.contains( node ) || entryUsable( orderId ) ) {
				return;
			}

			if ( Date.now() - started >= FALLBACK_WAIT ) {
				if ( ! revealFallback( orderId ) ) {
					showList( node );
				}

				return;
			}

			window.setTimeout( check, FALLBACK_POLL );
		}() );
	}

	function scanFallbacks() {
		scanQueued = false;

		Array.prototype.forEach.call( document.querySelectorAll( '[' + FALLBACK + ']:not([' + WATCHED + '])' ), function ( node ) {
			node.setAttribute( WATCHED, '' );
			watchFallback( node );
		} );
	}

	/** At most one look every 100 ms, however busy the app is. */
	function queueScan() {
		if ( ! scanQueued ) {
			scanQueued = true;
			window.setTimeout( scanFallbacks, 100 );
		}
	}

	if ( 'function' === typeof window.MutationObserver ) {
		new window.MutationObserver( queueScan ).observe( document.documentElement, { childList: true, subtree: true } );
	}

	queueScan();
}() );
