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

	function checkboxLabel( definition, field, label ) {
		return el( 'label', { class: 'ys-fct-status-check' }, [
			el( 'input', {
				type: 'checkbox',
				'aria-label': label,
				checked: !! definition[ field ],
				onchange: function ( event ) {
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

	function targetOptions( axis, exceptSlug ) {
		var options = [];

		Object.keys( builtin[ axis ] || {} ).forEach( function ( slug ) {
			options.push( { value: slug, label: builtin[ axis ][ slug ] } );
		} );

		model[ axis ].forEach( function ( definition ) {
			if ( definition.slug && definition.slug !== exceptSlug ) {
				options.push( { value: definition.slug, label: definition.label || definition.slug } );
			}
		} );

		return options;
	}

	function actionCell( axis, definition, index ) {
		var count = countFor( axis, definition.slug );
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
					openMove( axis, definition, count, cell );
				}
			} ) );
		}

		return cell;
	}

	function openMove( axis, definition, count, cell ) {
		if ( cell.querySelector( '.ys-fct-status-move' ) ) {
			return;
		}

		var template = root.querySelector( '[data-ys-template="move"]' );
		var fragment = template.content.cloneNode( true );
		var box = fragment.querySelector( '.ys-fct-status-move' );
		var select = fragment.querySelector( '[data-ys-move-target]' );

		fragment.querySelector( '[data-ys-move-label]' ).textContent =
			sprintf( t( 'moveTo' ), [ count, definition.label || definition.slug ] );

		select.setAttribute( 'aria-label', t( 'move' ) );

		targetOptions( axis, definition.slug ).forEach( function ( option ) {
			select.appendChild( el( 'option', { value: option.value, text: option.label } ) );
		} );

		fragment.querySelector( '[data-ys-move-cancel]' ).addEventListener( 'click', function () {
			box.remove();
		} );

		fragment.querySelector( '[data-ys-move-go]' ).addEventListener( 'click', function () {
			notice( t( 'moving' ), 'info' );

			request( 'migrate', {
				method: 'POST',
				body: { axis: axis, from: definition.slug, to: select.value }
			} ).then( function ( payload ) {
				usage = payload.usage || usage;
				notice( payload.message, 'success' );
				renderAxis( axis );
				renderUsageSummary();
			} ).catch( function ( error ) {
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
			var columns = 'order' === axis ? 9 : 7;
			body.appendChild( el( 'tr', {}, [ el( 'td', { colspan: String( columns ), class: 'ys-fct-status-empty', text: t( 'noCustom' ) } ) ] ) );
			return;
		}

		model[ axis ].forEach( function ( definition, index ) {
			var cells = [
				el( 'td', {}, [ moveButtons( axis, index ) ] ),
				el( 'td', {}, [
					textCell( definition, 'label', t( 'labelPlaceholder' ) ),
					el( 'br' ),
					textCell( definition, 'description', t( 'description' ) )
				] ),
				el( 'td', {}, [ slugCell( definition ) ] ),
				el( 'td', {}, [ colorCell( definition ) ] )
			];

			if ( 'order' === axis ) {
				cells.push( el( 'td', {}, [ selectCell( definition, 'payment_requirement', [
					{ value: 'any', label: t( 'reqAny' ) },
					{ value: 'paid_only', label: t( 'reqPaid' ) },
					{ value: 'unpaid_only', label: t( 'reqUnpaid' ) }
				], t( 'availableOn' ) ) ] ) );

				cells.push( el( 'td', {}, [ selectCell( definition, 'on_payment', [
					{ value: 'keep', label: t( 'keep' ) },
					{ value: 'let_core_decide', label: t( 'letCore' ) }
				], t( 'afterPayment' ) ) ] ) );
			}

			cells.push( el( 'td', {}, [
				checkboxLabel( definition, 'enabled', t( 'enabled' ) ),
				el( 'br' ),
				checkboxLabel( definition, 'editable', t( 'editable' ) )
			] ) );

			cells.push( el( 'td', { class: 'ys-fct-status-count', text: String( countFor( axis, definition.slug ) ) } ) );
			cells.push( actionCell( axis, definition, index ) );

			body.appendChild( el( 'tr', { 'data-ys-slug-row': definition.slug || '' }, cells ) );
		} );
	}

	function slugCell( definition ) {
		var input = el( 'input', {
			type: 'text',
			value: definition.slug || '',
			maxlength: String( cfg.maxSlug || 20 ),
			'aria-label': t( 'slug' ),
			class: 'code ys-fct-status-slug',
			placeholder: t( 'slugPlaceholder' ),
			'data-ys-slug': '1'
		} );

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
				var count = countFor( axis, definition.slug );

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

	function renderAll() {
		renderAxis( 'order' );
		renderAxis( 'shipping' );
		renderOverrides( 'order' );
		renderOverrides( 'payment' );
		renderOverrides( 'shipping' );
		renderUsageSummary();

		var toggle = root.querySelector( '[data-ys-restore-toggle]' );

		if ( toggle ) {
			toggle.checked = 'no' !== model.restore_on_payment;
		}
	}

	// ── state ────────────────────────────────────────────────────────────────

	function adopt( payload ) {
		model = payload.settings;
		model.overrides = model.overrides || { order: {}, payment: {}, shipping: {} };

		[ 'order', 'payment', 'shipping' ].forEach( function ( axis ) {
			model.overrides[ axis ] = model.overrides[ axis ] || {};
		} );

		builtin = payload.builtin || {};
		usage = payload.usage || { order: {}, shipping: {} };
		dirty = false;

		renderAll();
	}

	function save() {
		notice( t( 'saving' ), 'info' );

		var payload = JSON.parse( JSON.stringify( model ) );

		[ 'order', 'shipping' ].forEach( function ( axis ) {
			payload[ axis ] = payload[ axis ].map( function ( definition, index ) {
				delete definition.__isNew;
				delete definition.__slugTouched;
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

	root.querySelectorAll( '[data-ys-tab]' ).forEach( function ( tab ) {
		tab.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			root.querySelectorAll( '[data-ys-tab]' ).forEach( function ( other ) {
				other.classList.toggle( 'nav-tab-active', other === tab );
			} );

			root.querySelectorAll( '[data-ys-panel]' ).forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-ys-panel' ) !== tab.getAttribute( 'data-ys-tab' );
			} );
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
			}

			model[ axis ].push( definition );
			markDirty();
			renderAxis( axis );
		} );
	} );

	root.querySelectorAll( '[data-ys-save]' ).forEach( function ( button ) {
		button.addEventListener( 'click', save );
	} );

	var restoreToggle = root.querySelector( '[data-ys-restore-toggle]' );

	if ( restoreToggle ) {
		restoreToggle.addEventListener( 'change', function () {
			model.restore_on_payment = restoreToggle.checked ? 'yes' : 'no';
			markDirty();
		} );
	}

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

			if ( ! window.confirm( t( 'importConfirm' ) ) ) {
				return;
			}

			var parsed;

			try {
				parsed = JSON.parse( raw );
			} catch ( error ) {
				notice( t( 'importFailed' ), 'error' );
				return;
			}

			request( 'import', { method: 'POST', body: { payload: parsed } } )
				.then( function ( payload ) {
					adopt( payload );
					notice( payload.message, 'success' );
				} )
				.catch( function ( error ) {
					notice( error.message || t( 'importFailed' ), 'error' );
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

	request( 'settings' )
		.then( adopt )
		.catch( function ( error ) {
			notice( error.message || t( 'loadFailed' ), 'error' );
		} );
}() );
