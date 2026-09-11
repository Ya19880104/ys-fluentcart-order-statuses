<?php
/**
 * One "saved view" per custom order status, on the Orders table.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Admin;

use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
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
	 * @return void
	 */
	public function register() {
		add_filter( 'fluent_cart/admin_table_saved_views', array( $this, 'addOrderViews' ), 20, 2 );
	}

	/**
	 * @param mixed $tableConfig Table config keyed by table name.
	 * @param mixed $args        `['filterOptions' => array]`.
	 * @return array
	 */
	public function addOrderViews( $tableConfig, $args = array() ) {
		$tableConfig = is_array( $tableConfig ) ? $tableConfig : array();

		$custom = Settings::customStatuses( 'order', StatusRegistry::settings() );

		if ( empty( $custom ) ) {
			return $tableConfig;
		}

		$withCounts = is_array( $args ) && ! empty( $args['filterOptions'] );
		$counts     = array();

		if ( $withCounts && OrderRepository::tableExists() ) {
			$counts = OrderRepository::countByStatus( 'order', array_keys( $custom ) );
		}

		$views = array();

		foreach ( $custom as $slug => $definition ) {
			$views[] = self::view( $slug, $definition, isset( $counts[ $slug ] ) ? (int) $counts[ $slug ] : null );
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
}
