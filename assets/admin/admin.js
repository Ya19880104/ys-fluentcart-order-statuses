/* global ysFctStatusAdmin */
/**
 * The Order Statuses screen.
 *
 * Everything is built with createElement rather than innerHTML: labels,
 * descriptions and slugs are operator input that also ends up in the storefront,
 * so there is never a string-concatenation path for them to travel down.
 */
( function () {
	'use strict';

	var cfg = window.ysFctStatusAdmin || {};
	var i18n = cfg.i18n || {};

	var root = document.getElementById( 'ys-fct-status-admin' );

	if ( ! root ) {
		return;
	}

	/** The working copy. Nothing is written until Save. */
	var model = null;
	var builtin = {};
	var usage = { order: {}, shipping: {} };
	var emails = { order: {}, shipping: {} };
	var backup = null;
	var moveTargets = { order: [], shipping: [] };
	var dirty = false;

	// ── helpers ──────────────────────────────────────────────────────────────

	function t( key, fallback ) {
		return Object.prototype.hasOwnProperty.call( i18n, key ) ? i18n[ key ] : ( fallback || key );
	}

	function sprintf( template, values ) {
		var out = String( template );

		values.forEach( function ( value, index ) {
			out = out.replace( '%' + ( index + 1 ) + '$d', value )
				.replace( '%' + ( index + 1 ) + '$s', value );
		} );

		return out.replace( '%d', values[ 0 ] ).replace( '%s', values[ 0 ] );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( 'text' === key ) {
				node.textContent = attrs[ key ];
			} else if ( 'html' === key ) {
				node.innerHTML = attrs[ key ];
			} else if ( key.indexOf( 'on' ) === 0 && typeof attrs[ key ] === 'function' ) {
				node.addEventListener( key.slice( 2 ), attrs[ key ] );
			} else if ( false === attrs[ key ] || null === attrs[ key ] || undefined === attrs[ key ] ) {
				/* skip */
			} else if ( true === attrs[ key ] ) {
				node.setAttribute( key, key );
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );

		( children || [] ).forEach( function ( child ) {
			if ( null === child || undefined === child ) {
				return;
			}

			node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
		} );

		return node;
	}

	function notice( message, type ) {
		var box = root.querySelector( '[data-ys-notice]' );

		if ( ! box ) {
			return;
		}

		box.textContent = '';

		if ( ! message ) {
			box.className = 'ys-fct-status-notice';
			return;
		}

		box.className = 'ys-fct-status-notice notice notice-' + ( type || 'success' ) + ' inline';
		box.appendChild( el( 'p', { text: message } ) );
	}

	function request( path, options ) {
		options = options || {};

		return window.fetch( cfg.restUrl + path, {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.restNonce
			},
			body: options.body ? JSON.stringify( options.body ) : undefined
		} ).then( function ( response ) {
			return response.json().then( function ( payload ) {
				if ( ! response.ok ) {
					var error = new Error( ( payload && payload.message ) || t( 'saveFailed' ) );
					error.payload = payload;
					throw error;
				}

				return payload;
			} );
		} );
	}

	function markDirty() {
		dirty = true;
		notice( t( 'unsaved' ), 'warning' );

		// The workflow preview is a separate <ol>, so redrawing it on every
		// keystroke cannot steal focus from the field being typed into.
		if ( model ) {
			renderSteps();
			renderShippingSteps();
		}
	}

	// ── rendering ────────────────────────────────────────────────────────────

	function slugify( value ) {
		return String( value ).toLowerCase().trim()
			.replace( /\s+/g, '_' )
			.replace( /[^a-z0-9_-]/g, '' )
			.slice( 0, cfg.maxSlug || 20 );
	}

	function textCell( definition, field, placeholder ) {
		return el( 'input', {
			type: 'text',
			value: definition[ field ] || '',
			placeholder: placeholder || '',
			'aria-label': placeholder || field,
			class: 'regular-text ys-fct-status-input',
			oninput: function ( event ) {
				definition[ field ] = event.target.value;

				if ( 'label' === field && definition.__isNew && ! definition.__slugTouched ) {
					definition.slug = slugify( event.target.value );
					var slugInput = event.target.closest( 'tr' ).querySelector( '[data-ys-slug]' );

					if ( slugInput ) {
						slugInput.value = definition.slug;
					}
				}

				markDirty();
			}
		} );
	}

	function selectCell( definition, field, options, label ) {
		var select = el( 'select', {
			class: 'ys-fct-status-input',
			'aria-label': label || field,
			onchange: function ( event ) {
				definition[ field ] = event.target.value;
				markDirty();
			}
		} );

		options.forEach( function ( option ) {
			select.appendChild( el( 'option', {
				value: option.value,
				selected: definition[ field ] === option.value,
				text: option.label
			} ) );
		} );

		return select;
	}

	function checkboxLabel( definition, field, label, axis ) {
		return el( 'label', { class: 'ys-fct-status-check' }, [
			el( 'input', {
				type: 'checkbox',
				'aria-label': label,
				checked: !! definition[ field ],
				onchange: function ( event ) {
					// Switching off a status orders are on changes what happens
					// to those orders, quietly. Say what, and let it be undone.
					if ( 'enabled' === field && axis && ! event.target.checked ) {
						var count = usageOf( axis, definition );

						if ( count > 0 && ! window.confirm( sprintf( t( 'disableConfirm' ), [ definition.label || definition.slug, count ] ) ) ) {
							event.target.checked = true;
							return;
						}
					}

					definition[ field ] = event.target.checked;
					markDirty();
				}
			} ),
			' ' + label
		] );
	}

	function colorCell( definition ) {
		var text = el( 'input', {
			type: 'text',
			value: definition.color,
			class: 'ys-fct-status-color-text',
			'aria-label': t( 'colourHex' ),
			maxlength: '7'
		} );

		var picker = el( 'input', {
			type: 'color',
			value: definition.color,
			'aria-label': t( 'colourPicker' ),
			class: 'ys-fct-status-color-picker'
		} );

		picker.addEventListener( 'input', function () {
			definition.color = picker.value;
			text.value = picker.value;
			markDirty();
		} );

		text.addEventListener( 'input', function () {
			definition.color = text.value;

			if ( /^#[0-9a-fA-F]{6}$/.test( text.value ) ) {
				picker.value = text.value;
			}

			markDirty();
		} );

		return el( 'div', { class: 'ys-fct-status-color' }, [ picker, text ] );
	}

	function moveButtons( axis, index ) {
		var list = model[ axis ];

		function swap( a, b ) {
			var tmp = list[ a ];
			list[ a ] = list[ b ];
			list[ b ] = tmp;

			list.forEach( function ( definition, position ) {
				definition.sort_order = ( position + 1 ) * 10;
			} );

			markDirty();
			renderAxis( axis );
		}

		return el( 'div', { class: 'ys-fct-status-reorder' }, [
			el( 'button', {
				type: 'button',
				class: 'button button-small',
				disabled: 0 === index,
				title: t( 'up' ),
				'aria-label': t( 'up' ),
				text: '↑',
				onclick: function () {
					swap( index, index - 1 );
				}
			} ),
			el( 'button', {
				type: 'button',
				class: 'button button-small',
				disabled: index === list.length - 1,
				title: t( 'down' ),
				'aria-label': t( 'down' ),
				text: '↓',
				onclick: function () {
					swap( index, index + 1 );
				}
			} )
		] );
	}

	function countFor( axis, slug ) {
		return ( usage[ axis ] && usage[ axis ][ slug ] ) || 0;
	}

	/**
	 * Orders on a row's status, counted by the slug that was stored — the one
	 * the orders actually carry. A row that has never been saved has none.
	 */
	function usageOf( axis, definition ) {
		return definition.__isNew ? 0 : countFor( axis, definition.__stored || definition.slug );
	}

	/**
	 * Everything `linked_shipping_status` may point at: the built-in shipping
	 * statuses plus whatever custom ones are defined on the other tab. Rebuilt
	 * on every render rather than cached, because adding a shipping status and
	 * then linking to it without saving in between is the obvious thing to do.
	 */
	function shippingOptions() {
		var options = [ { value: '', label: t( 'linkedNone' ) } ];

		Object.keys( builtin.shipping || {} ).forEach( function ( slug ) {
			options.push( { value: slug, label: builtin.shipping[ slug ] } );
		} );

		model.shipping.forEach( function ( definition ) {
			if ( definition.slug ) {
				options.push( { value: definition.slug, label: definition.label || definition.slug } );
			}
		} );

		return options;
	}

	/** The name a slug goes by on this screen: its row, a renamed built-in, the built-in, or the slug. */
	function labelOf( axis, slug ) {
		var found = '';

		( model[ axis ] || [] ).forEach( function ( definition ) {
			if ( ( definition.__stored || definition.slug ) === slug ) {
				found = definition.label || slug;
			}
		} );

		if ( found ) {
			return found;
		}

		var override = model.overrides && model.overrides[ axis ] ? model.overrides[ axis ][ slug ] : null;

		if ( override && override.label ) {
			return override.label;
		}

		return ( builtin[ axis ] && builtin[ axis ][ slug ] ) || slug;
	}

	/**
	 * Where Move orders may put orders: the server's list, not every status.
	 * Canceled, Completed, Shipped and the like would be written without any
	 * of what normally comes with them, so the server refuses them and this
	 * list never offers them.
	 */
	function moveOptions( axis, exceptSlug ) {
		var options = [];

		( moveTargets[ axis ] || [] ).forEach( function ( slug ) {
			if ( slug !== exceptSlug ) {
				options.push( { value: slug, label: labelOf( axis, slug ) } );
			}
		} );

		return options;
	}

	function actionCell( axis, definition, index ) {
		var count = usageOf( axis, definition );
		var cell = el( 'td', {} );

		cell.appendChild( el( 'button', {
			type: 'button',
			class: 'button button-small button-link-delete',
			text: t( 'remove' ),
			onclick: function () {
				if ( count > 0 ) {
					notice( sprintf( t( 'inUseRemove' ), [ count ] ), 'error' );
					return;
				}

				if ( ! window.confirm( sprintf( t( 'confirmRemove' ), [ definition.label || definition.slug ] ) ) ) {
					return;
				}

				model[ axis ].splice( index, 1 );
				markDirty();
				renderAxis( axis );
			}
		} ) );

		if ( count > 0 ) {
			cell.appendChild( document.createTextNode( ' ' ) );
			cell.appendChild( el( 'button', {
				type: 'button',
				class: 'button button-small',
				text: t( 'move' ),
				onclick: function () {
					openMove( axis, { slug: definition.__stored || definition.slug, label: definition.label || definition.slug }, count, cell );
				}
			} ) );
		}

		return cell;
	}

	/**
	 * The Move orders control, opened inside `cell`.
	 *
	 * @param {string}      axis   'order' or 'shipping'.
	 * @param {Object}      source `{ slug, label }` — the status to empty.
	 * @param {number}      count  Orders on it.
	 * @param {HTMLElement} cell   Where the control goes.
	 */
	function openMove( axis, source, count, cell ) {
		if ( cell.querySelector( '.ys-fct-status-move' ) ) {
			return;
		}

		var template = root.querySelector( '[data-ys-template="move"]' );

		if ( ! template ) {
			return;
		}

		var fragment = template.content.cloneNode( true );
		var box = fragment.querySelector( '.ys-fct-status-move' );
		var select = fragment.querySelector( '[data-ys-move-target]' );
		var go = fragment.querySelector( '[data-ys-move-go]' );
		var cancel = fragment.querySelector( '[data-ys-move-cancel]' );
		var options = moveOptions( axis, source.slug );

		fragment.querySelector( '[data-ys-move-label]' ).textContent =
			sprintf( t( 'moveTo' ), [ count, source.label ] );

		select.setAttribute( 'aria-label', t( 'move' ) );

		options.forEach( function ( option ) {
			select.appendChild( el( 'option', { value: option.value, text: option.label } ) );
		} );

		go.disabled = ! options.length;

		cancel.addEventListener( 'click', function () {
			box.remove();
		} );

		go.addEventListener( 'click', function () {
			var target = select.value;
			var targetLabel = select.selectedIndex >= 0 ? select.options[ select.selectedIndex ].textContent : target;

			if ( ! target ) {
				return;
			}

			// The move writes the order rows directly. Say exactly that before
			// doing it: nothing downstream of a normal status change happens.
			if ( ! window.confirm( sprintf( t( 'moveConfirm' ), [ count, source.label, targetLabel ] ) ) ) {
				return;
			}

			go.disabled = true;
			cancel.disabled = true;
			select.disabled = true;
			notice( t( 'moving' ), 'info' );

			request( 'migrate', {
				method: 'POST',
				body: { axis: axis, from: source.slug, to: target }
			} ).then( function ( payload ) {
				usage = payload.usage || usage;
				notice( payload.message, 'success' );
				renderAxis( axis );
				renderUsageSummary();
				renderOrphans();
			} ).catch( function ( error ) {
				go.disabled = false;
				cancel.disabled = false;
				select.disabled = false;
				notice( error.message, 'error' );
			} );
		} );

		cell.appendChild( box );
	}

	function renderAxis( axis ) {
		var body = root.querySelector( '[data-ys-rows="' + axis + '"]' );

		if ( ! body ) {
			return;
		}

		body.textContent = '';

		if ( ! model[ axis ].length ) {
			var columns = 'order' === axis ? 8 : 7;
			body.appendChild( el( 'tr', {}, [ el( 'td', { colspan: String( columns ), class: 'ys-fct-status-empty', text: t( 'noCustom' ) } ) ] ) );
			return;
		}

		model[ axis ].forEach( function ( definition, index ) {
			var firstCell = [];

			if ( 'order' === axis ) {
				// Step 1 is the built-in entry status, so a custom status at
				// index 0 is step 2. Showing the number here is what makes the
				// up/down buttons obviously mean "reorder the workflow".
				firstCell.push( el( 'span', { class: 'ys-fct-status-step', text: String( index + 2 ) } ) );
			}

			firstCell.push( moveButtons( axis, index ) );

			var cells = [
				el( 'td', {}, firstCell ),
				el( 'td', {}, [
					textCell( definition, 'label', t( 'labelPlaceholder' ) ),
					el( 'br' ),
					textCell( definition, 'description', t( 'description' ) )
				] ),
				el( 'td', {}, [ slugCell( definition ) ] ),
				el( 'td', {}, [ colorCell( definition ) ] )
			];

			if ( 'order' === axis ) {
				cells.push( el( 'td', {}, [ selectCell(
					definition,
					'linked_shipping_status',
					shippingOptions(),
					t( 'linkedShipping' )
				) ] ) );
			}

			cells.push( el( 'td', {}, [
				checkboxLabel( definition, 'enabled', t( 'enabled' ), axis ),
				el( 'br' ),
				checkboxLabel( definition, 'editable', t( 'editable' ) )
			] ) );

			cells.push( el( 'td', { class: 'ys-fct-status-count', text: String( usageOf( axis, definition ) ) } ) );
			cells.push( actionCell( axis, definition, index ) );

			body.appendChild( el( 'tr', { 'data-ys-slug-row': definition.slug || '' }, cells ) );

			// Its own row rather than a line inside the label cell: the cell is two
			// stacked inputs wide, and "E-mail: customer — edit in Email
			// Notifications" wrapped to three lines in it. On the order axis the
			// same row carries the folded-away payment settings.
			var extra = [ emailCell( axis, definition ) ];

			if ( 'order' === axis ) {
				extra.push( advancedCell( definition ) );
			}

			body.appendChild(
				el( 'tr', { class: 'ys-fct-status-email-row' }, [
					el( 'td', { colspan: String( 'order' === axis ? 8 : 7 ) }, extra )
				] )
			);
		} );
	}

	/**
	 * The two payment settings of an order status, folded away.
	 *
	 * "Offered on every order" and "kept when a payment lands" are right for
	 * almost every shop, and a column of "Paid orders only" beside every status
	 * made the list read as if it were split into a paid half and an unpaid
	 * half. So both settings sit behind one collapsed line. Its summary always
	 * says what they are set to, and turns amber when either differs from the
	 * default, so a status somebody did restrict is visible without opening
	 * anything.
	 */
	function advancedCell( definition ) {
		var requirementOptions = [
			{ value: 'any', label: t( 'reqAny' ) },
			{ value: 'paid_only', label: t( 'reqPaid' ) },
			{ value: 'unpaid_only', label: t( 'reqUnpaid' ) }
		];
		var paymentOptions = [
			{ value: 'keep', label: t( 'keep' ) },
			{ value: 'let_core_decide', label: t( 'letCore' ) }
		];
		var summary = el( 'summary', { class: 'ys-fct-status-advanced-summary' } );
		var details = null;

		function labelFor( options, value ) {
			for ( var i = 0; i < options.length; i++ ) {
				if ( options[ i ].value === value ) {
					return options[ i ].label;
				}
			}

			return value;
		}

		function refreshSummary() {
			var requirement = definition.payment_requirement || 'any';
			var onPayment = definition.on_payment || 'keep';

			summary.textContent = t( 'advanced' ) + ' — ' +
				t( 'availableOn' ) + ': ' + labelFor( requirementOptions, requirement ) + ' · ' +
				t( 'afterPayment' ) + ': ' + labelFor( paymentOptions, onPayment );

			if ( details ) {
				details.classList.toggle( 'is-custom', 'any' !== requirement || 'keep' !== onPayment );
			}
		}

		// selectCell() stores the value on its own change listener, which is
		// registered first, so the summary below always reads the new value.
		var requirementSelect = selectCell( definition, 'payment_requirement', requirementOptions, t( 'availableOn' ) );
		var paymentSelect = selectCell( definition, 'on_payment', paymentOptions, t( 'afterPayment' ) );

		requirementSelect.addEventListener( 'change', refreshSummary );
		paymentSelect.addEventListener( 'change', refreshSummary );

		details = el( 'details', {
			class: 'ys-fct-status-advanced',
			open: !! definition.__advancedOpen
		}, [
			summary,
			el( 'div', { class: 'ys-fct-status-advanced-body' }, [
				el( 'label', {}, [ el( 'span', { text: t( 'availableOn' ) } ), requirementSelect ] ),
				el( 'label', {}, [ el( 'span', { text: t( 'afterPayment' ) } ), paymentSelect ] ),
				el( 'p', { class: 'description', text: t( 'advancedHint' ) } )
			] )
		] );

		// Remembered on the definition so reordering or adding a row — both of
		// which redraw the table — does not snap an open line shut.
		details.addEventListener( 'toggle', function () {
			definition.__advancedOpen = details.open;
		} );

		refreshSummary();

		return details;
	}

	/**
	 * The one line about e-mail on a status row.
	 *
	 * Read-only on purpose: FluentCart owns the on/off switch, the subject and
	 * the body, and a second toggle here would be a second source of truth for
	 * a value this plugin does not store. The line says what FluentCart's own
	 * configuration currently says, and links to where it is changed.
	 */
	function emailCell( axis, definition ) {
		var wrap = el( 'span', { class: 'ys-fct-status-email' } );
		var state = ( emails[ axis ] || {} )[ definition.slug ] || null;

		wrap.appendChild( document.createTextNode( t( 'emailLabel' ) + ': ' ) );

		if ( ! state ) {
			// A status that has never been saved has no notification yet — the
			// registry is built from the stored settings, not from this form.
			// A saved one that is switched off has none either, and says so:
			// its heading and message are kept for when it is switched on.
			wrap.appendChild( el( 'em', {
				text: ! definition.__isNew && ! definition.enabled ? t( 'emailDisabled' ) : t( 'emailUnsaved' )
			} ) );
			return wrap;
		}

		var on = [];

		if ( state.customer ) {
			on.push( t( 'emailCustomer' ) );
		}

		if ( state.admin ) {
			on.push( t( 'emailAdmin' ) );
		}

		wrap.appendChild( el( 'strong', {
			class: on.length ? 'ys-fct-status-email-on' : 'ys-fct-status-email-off',
			text: on.length ? on.join( ', ' ) : t( 'emailOff' )
		} ) );

		if ( cfg.emailsUrl ) {
			wrap.appendChild( document.createTextNode( ' — ' ) );
			wrap.appendChild( el( 'a', {
				href: cfg.emailsUrl,
				target: '_blank',
				rel: 'noopener',
				text: t( 'emailEdit' )
			} ) );
		}

		return wrap;
	}

	function slugCell( definition ) {
		// A saved slug is the value the orders carry. Editing it would leave
		// every one of them on the old value, which the server now refuses
		// anyway — so the field says so up front instead of failing at Save.
		var saved = ! definition.__isNew;

		var input = el( 'input', {
			type: 'text',
			value: definition.slug || '',
			maxlength: String( cfg.maxSlug || 20 ),
			'aria-label': t( 'slug' ),
			class: 'code ys-fct-status-slug',
			placeholder: t( 'slugPlaceholder' ),
			'data-ys-slug': '1',
			readonly: saved,
			title: saved ? t( 'slugLocked' ) : null
		} );

		if ( saved ) {
			return input;
		}

		input.addEventListener( 'input', function () {
			definition.__slugTouched = true;
			definition.slug = slugify( input.value );
			input.value = definition.slug;
			markDirty();
		} );

		return input;
	}

	function renderOverrides( axis ) {
		var body = root.querySelector( '[data-ys-overrides="' + axis + '"]' );

		if ( ! body ) {
			return;
		}

		body.textContent = '';

		Object.keys( builtin[ axis ] || {} ).forEach( function ( slug ) {
			var override = model.overrides[ axis ][ slug ] || { label: '', color: '' };
			model.overrides[ axis ][ slug ] = override;

			var label = el( 'input', {
				type: 'text',
				value: override.label,
				class: 'regular-text',
				'aria-label': builtin[ axis ][ slug ],
				placeholder: builtin[ axis ][ slug ],
				oninput: function ( event ) {
					override.label = event.target.value;
					markDirty();
				}
			} );

			var cells = [
				el( 'th', { scope: 'row' }, [
					el( 'strong', { text: builtin[ axis ][ slug ] } ),
					el( 'br' ),
					el( 'code', { text: slug } )
				] ),
				el( 'td', {}, [ label ] )
			];

			if ( 'payment' !== axis ) {
				cells.push( el( 'td', {}, [ overrideColor( override ) ] ) );
			} else {
				cells.push( el( 'td', { class: 'description', text: '—' } ) );
			}

			cells.push( el( 'td', { class: 'ys-fct-status-count', text: String( countFor( axis, slug ) ) } ) );

			body.appendChild( el( 'tr', {}, cells ) );
		} );
	}

	function overrideColor( override ) {
		var picker = el( 'input', {
			type: 'color',
			value: override.color || '#cccccc',
			'aria-label': t( 'colourPicker' ),
			class: 'ys-fct-status-color-picker'
		} );

		var text = el( 'input', {
			type: 'text',
			value: override.color || '',
			class: 'ys-fct-status-color-text',
			'aria-label': t( 'colourHex' ),
			maxlength: '7',
			placeholder: '#rrggbb'
		} );

		picker.addEventListener( 'input', function () {
			override.color = picker.value;
			text.value = picker.value;
			markDirty();
		} );

		text.addEventListener( 'input', function () {
			override.color = text.value;

			if ( /^#[0-9a-fA-F]{6}$/.test( text.value ) ) {
				picker.value = text.value;
			}

			markDirty();
		} );

		return el( 'div', { class: 'ys-fct-status-color' }, [ picker, text ] );
	}

	function renderUsageSummary() {
		var target = root.querySelector( '[data-ys-usage-summary]' );

		if ( ! target ) {
			return;
		}

		target.textContent = '';

		var rows = [];

		[ 'order', 'shipping' ].forEach( function ( axis ) {
			model[ axis ].forEach( function ( definition ) {
				var count = usageOf( axis, definition );

				if ( count > 0 ) {
					rows.push( ( definition.label || definition.slug ) + ' (' + definition.slug + '): ' + count );
				}
			} );
		} );

		if ( ! rows.length ) {
			target.appendChild( el( 'p', { text: t( 'usageNone' ) } ) );
			return;
		}

		target.appendChild( el( 'p', { text: t( 'usageSome' ) } ) );
		var list = el( 'ul', { class: 'ys-fct-status-usage' } );

		rows.forEach( function ( row ) {
			list.appendChild( el( 'li', { text: row } ) );
		} );

		target.appendChild( list );
	}

	/**
	 * Orders sitting on a value nothing defines: a warning at the top of the
	 * screen, and a list with a Move orders control on the Tools tab.
	 */
	function renderOrphans() {
		var orphans = ( usage && usage.orphans ) || {};
		var rows = [];
		var total = 0;

		[ 'order', 'shipping' ].forEach( function ( axis ) {
			var counts = orphans[ axis ] || {};

			Object.keys( counts ).forEach( function ( slug ) {
				rows.push( { axis: axis, slug: slug, count: counts[ slug ] } );
				total += counts[ slug ];
			} );
		} );

		var warning = root.querySelector( '[data-ys-orphans-warning]' );

		if ( warning ) {
			warning.textContent = '';
			warning.hidden = ! rows.length;
			warning.className = rows.length ? 'notice notice-warning inline ys-fct-status-orphans-warning' : 'ys-fct-status-orphans-warning';

			if ( rows.length ) {
				warning.appendChild( el( 'p', {}, [
					sprintf( t( 'orphansWarning' ), [ total ] ) + ' ',
					el( 'a', {
						href: '#tools',
						text: t( 'orphansLink' ),
						onclick: function ( event ) {
							event.preventDefault();
							showTab( 'tools' );

							var section = root.querySelector( '[data-ys-orphans]' );

							if ( section && section.scrollIntoView ) {
								section.scrollIntoView( { block: 'center' } );
							}
						}
					} )
				] ) );
			}
		}

		var list = root.querySelector( '[data-ys-orphans]' );

		if ( ! list ) {
			return;
		}

		list.textContent = '';

		if ( ! rows.length ) {
			list.appendChild( el( 'p', { text: t( 'orphansNone' ) } ) );
			return;
		}

		var body = el( 'tbody' );

		rows.forEach( function ( row ) {
			var cell = el( 'td', {} );

			cell.appendChild( el( 'button', {
				type: 'button',
				class: 'button button-small',
				text: t( 'move' ),
				onclick: function () {
					openMove( row.axis, { slug: row.slug, label: row.slug }, row.count, cell );
				}
			} ) );

			body.appendChild( el( 'tr', {}, [
				el( 'td', {}, [ el( 'code', { text: row.slug } ) ] ),
				el( 'td', { text: 'shipping' === row.axis ? t( 'axisShipping' ) : t( 'axisOrder' ) } ),
				el( 'td', { text: sprintf( t( 'orphanCount' ), [ row.count ] ) } ),
				cell
			] ) );
		} );

		list.appendChild( el( 'table', { class: 'widefat striped ys-fct-status-table' }, [ body ] ) );
	}

	function renderSteps() {
		var list = root.querySelector( '[data-ys-steps]' );

		if ( ! list ) {
			return;
		}

		list.textContent = '';

		var entrySlug = cfg.entrySlug || 'processing';
		var entryOverride = ( model.overrides.order && model.overrides.order[ entrySlug ] ) || {};
		var entryLabel = entryOverride.label || ( builtin.order && builtin.order[ entrySlug ] ) || entrySlug;

		list.appendChild( stepItem( entryLabel, entryOverride.color || '', t( 'stepEntry' ) ) );

		model[ 'order' ].forEach( function ( definition ) {
			if ( false === definition.enabled ) {
				return;
			}

			var note = definition.linked_shipping_status
				? t( 'linkedShipping' ) + ' ' + definition.linked_shipping_status
				: '';

			list.appendChild( stepItem( definition.label || definition.slug, definition.color, note ) );
		} );
	}

	/**
	 * The fulfilment workflow, drawn the same way as the order one.
	 *
	 * It has a closing step the order workflow does not: `shipped` is where
	 * FluentCart marks each physical item fulfilled, so it is a real step and
	 * not just a destination. Showing it makes the shape of the workflow —
	 * built-in, yours, yours, built-in — obvious at a glance.
	 */
	function renderShippingSteps() {
		var list = root.querySelector( '[data-ys-shipping-steps]' );

		if ( ! list ) {
			return;
		}

		list.textContent = '';

		list.appendChild( builtinStepItem( cfg.shipEntry || 'unshipped', 'shipping', t( 'stepShipEntry' ) ) );

		model.shipping.forEach( function ( definition ) {
			if ( false === definition.enabled ) {
				return;
			}

			list.appendChild( stepItem( definition.label || definition.slug, definition.color, '' ) );
		} );

		list.appendChild( builtinStepItem( cfg.shipExit || 'shipped', 'shipping', t( 'stepShipExit' ) ) );
	}

	function builtinStepItem( slug, axis, note ) {
		var override = ( model.overrides[ axis ] && model.overrides[ axis ][ slug ] ) || {};
		var label = override.label || ( builtin[ axis ] && builtin[ axis ][ slug ] ) || slug;

		return stepItem( label, override.color || '', note );
	}

	function stepItem( label, color, note ) {
		var children = [ el( 'strong', { text: label } ) ];

		if ( note ) {
			children.push( el( 'span', { class: 'ys-fct-status-step-note', text: note } ) );
		}

		return el( 'li', {
			class: 'ys-fct-status-step-item',
			style: color ? '--ys-step-color:' + color : null
		}, children );
	}

	function renderTools() {
		var restore = root.querySelector( '[data-ys-restore-toggle]' );

		if ( restore ) {
			restore.checked = 'no' !== model.restore_on_payment;
		}

		var strict = root.querySelector( '[data-ys-strict-toggle]' );

		if ( strict ) {
			strict.checked = 'yes' === model.pipeline_strict;
		}

		var stall = root.querySelector( '[data-ys-stall-days]' );

		if ( stall ) {
			stall.value = model.stall_days;
		}

		model.daily_summary = model.daily_summary || { enabled: 'no', email: '' };

		var summary = root.querySelector( '[data-ys-summary-toggle]' );

		if ( summary ) {
			summary.checked = 'yes' === model.daily_summary.enabled;
		}

		var email = root.querySelector( '[data-ys-summary-email]' );

		if ( email ) {
			email.value = model.daily_summary.email || '';
		}
	}

	function renderAll() {
		renderAxis( 'order' );
		renderAxis( 'shipping' );
		renderOverrides( 'order' );
		renderOverrides( 'payment' );
		renderOverrides( 'shipping' );
		renderUsageSummary();
		renderSteps();
		renderShippingSteps();
		renderTools();
		renderUndo();
		renderOrphans();
	}

	// ── state ────────────────────────────────────────────────────────────────

	function adopt( payload ) {
		model = payload.settings;
		model.overrides = model.overrides || { order: {}, payment: {}, shipping: {} };

		[ 'order', 'payment', 'shipping' ].forEach( function ( axis ) {
			model.overrides[ axis ] = model.overrides[ axis ] || {};
		} );

		// A settings document written by 0.1.0 has none of the pipeline keys.
		// The server fills them in on read, but the screen must not depend on
		// that to render — an empty select is a worse bug than a default.
		model.pipeline_strict = model.pipeline_strict || 'no';
		model.stall_days = model.stall_days || 3;
		model.daily_summary = model.daily_summary || { enabled: 'no', email: '' };

		model.order.forEach( function ( definition ) {
			definition.linked_shipping_status = definition.linked_shipping_status || '';
		} );

		// What the orders carry, remembered beside the editable copy.
		[ 'order', 'shipping' ].forEach( function ( axis ) {
			model[ axis ].forEach( function ( definition ) {
				definition.__stored = definition.slug;
			} );
		} );

		builtin = payload.builtin || {};
		usage = payload.usage || { order: {}, shipping: {} };
		emails = payload.emails || { order: {}, shipping: {} };
		backup = payload.backup || null;
		moveTargets = payload.move_targets || { order: [], shipping: [] };
		dirty = false;

		renderAll();
	}

	/** The Undo button exists only while there is an import to undo. */
	function renderUndo() {
		var wrap = root.querySelector( '[data-ys-undo-wrap]' );
		var note = root.querySelector( '[data-ys-undo-note]' );

		if ( wrap ) {
			wrap.hidden = ! backup;
		}

		if ( note ) {
			note.textContent = backup && backup.note ? backup.note : '';
		}
	}

	function save() {
		notice( t( 'saving' ), 'info' );

		var payload = JSON.parse( JSON.stringify( model ) );

		[ 'order', 'shipping' ].forEach( function ( axis ) {
			payload[ axis ] = payload[ axis ].map( function ( definition, index ) {
				delete definition.__isNew;
				delete definition.__slugTouched;
				delete definition.__advancedOpen;
				delete definition.__stored;
				definition.sort_order = ( index + 1 ) * 10;
				return definition;
			} );
		} );

		request( 'settings', { method: 'POST', body: { settings: payload } } )
			.then( function ( response ) {
				adopt( response );
				notice( response.message || t( 'saved' ), 'success' );
			} )
			.catch( function ( error ) {
				notice( error.message || t( 'saveFailed' ), 'error' );
			} );
	}

	// ── wiring ───────────────────────────────────────────────────────────────

	function showTab( name ) {
		var found = false;

		root.querySelectorAll( '[data-ys-tab]' ).forEach( function ( other ) {
			var isTarget = other.getAttribute( 'data-ys-tab' ) === name;
			found = found || isTarget;
			other.classList.toggle( 'nav-tab-active', isTarget );
		} );

		if ( ! found ) {
			return;
		}

		root.querySelectorAll( '[data-ys-panel]' ).forEach( function ( panel ) {
			panel.hidden = panel.getAttribute( 'data-ys-panel' ) !== name;
		} );

		// The report is several GROUP BY queries; it is fetched when the tab is
		// first opened rather than on every page load.
		if ( 'reports' === name && ! report.loaded ) {
			loadReport();
		}
	}

	root.querySelectorAll( '[data-ys-tab]' ).forEach( function ( tab ) {
		tab.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			showTab( tab.getAttribute( 'data-ys-tab' ) );
		} );
	} );


	root.querySelectorAll( '[data-ys-add]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var axis = button.getAttribute( 'data-ys-add' );

			var definition = {
				slug: '',
				label: '',
				color: ( cfg.defaults && cfg.defaults.color ) || '#64748b',
				description: '',
				editable: true,
				enabled: true,
				sort_order: ( model[ axis ].length + 1 ) * 10,
				__isNew: true
			};

			if ( 'order' === axis ) {
				definition.payment_requirement = 'any';
				definition.on_payment = 'keep';
				definition.linked_shipping_status = '';
			}

			model[ axis ].push( definition );
			markDirty();
			renderAxis( axis );
			renderSteps();
			renderShippingSteps();
		} );
	} );

	root.querySelectorAll( '[data-ys-save]' ).forEach( function ( button ) {
		button.addEventListener( 'click', save );
	} );

	function bindToggle( selector, apply ) {
		var node = root.querySelector( selector );

		if ( ! node ) {
			return;
		}

		node.addEventListener( 'change', function () {
			apply( node );
			markDirty();
		} );
	}

	bindToggle( '[data-ys-restore-toggle]', function ( node ) {
		model.restore_on_payment = node.checked ? 'yes' : 'no';
	} );

	bindToggle( '[data-ys-strict-toggle]', function ( node ) {
		model.pipeline_strict = node.checked ? 'yes' : 'no';
	} );

	bindToggle( '[data-ys-summary-toggle]', function ( node ) {
		model.daily_summary.enabled = node.checked ? 'yes' : 'no';
	} );

	var stallDays = root.querySelector( '[data-ys-stall-days]' );

	if ( stallDays ) {
		stallDays.addEventListener( 'input', function () {
			model.stall_days = parseInt( stallDays.value, 10 ) || 1;
			markDirty();
		} );
	}

	var summaryEmail = root.querySelector( '[data-ys-summary-email]' );

	if ( summaryEmail ) {
		summaryEmail.addEventListener( 'input', function () {
			model.daily_summary.email = summaryEmail.value.trim();
			markDirty();
		} );
	}

	var templateButton = root.querySelector( '[data-ys-template]' );

	if ( templateButton ) {
		templateButton.addEventListener( 'click', function () {
			if ( ! window.confirm( t( 'templateConfirm' ) ) ) {
				return;
			}

			notice( t( 'templateWorking' ), 'info' );

			request( 'template', { method: 'POST', body: {} } )
				.then( function ( payload ) {
					// The template writes straight to the option, so the screen
					// has to be reloaded from the server rather than patched:
					// anything unsaved in the form was not part of that merge.
					return request( 'settings' ).then( function ( fresh ) {
						adopt( fresh );
						notice( payload.message, 'success' );
					} );
				} )
				.catch( function ( error ) {
					notice( error.message || t( 'saveFailed' ), 'error' );
				} );
		} );
	}

	var shippingTemplateButton = root.querySelector( '[data-ys-template-shipping]' );

	if ( shippingTemplateButton ) {
		shippingTemplateButton.addEventListener( 'click', function () {
			if ( ! window.confirm( t( 'shippingConfirm' ) ) ) {
				return;
			}

			notice( t( 'templateWorking' ), 'info' );

			request( 'template', { method: 'POST', body: { axis: 'shipping' } } )
				.then( function ( payload ) {
					// Same reasoning as the order-axis button: the template
					// writes straight to the option, so the screen is reloaded
					// from the server rather than patched.
					return request( 'settings' ).then( function ( fresh ) {
						adopt( fresh );
						showTab( 'shipping' );
						notice( payload.message, 'success' );
					} );
				} )
				.catch( function ( error ) {
					notice( error.message || t( 'saveFailed' ), 'error' );
				} );
		} );
	}

	var backfillButton = root.querySelector( '[data-ys-backfill]' );

	if ( backfillButton ) {
		backfillButton.addEventListener( 'click', function () {
			if ( ! window.confirm( t( 'backfillConfirm' ) ) ) {
				return;
			}

			notice( t( 'backfillWorking' ), 'info' );

			request( 'reports/backfill', { method: 'POST', body: {} } )
				.then( function ( payload ) {
					notice( payload.message, 'success' );
					setHistoryCount( payload.rows );
					report.loaded = false;
				} )
				.catch( function ( error ) {
					notice( error.message || t( 'saveFailed' ), 'error' );
				} );
		} );
	}

	var summaryTest = root.querySelector( '[data-ys-summary-test]' );

	if ( summaryTest ) {
		summaryTest.addEventListener( 'click', function () {
			notice( t( 'summaryWorking' ), 'info' );

			request( 'reports/summary-test', { method: 'POST', body: {} } )
				.then( function ( payload ) {
					notice( payload.message, payload.sent ? 'success' : 'warning' );
				} )
				.catch( function ( error ) {
					notice( error.message || t( 'saveFailed' ), 'error' );
				} );
		} );
	}

	function setHistoryCount( rows ) {
		var node = root.querySelector( '[data-ys-history-count]' );

		if ( node ) {
			node.textContent = sprintf( t( 'historyCount' ), [ rows ] );
		}
	}

	// ── the report tab ───────────────────────────────────────────────────────

	var report = { loaded: false, data: null };

	function reportRange() {
		var since = root.querySelector( '[data-ys-range="since"]' );
		var until = root.querySelector( '[data-ys-range="until"]' );

		return {
			since: since && since.value ? since.value : '',
			until: until && until.value ? until.value : ''
		};
	}

	/**
	 * Which of the two workflows the funnel, the timings and the stuck list
	 * describe. The two distribution tables are per-axis by construction and
	 * are always rendered, so this switch does not touch them.
	 */
	function reportAxis() {
		var picked = root.querySelector( '[data-ys-report-axis]:checked' );

		return picked && 'shipping' === picked.value ? 'shipping' : 'order';
	}

	function reportQuery() {
		var range = reportRange();
		var parts = [ 'axis=' + encodeURIComponent( reportAxis() ) ];

		if ( range.since ) {
			parts.push( 'since=' + encodeURIComponent( range.since ) );
		}

		if ( range.until ) {
			parts.push( 'until=' + encodeURIComponent( range.until ) );
		}

		return '?' + parts.join( '&' );
	}

	function loadReport() {
		var note = root.querySelector( '[data-ys-report-note]' );

		if ( note ) {
			note.textContent = t( 'reportLoading' );
		}

		request( 'reports/overview' + reportQuery() )
			.then( function ( payload ) {
				report.loaded = true;
				report.data = payload;
				renderReport( payload );
			} )
			.catch( function ( error ) {
				if ( note ) {
					note.textContent = error.message || t( 'reportFailed' );
				}
			} );
	}

	function renderReport( data ) {
		var note = root.querySelector( '[data-ys-report-note]' );

		if ( note ) {
			note.textContent = '';

			if ( data.currencies && data.currencies.length > 1 ) {
				note.appendChild( el( 'p', {
					class: 'notice notice-warning inline',
					text: sprintf( t( 'mixedCurrency' ), [ data.currencies.join( ', ' ) ] )
				} ) );
			}

			if ( ! data.history_rows ) {
				note.appendChild( el( 'p', { class: 'notice notice-info inline', text: t( 'noHistoryYet' ) } ) );
			}
		}

		setHistoryCount( data.history_rows );
		renderAxisChips( data.axis );
		renderFunnel( data.funnel, data.orders_url );
		renderDistribution( 'order', data.order_statuses );
		renderDistribution( 'shipping', data.shipping_statuses );
		renderDwell( data.dwell );
		renderStalled( data.stalled, data.orders_url );
	}

	/**
	 * Name the axis next to the three headings it applies to.
	 *
	 * Without it the funnel and the stuck list change under the operator when
	 * they flip the switch, with nothing on screen saying which workflow they
	 * are now reading — and the two workflows can legitimately share step names.
	 */
	function renderAxisChips( axis ) {
		var name = 'shipping' === axis ? t( 'axisShipping' ) : t( 'axisOrder' );

		root.querySelectorAll( '[data-ys-axis-chip]' ).forEach( function ( chip ) {
			chip.textContent = name;
		} );
	}

	function renderFunnel( steps, ordersUrl ) {
		var target = root.querySelector( '[data-ys-funnel]' );

		if ( ! target ) {
			return;
		}

		target.textContent = '';

		if ( ! steps || ! steps.length ) {
			target.appendChild( el( 'p', { class: 'description', text: t( 'noData' ) } ) );
			return;
		}

		var widest = steps.reduce( function ( max, step ) {
			return Math.max( max, step.total_count );
		}, 0 );

		steps.forEach( function ( step ) {
			var share = widest ? Math.round( ( step.total_count / widest ) * 100 ) : 0;

			target.appendChild( el( 'div', { class: 'ys-fct-status-funnel-row' }, [
				el( 'span', { class: 'ys-fct-status-funnel-label' }, [
					el( 'span', { class: 'ys-fct-status-dot', style: 'background:' + ( step.color || '#64748b' ) } ),
					document.createTextNode( ' ' + step.label )
				] ),
				el( 'span', { class: 'ys-fct-status-bar' }, [
					el( 'span', {
						class: 'ys-fct-status-bar-fill',
						style: 'width:' + share + '%;background:' + ( step.color || '#64748b' )
					} )
				] ),
				el( 'a', {
					class: 'ys-fct-status-funnel-count',
					href: ordersUrl || cfg.ordersUrl || '#',
					text: String( step.total_count )
				} )
			] ) );
		} );
	}

	function table( headings, rows, emptyText ) {
		if ( ! rows.length ) {
			return el( 'p', { class: 'description', text: emptyText } );
		}

		var head = el( 'tr', {}, headings.map( function ( heading ) {
			return el( 'th', { scope: 'col', text: heading } );
		} ) );

		return el( 'table', { class: 'widefat striped ys-fct-status-report-table' }, [
			el( 'thead', {}, [ head ] ),
			el( 'tbody', {}, rows )
		] );
	}

	function money( cents ) {
		return ( cents / 100 ).toFixed( 2 );
	}

	/**
	 * Days, unless days would round to nothing.
	 *
	 * A workflow step is measured in days because that is the unit a production
	 * schedule runs on, but printing "0 days" next to three hundred completed
	 * stays tells the operator nothing — it looks like the column is broken
	 * rather than like the step is quick.
	 */
	function duration( days ) {
		if ( days >= 1 ) {
			return days.toFixed( days >= 10 ? 0 : 1 ) + ' ' + t( 'days' );
		}

		var hours = days * 24;

		if ( hours >= 1 ) {
			return hours.toFixed( 1 ) + ' ' + t( 'hours' );
		}

		var minutes = Math.round( hours * 60 );

		// Zero would be a lie of precision: the stay happened, it was just
		// shorter than the smallest unit this column prints.
		return ( minutes > 0 ? minutes : '<1' ) + ' ' + t( 'minutes' );
	}

	function renderDistribution( axis, rows ) {
		var target = root.querySelector( '[data-ys-distribution="' + axis + '"]' );

		if ( ! target ) {
			return;
		}

		target.textContent = '';

		rows = rows || [];

		var widest = rows.reduce( function ( max, row ) {
			return Math.max( max, row.total_count );
		}, 0 );

		var body = rows.map( function ( row ) {
			var share = widest ? Math.round( ( row.total_count / widest ) * 100 ) : 0;

			return el( 'tr', {}, [
				el( 'td', {}, [
					el( 'span', { class: 'ys-fct-status-dot', style: 'background:' + ( row.color || '#64748b' ) } ),
					document.createTextNode( ' ' + row.label ),
					el( 'br' ),
					el( 'code', { text: row.slug } )
				] ),
				el( 'td', { class: 'ys-fct-status-num', text: String( row.paid_count ) } ),
				el( 'td', { class: 'ys-fct-status-num', text: money( row.paid_amount ) } ),
				el( 'td', { class: 'ys-fct-status-num', text: String( row.unpaid_count ) } ),
				el( 'td', { class: 'ys-fct-status-num', text: money( row.unpaid_amount ) } ),
				el( 'td', {}, [
					el( 'span', { class: 'ys-fct-status-bar' }, [
						el( 'span', {
							class: 'ys-fct-status-bar-fill',
							style: 'width:' + share + '%;background:' + ( row.color || '#64748b' )
						} )
					] ),
					el( 'span', { class: 'ys-fct-status-num-inline', text: String( row.total_count ) } )
				] )
			] );
		} );

		target.appendChild( table(
			[
				t( 'colStatus' ),
				t( 'colPaid' ) + ' · ' + t( 'colOrders' ),
				t( 'colPaid' ) + ' · ' + t( 'colAmount' ),
				t( 'colUnpaid' ) + ' · ' + t( 'colOrders' ),
				t( 'colUnpaid' ) + ' · ' + t( 'colAmount' ),
				t( 'colTotal' )
			],
			body,
			t( 'noData' )
		) );
	}

	function renderDwell( rows ) {
		var target = root.querySelector( '[data-ys-dwell]' );

		if ( ! target ) {
			return;
		}

		target.textContent = '';

		var body = ( rows || [] ).filter( function ( row ) {
			return row.is_custom || row.samples > 0;
		} ).map( function ( row ) {
			return el( 'tr', {}, [
				el( 'td', {}, [
					el( 'span', { class: 'ys-fct-status-dot', style: 'background:' + ( row.color || '#64748b' ) } ),
					document.createTextNode( ' ' + row.label )
				] ),
				el( 'td', { class: 'ys-fct-status-num', text: String( row.samples ) } ),
				el( 'td', { class: 'ys-fct-status-num', text: row.samples ? duration( row.avg_days ) : '—' } ),
				el( 'td', { class: 'ys-fct-status-num', text: row.samples ? duration( row.max_days ) : '—' } )
			] );
		} );

		target.appendChild( table(
			[ t( 'colStatus' ), t( 'colStays' ), t( 'colAverage' ), t( 'colLongest' ) ],
			body,
			t( 'noDwell' )
		) );
	}

	function renderStalled( stalled, ordersUrl ) {
		var target = root.querySelector( '[data-ys-stalled]' );

		if ( ! target ) {
			return;
		}

		target.textContent = '';

		stalled = stalled || { days: 0, orders: [] };

		var body = stalled.orders.map( function ( order ) {
			return el( 'tr', {}, [
				el( 'td', {}, [
					el( 'a', {
						href: ( ordersUrl || cfg.ordersUrl || '#' ) + '/' + order.order_id,
						text: '#' + order.order_id
					} )
				] ),
				el( 'td', { text: order.label || order.status } ),
				el( 'td', { text: order.entered_at } ),
				el( 'td', { class: 'ys-fct-status-num ys-fct-status-late', text: String( order.days ) } )
			] );
		} );

		target.appendChild( table(
			[ t( 'colOrder' ), t( 'colStatus' ), t( 'colSince' ), t( 'colDays' ) ],
			body,
			sprintf( t( 'noStalled' ), [ stalled.days ] )
		) );
	}

	function download( filename, content, mime ) {
		var blob = new window.Blob( [ content ], { type: mime } );
		var url = window.URL.createObjectURL( blob );
		var link = el( 'a', { href: url, download: filename } );

		document.body.appendChild( link );
		link.click();
		link.remove();
		window.URL.revokeObjectURL( url );
	}

	var refreshButton = root.querySelector( '[data-ys-report-refresh]' );

	if ( refreshButton ) {
		refreshButton.addEventListener( 'click', loadReport );
	}

	root.querySelectorAll( '[data-ys-report-axis]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', loadReport );
	} );

	var clearButton = root.querySelector( '[data-ys-report-clear]' );

	if ( clearButton ) {
		clearButton.addEventListener( 'click', function () {
			root.querySelectorAll( '[data-ys-range]' ).forEach( function ( field ) {
				field.value = '';
			} );

			loadReport();
		} );
	}

	root.querySelectorAll( '[data-ys-export-report]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var type = button.getAttribute( 'data-ys-export-report' );

			// `reportQuery()` always carries the axis, so it is never empty and
			// the separator is always an ampersand.
			request( 'reports/export' + reportQuery() + '&type=' + encodeURIComponent( type ) )
				.then( function ( payload ) {
					download( payload.filename, payload.csv, 'text/csv;charset=utf-8' );
				} )
				.catch( function ( error ) {
					notice( error.message || t( 'reportFailed' ), 'error' );
				} );
		} );
	} );

	var exportButton = root.querySelector( '[data-ys-export]' );

	if ( exportButton ) {
		exportButton.addEventListener( 'click', function () {
			request( 'export' ).then( function ( payload ) {
				var blob = new window.Blob( [ JSON.stringify( payload, null, 2 ) ], { type: 'application/json' } );
				var url = window.URL.createObjectURL( blob );
				var link = el( 'a', { href: url, download: 'ys-fluentcart-order-statuses.json' } );

				document.body.appendChild( link );
				link.click();
				link.remove();
				window.URL.revokeObjectURL( url );
			} ).catch( function ( error ) {
				notice( error.message, 'error' );
			} );
		} );
	}

	var importButton = root.querySelector( '[data-ys-import]' );

	if ( importButton ) {
		importButton.addEventListener( 'click', function () {
			var field = root.querySelector( '[data-ys-import-json]' );
			var raw = field ? field.value.trim() : '';

			if ( ! raw ) {
				return;
			}

			var parsed;

			try {
				parsed = JSON.parse( raw );
			} catch ( error ) {
				notice( t( 'importFailed' ), 'error' );
				return;
			}

			importButton.disabled = true;
			notice( t( 'importChecking' ), 'info' );

			// Ask the server what the file would change first, and put that in
			// the confirm: "replace every status?" alone does not tell anyone
			// that their strict mode is about to switch off. A file the server
			// refuses never reaches the dialog at all.
			request( 'import', { method: 'POST', body: { payload: parsed, dry_run: true } } )
				.then( function ( preview ) {
					var lines = ( preview.summary || [] ).join( '\n' );
					var question = preview.empty ? t( 'importNothing' ) : t( 'importConfirm' );

					if ( ! window.confirm( ( lines ? lines + '\n\n' : '' ) + question ) ) {
						notice( '' );
						return null;
					}

					notice( t( 'importWorking' ), 'info' );

					return request( 'import', { method: 'POST', body: { payload: parsed } } ).then( function ( payload ) {
						adopt( payload );
						notice( payload.message, 'success' );
					} );
				} )
				.catch( function ( error ) {
					notice( error.message || t( 'importFailed' ), 'error' );
				} )
				.then( function () {
					importButton.disabled = false;
				} );
		} );
	}

	var undoButton = root.querySelector( '[data-ys-undo]' );

	if ( undoButton ) {
		undoButton.addEventListener( 'click', function () {
			if ( ! window.confirm( t( 'undoConfirm' ) ) ) {
				return;
			}

			undoButton.disabled = true;
			notice( t( 'undoWorking' ), 'info' );

			request( 'import/undo', { method: 'POST', body: {} } )
				.then( function ( payload ) {
					adopt( payload );
					notice( payload.message, 'success' );
				} )
				.catch( function ( error ) {
					notice( error.message || t( 'saveFailed' ), 'error' );
				} )
				.then( function () {
					undoButton.disabled = false;
				} );
		} );
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( ! dirty ) {
			return undefined;
		}

		event.preventDefault();
		event.returnValue = '';
		return '';
	} );

	// Last, once every handler and every module-level binding exists: the daily
	// summary e-mail links to `…&page=ys-fct-order-statuses#reports`, so the
	// fragment has to select a tab rather than being decoration.
	if ( window.location.hash ) {
		showTab( window.location.hash.replace( '#', '' ) );
	}

	request( 'settings' )
		.then( adopt )
		.catch( function ( error ) {
			notice( error.message || t( 'loadFailed' ), 'error' );
		} );
}() );
