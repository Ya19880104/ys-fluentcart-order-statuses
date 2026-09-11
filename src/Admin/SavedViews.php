<?php
/**
 * One "saved view" per custom order status, on the Orders table.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Admin;

use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\OrderContext;
use YangSheep\FluentCart\OrderStatuses\Support\OrderRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one way to get a custom status onto the Orders list as a clickable tab.
 *
 * The tab strip itself (All / On Hold / Paid / Completed …) comes from
 * `OrderFilter::tabsMap()`, which is hardcoded and unfiltered. Saved views are
 * the supported extension point beside it: the SPA's table base class reads
 * `window.fluentCartAdminApp.table_config.<table>.saved_views` and renders each
 * entry as another tab, and the server resolves the slug it sends back through
 * this same filter.
 *
 * Measured structure of one view (FluentCart 1.6.3):
 *
 *     [ 'id', 'slug', 'name', 'description', 'is_public', 'owner_id',
 *       'query_params' => [ 'filter_type' => 'simple'|'advanced', 'search',
 *                           'active_view', 'advanced_filters' ] ]
 *
 * `filter_type` is `simple` here, not `advanced`, and that is not a style
 * choice: `BaseFilter::applyAdvancedFilter()` opens with
 * `if (!App::isProActive()) { return; }`, so an advanced-filter view on a store
 * without FluentCart Pro silently matches every order. The simple search
 * expression `status = <slug>` goes through `applySimpleOperatorFilter()`,
 * which is not gated, and resolves to `WHERE status = '<slug>'`.
 *
 * The filter is called from two places with two different jobs, and they are
 * told apart by `$args['filterOptions']`:
 *
 *  - `MenuHandler` (non-empty) builds the blob the browser gets — this is where
 *    the labels and the per-status counts are worth a query.
 *  - `BaseFilter::parseAcceptedView()` (empty) is resolving an incoming
 *    `active_view` slug on a list request, and only needs slug and query_params.
 */
final class SavedViews {

	/** Prefix, so a view can never collide with a core tab key. */
	const SLUG_PREFIX = 'ys_status_';

	/**
	 * Prefix for the shipping-axis views.
	 *
	 * Separate from the order one because the two axes can legitimately hold
	 * the same slug — the fulfilment template deliberately reuses
	 * `in_production` and `ship_scheduled` — and a saved view is addressed by
	 * its own slug, so one prefix would give two views the same id.
	 */
	const SHIPPING_SLUG_PREFIX = 'ys_shipping_';

	/**
	 * @return void
	 */
	public function register() {
		add_filter( 'fluent_cart/admin_table_saved_views', array( $this, 'addOrderViews' ), 20, 2 );
		add_filter( 'fluent_cart/orders_list_filter_query', array( $this, 'applyShippingView' ), 20 );
	}

	/**
	 * Apply a shipping-axis saved view to the list query.
	 *
	 * The order-axis views need nothing here: their `search` expression is
	 * `status = <slug>`, and `status` is one of the columns
	 * `OrderFilter::getSearchableFields()` knows, so
	 * `applySimpleOperatorFilter()` resolves it into `WHERE status = …` on its
	 * own.
	 *
	 * **`shipping_status` is not in that map.** Measured on 1.6.3: the list is
	 * `id`, `status`, `invoice`, `payment`, `payment_by`, `customer` (plus
	 * `license` with Pro), the method is `static` and has no filter, and a
	 * search expression naming any other column falls through to the free-text
	 * branch — which looks for the whole string `shipping_status = shipped`
	 * inside invoice numbers, customer names and product titles, and therefore
	 * matches nothing at all. So a shipping-axis view carries no search
	 * expression, and the clause is added here instead, on the one filter
	 * FluentCart applies to the finished list query.
	 *
	 * **Version note — measured, do not gate this on 1.6.3.** The hook does not
	 * appear in a source search for its literal name, because `BaseFilter` composes
	 * it: `apply_filters("fluent_cart/{$filter}_list_filter_query", …)`, with
	 * `$filter` from `getFilterName()`. Both call sites are byte-identical in
	 * 1.6.0 (`BaseFilter.php:1119` and `:1128`) and 1.6.3 (`:1409`, `:1418`), and
	 * the shipping-axis views are asserted end to end through a real list request
	 * on both versions — `changer-scenarios.php` S5 and `pipeline-scenarios.php`
	 * R1. Gating them on 1.6.3 would take a working feature away from every
	 * 1.6.0 store, which is the version this plugin's minimum already names.
	 *
	 * @param mixed $query Fluent ORM builder.
	 * @return mixed
	 */
	public function applyShippingView( $query ) {
		$slug = self::shippingSlugFor( OrderContext::activeView() );

		if ( '' === $slug || ! is_object( $query ) || ! method_exists( $query, 'where' ) ) {
			return $query;
		}

		return $query->where( 'shipping_status', $slug );
	}

	/**
	 * @param string $view Active saved-view slug.
	 * @return string The shipping slug it selects, or ''.
	 */
	public static function shippingSlugFor( $view ) {
		$view = (string) $view;

		if ( '' === $view || 0 !== strpos( $view, self::SHIPPING_SLUG_PREFIX ) ) {
			return '';
		}

		$slug = substr( $view, strlen( self::SHIPPING_SLUG_PREFIX ) );

		// Only a status this plugin actually defines. Without the check the
		// view slug would be a free text channel into a WHERE clause, and the
		// fact that it is parameterised is not a reason to accept one.
		$custom = Settings::customStatuses( 'shipping', StatusRegistry::settings() );

		return isset( $custom[ $slug ] ) ? $slug : '';
	}

	/**
	 * @param mixed $tableConfig Table config keyed by table name.
	 * @param mixed $args        `['filterOptions' => array]`.
	 * @return array
	 */
	public function addOrderViews( $tableConfig, $args = array() ) {
		$tableConfig = is_array( $tableConfig ) ? $tableConfig : array();

		$settings = StatusRegistry::settings();
		$custom   = Settings::customStatuses( 'order', $settings );
		$shipping = Settings::customStatuses( 'shipping', $settings );

		if ( empty( $custom ) && empty( $shipping ) ) {
			return $tableConfig;
		}

		$withCounts = is_array( $args ) && ! empty( $args['filterOptions'] );
		$counts     = array();
		$shipCounts = array();

		if ( $withCounts && OrderRepository::tableExists() ) {
			$counts     = OrderRepository::countByStatus( 'order', array_keys( $custom ) );
			$shipCounts = OrderRepository::countByStatus( 'shipping', array_keys( $shipping ) );
		}

		$views = array();

		foreach ( $custom as $slug => $definition ) {
			$views[] = self::view( $slug, $definition, isset( $counts[ $slug ] ) ? (int) $counts[ $slug ] : null );
		}

		foreach ( $shipping as $slug => $definition ) {
			$views[] = self::shippingView( $slug, $definition, isset( $shipCounts[ $slug ] ) ? (int) $shipCounts[ $slug ] : null );
		}

		if ( ! isset( $tableConfig['order_table'] ) || ! is_array( $tableConfig['order_table'] ) ) {
			// `parseAcceptedView()` hands us a bare `[]`, so the entry has to be
			// created rather than extended — without this the resolver finds
			// nothing and the view falls back to showing every order.
			$tableConfig['order_table'] = array();
		}

		$existing = isset( $tableConfig['order_table']['saved_views'] ) && is_array( $tableConfig['order_table']['saved_views'] )
			? $tableConfig['order_table']['saved_views']
			: array();

		$tableConfig['order_table']['saved_views'] = array_merge( $existing, $views );

		return $tableConfig;
	}

	/**
	 * @param string   $slug       Status slug.
	 * @param array    $definition Status definition.
	 * @param int|null $count      Orders on this status, or null when not counted.
	 * @return array<string,mixed>
	 */
	private static function view( $slug, array $definition, $count ) {
		$name = $definition['label'];

		if ( null !== $count ) {
			$name .= ' (' . $count . ')';
		}

		return array(
			'id'           => self::SLUG_PREFIX . $slug,
			'slug'         => self::SLUG_PREFIX . $slug,
			'name'         => $name,
			'description'  => $definition['description'],
			'is_public'    => 1,
			// 0, never the current user: `isViewOwner()` decides whether to show
			// rename and delete controls, and these views are not editable —
			// they follow the status definitions.
			'owner_id'     => 0,
			'query_params' => array(
				'filter_type' => 'simple',
				'search'      => 'status = ' . $slug,
			),
		);
	}

	/**
	 * A shipping-axis view.
	 *
	 * `search` is deliberately empty — see `applyShippingView()` for why a
	 * `shipping_status = …` expression cannot work — and `query_params` is
	 * still an array rather than nothing, because `applySavedViewFilter()`
	 * returns early on an empty one and the SPA reads the shape.
	 *
	 * @param string   $slug       Shipping status slug.
	 * @param array    $definition Status definition.
	 * @param int|null $count      Orders on this status, or null when not counted.
	 * @return array<string,mixed>
	 */
	private static function shippingView( $slug, array $definition, $count ) {
		// The axis marker is not decoration. The two axes can hold the same
		// slug — the two templates deliberately do — so without it the Orders
		// list can show two views called "In production" with different counts
		// and nothing to say which column each one filters.
		$name = sprintf(
			/* translators: %s: shipping status label */
			__( '%s · shipping', 'ys-fluentcart-order-statuses' ),
			$definition['label']
		);

		if ( null !== $count ) {
			$name .= ' (' . $count . ')';
		}

		return array(
			'id'           => self::SHIPPING_SLUG_PREFIX . $slug,
			'slug'         => self::SHIPPING_SLUG_PREFIX . $slug,
			'name'         => $name,
			'description'  => $definition['description'],
			'is_public'    => 1,
			'owner_id'     => 0,
			'query_params' => array(
				'filter_type' => 'simple',
				'search'      => '',
			),
		);
	}
}
