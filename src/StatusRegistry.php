<?php
/**
 * Where the custom statuses meet FluentCart.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every FluentCart filter this add-on touches, in one place.
 *
 * Two of them (`editable_order_statuses`, `editable_shipping_statuses`) are not
 * only display lists — `OrderResource::updateStatuses()` uses them as the
 * write-side allow-list, so registering there is what makes a custom slug
 * actually settable through FluentCart's own admin UI and REST API. The other
 * three are label sources.
 *
 * Settings are read once per request and cached: these filters fire several
 * times per admin page load (the localize block alone calls
 * `getOrderStatuses()` twice), and each call would otherwise be an option read
 * plus a full sanitise pass.
 */
final class StatusRegistry {

	/** Late enough that a site-level filter can still have the last word. */
	const PRIORITY = 20;

	/** @var array|null Per-request settings cache. */
	private static $cache = null;

	/**
	 * @return void
	 */
	public function register() {
		add_filter( 'fluent_cart/order_statuses', array( $this, 'orderStatuses' ), self::PRIORITY );
		add_filter( 'fluent_cart/editable_order_statuses', array( $this, 'editableOrderStatuses' ), self::PRIORITY );
		add_filter( 'fluent_cart/shipping_statuses', array( $this, 'shippingStatuses' ), self::PRIORITY );
		add_filter( 'fluent_cart/editable_shipping_statuses', array( $this, 'editableShippingStatuses' ), self::PRIORITY );
		add_filter( 'fluent_cart/payment_statuses', array( $this, 'paymentStatuses' ), self::PRIORITY );
		add_filter( 'fluent_cart/admin_app_data', array( $this, 'adminAppData' ), self::PRIORITY );
		add_filter( 'fluent_cart/admin_filter_options', array( $this, 'adminFilterOptions' ), self::PRIORITY );

		// Any write to the option invalidates the request cache — the settings
		// screen saves and then re-reads in the same request.
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'flushCache' ) );
		add_action( 'add_option_' . Settings::OPTION, array( __CLASS__, 'flushCache' ) );
	}

	/**
	 * @return void
	 */
	public static function flushCache() {
		self::$cache = null;
	}

	/**
	 * @return array The normalised settings, read at most once per request.
	 */
	public static function settings() {
		if ( null === self::$cache ) {
			self::$cache = Settings::all();
		}

		return self::$cache;
	}

	/**
	 * Display list of order statuses: built-ins relabelled, customs appended.
	 *
	 * @param mixed $statuses Core's map.
	 * @return array
	 */
	public function orderStatuses( $statuses ) {
		return $this->merge( $statuses, 'order', 'order', false );
	}

	/**
	 * Manually settable order statuses — and the write-side allow-list.
	 *
	 * `payment_requirement` is deliberately NOT applied here; see
	 * `Payment\RequirementGuard` for why (the filter has no idea which order is
	 * being edited, and the admin SPA reads this list once per page load rather
	 * than once per order).
	 *
	 * @param mixed $statuses Core's map.
	 * @return array
	 */
	public function editableOrderStatuses( $statuses ) {
		return self::inPipelineOrder( $this->merge( $statuses, 'order', 'order', true ) );
	}

	/**
	 * Sort a slug => label map so the pipeline reads in workflow order.
	 *
	 * The FluentCart order screen renders this map as a dropdown in array order
	 * and offers no hook of its own for ordering it, so the array order is the
	 * only lever there is. Pipeline steps come first, in the order the operator
	 * arranged them ("Paid → In production → Shipment scheduled → Shipped");
	 * everything else keeps the position core gave it, underneath.
	 *
	 * @param array $statuses Slug => label.
	 * @return array
	 */
	public static function inPipelineOrder( array $statuses ) {
		return self::inPipelineOrderFor( 'order', $statuses );
	}

	/**
	 * @param string $axis     'order' or 'shipping'.
	 * @param array  $statuses Slug => label.
	 * @return array
	 */
	public static function inPipelineOrderFor( $axis, array $statuses ) {
		$ordered = array();

		foreach ( Settings::pipelineFor( $axis, self::settings() ) as $slug ) {
			if ( isset( $statuses[ $slug ] ) ) {
				$ordered[ $slug ] = $statuses[ $slug ];
				unset( $statuses[ $slug ] );
			}
		}

		return $ordered + $statuses;
	}

	/**
	 * Display list of shipping statuses — and, measured on 1.6.3, the list
	 * FluentCart's own "Change Shipping Status" dialog is built from.
	 *
	 * This is **not** the obvious one. The admin SPA localises
	 * `order_statuses`, `editable_order_statuses`, `payment_statuses`,
	 * `editable_payment_statuses` … and `shipping_statuses`. There is no
	 * `editable_shipping_statuses` in `window.fluentCartAdminApp` at all: that
	 * filter is the server-side write allow-list and nothing else. So the
	 * dialog reads *this* map, and ordering only the editable one left the
	 * custom steps listed after `unshippable` — verified in the browser before
	 * this line existed.
	 *
	 * @param mixed $statuses Core's map.
	 * @return array
	 */
	public function shippingStatuses( $statuses ) {
		return self::inPipelineOrderFor( 'shipping', $this->merge( $statuses, 'shipping', 'shipping', false ) );
	}

	/**
	 * Manually settable shipping statuses — and the write-side allow-list.
	 *
	 * Returned in fulfilment order, so FluentCart's own "Change Shipping
	 * Status" dialog reads *Not shipped yet → In production → Shipment
	 * scheduled → Shipped*, with `delivered` and `unshippable` underneath.
	 * That dialog is the one control FluentCart 1.6.3 offers on a **paid**
	 * order, which is why the recommended place for a post-payment workflow is
	 * this axis rather than the order one.
	 *
	 * @param mixed $statuses Core's map.
	 * @return array
	 */
	public function editableShippingStatuses( $statuses ) {
		return self::inPipelineOrderFor( 'shipping', $this->merge( $statuses, 'shipping', 'shipping', true ) );
	}

	/**
	 * Payment statuses are relabelled only — v1 never adds one. See the README
	 * for the download-permission, refund and revenue-report logic that reads
	 * `payment_status` against hardcoded arrays.
	 *
	 * @param mixed $statuses Core's map.
	 * @return array
	 */
	public function paymentStatuses( $statuses ) {
		$statuses = is_array( $statuses ) ? $statuses : array();

		return $this->applyOverrides( $statuses, 'payment' );
	}

	/**
	 * Hand the admin SPA a colour map so a custom status can be painted without
	 * a Vue build. `Admin\ColorStyles` turns it into CSS.
	 *
	 * @param mixed $data Localised admin data.
	 * @return array
	 */
	public function adminAppData( $data ) {
		$data = is_array( $data ) ? $data : array();

		$data['ys_status_colors'] = self::colorMap();

		return $data;
	}

	/**
	 * Add the custom order statuses to the Orders table's advanced filter.
	 *
	 * Core builds that node as a hardcoded four-entry map inside
	 * `OrderFilter::getAdvanceFilterOptions()`, but the whole structure passes
	 * through this filter afterwards, so the node can be found and extended.
	 *
	 * @param mixed $options Filter options.
	 * @return array
	 */
	public function adminFilterOptions( $options ) {
		if ( ! is_array( $options ) || empty( $options['order_filter_options']['advance'] ) ) {
			return is_array( $options ) ? $options : array();
		}

		$advance = $options['order_filter_options']['advance'];

		if ( ! is_array( $advance ) ) {
			return $options;
		}

		$extra = array();

		foreach ( Settings::customStatuses( 'order', self::settings() ) as $slug => $definition ) {
			$extra[ $slug ] = $definition['label'];
		}

		$overrides = self::settings()['overrides']['order'];

		foreach ( $advance as $groupKey => $group ) {
			if ( empty( $group['children'] ) || ! is_array( $group['children'] ) ) {
				continue;
			}

			foreach ( $group['children'] as $childKey => $child ) {
				if ( ! isset( $child['value'] ) || 'status' !== $child['value'] || empty( $child['options'] ) ) {
					continue;
				}

				$statusOptions = (array) $child['options'];

				foreach ( $overrides as $slug => $override ) {
					if ( isset( $statusOptions[ $slug ] ) && '' !== $override['label'] ) {
						$statusOptions[ $slug ] = $override['label'];
					}
				}

				$advance[ $groupKey ]['children'][ $childKey ]['options'] = $statusOptions + $extra;
			}
		}

		$options['order_filter_options']['advance'] = $advance;

		// `admin_filter_options` runs before MenuHandler copies the order
		// options into `tableConfig`, so this one write reaches both.
		return $options;
	}

	/**
	 * Every status slug that has a colour, across all three axes.
	 *
	 * @return array<string,string> slug => `#rrggbb`.
	 */
	public static function colorMap() {
		$settings = self::settings();
		$colors   = array();

		foreach ( array( 'order', 'shipping' ) as $axis ) {
			foreach ( Settings::customStatuses( $axis, $settings ) as $slug => $definition ) {
				$colors[ $slug ] = $definition['color'];
			}
		}

		foreach ( array( 'order', 'payment', 'shipping' ) as $axis ) {
			foreach ( $settings['overrides'][ $axis ] as $slug => $override ) {
				if ( '' !== $override['color'] ) {
					$colors[ $slug ] = $override['color'];
				}
			}
		}

		return $colors;
	}

	/**
	 * @param mixed  $statuses     Core's slug => label map.
	 * @param string $axis         Custom-status axis.
	 * @param string $overrideAxis Override axis key.
	 * @param bool   $editableOnly Whether to skip customs flagged not-editable.
	 * @return array
	 */
	private function merge( $statuses, $axis, $overrideAxis, $editableOnly ) {
		$statuses = is_array( $statuses ) ? $statuses : array();
		$statuses = $this->applyOverrides( $statuses, $overrideAxis );

		foreach ( Settings::customStatuses( $axis, self::settings() ) as $slug => $definition ) {
			if ( $editableOnly && empty( $definition['editable'] ) ) {
				continue;
			}

			// Never shadow a core slug: `sanitize()` rejects reserved slugs, but
			// a row written by an older schema or edited by hand could still
			// carry one, and silently relabelling `completed` here would be a
			// much worse failure than dropping the definition.
			if ( isset( $statuses[ $slug ] ) ) {
				continue;
			}

			$statuses[ $slug ] = $definition['label'];
		}

		return $statuses;
	}

	/**
	 * @param array  $statuses Slug => label.
	 * @param string $axis     Override axis key.
	 * @return array
	 */
	private function applyOverrides( array $statuses, $axis ) {
		$settings = self::settings();

		if ( empty( $settings['overrides'][ $axis ] ) ) {
			return $statuses;
		}

		foreach ( $settings['overrides'][ $axis ] as $slug => $override ) {
			if ( isset( $statuses[ $slug ] ) && '' !== $override['label'] ) {
				$statuses[ $slug ] = $override['label'];
			}
		}

		return $statuses;
	}
}
