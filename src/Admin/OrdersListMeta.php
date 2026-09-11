<?php
/**
 * Extra per-row data on the Orders list payload.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Admin;

use YangSheep\FluentCart\OrderStatuses\History\HistoryRepository;
use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\Labels;
use YangSheep\FluentCart\OrderStatuses\Support\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `fluent_cart/orders_list` — and an honest note about what it buys.
 *
 * The filter receives the paginator on its way out of `OrderController::index()`
 * and can add anything to each row. **The FluentCart admin SPA does not render
 * unknown keys**: `OrdersTable`'s columns are declared in the compiled Vue
 * bundle, so `ys_status_meta` reaches the browser inside the JSON response and
 * is then ignored. That was measured on 1.6.3, not assumed — the network
 * response carries the key and no cell appears.
 *
 * It is still worth adding, for two consumers that are not the SPA:
 *
 *  - anything reading `GET /fluent-cart/v2/orders` directly (an export script,
 *    a BI job, a headless dashboard), which is the same audience the report
 *    REST routes serve; and
 *  - a future FluentCart with a column API, or a site that ships its own small
 *    admin script — the data is already there.
 *
 * Cost is bounded deliberately: one history query for the whole page, not one
 * per row, and nothing at all when no custom statuses are configured.
 */
final class OrdersListMeta {

	/**
	 * @return void
	 */
	public function register() {
		add_filter( 'fluent_cart/orders_list', array( $this, 'decorate' ), 20 );
	}

	/**
	 * @param mixed $orders Paginator of Order models.
	 * @return mixed The same paginator.
	 */
	public function decorate( $orders ) {
		$settings = StatusRegistry::settings();
		$custom   = Settings::customStatuses( 'order', $settings );

		if ( empty( $custom ) || ! is_object( $orders ) || ! method_exists( $orders, 'items' ) ) {
			return $orders;
		}

		$items = $orders->items();

		if ( ! is_array( $items ) && ! ( $items instanceof \Traversable ) ) {
			return $orders;
		}

		$labels   = Labels::resolved( 'order', $settings );
		$colors   = Labels::colors( 'order', $settings );
		$pipeline = Settings::pipeline( $settings );

		$ids = array();

		foreach ( $items as $item ) {
			if ( is_object( $item ) && ! empty( $item->id ) ) {
				$ids[] = (int) $item->id;
			}
		}

		$entered = self::enteredAtFor( $ids );
		$now     = time();

		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || empty( $item->id ) ) {
				continue;
			}

			$orderId = (int) $item->id;
			$slug    = isset( $item->status ) ? (string) $item->status : '';
			$since   = isset( $entered[ $orderId ] ) ? $entered[ $orderId ] : '';
			$step    = array_search( $slug, $pipeline, true );

			$meta = array(
				'slug'          => $slug,
				'label'         => isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug,
				'color'         => isset( $colors[ $slug ] ) ? $colors[ $slug ] : '',
				'is_custom'     => isset( $custom[ $slug ] ),
				'pipeline_step' => false === $step ? 0 : (int) $step + 1,
				'entered_at'    => $since,
				'days_in_status' => '' === $since
					? null
					: round( max( 0, $now - (int) strtotime( $since . ' UTC' ) ) / DAY_IN_SECONDS, 1 ),
			);

			try {
				// setAttribute rather than a property write so the model's own
				// mutator/casting path is respected. Nothing saves these models
				// — `OrderController::index()` reads and returns them — so an
				// attribute with no column behind it is safe here and nowhere
				// else.
				if ( method_exists( $item, 'setAttribute' ) ) {
					$item->setAttribute( 'ys_status_meta', $meta );
				} else {
					$item->ys_status_meta = $meta;
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				unset( $e );
			}
		}

		return $orders;
	}

	/**
	 * When each of these orders entered the status it is on now.
	 *
	 * One query for the page. An order with no history row simply has no entry
	 * here, and the caller reports `null` days rather than guessing.
	 *
	 * @param int[] $ids Order ids.
	 * @return array<int,string> Order id => `Y-m-d H:i:s`.
	 */
	private static function enteredAtFor( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( empty( $ids ) || ! Schema::tableExists() ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$sql = 'SELECT h.order_id, MAX(h.changed_at) AS entered_at'
			. ' FROM `' . Schema::table() . '` h'
			. ' INNER JOIN `' . $wpdb->prefix . 'fct_orders` o ON o.id = h.order_id AND o.status = h.new_status'
			. ' WHERE h.axis = %s AND h.order_id IN (' . $placeholders . ')'
			. ' GROUP BY h.order_id';

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( 'order' ), $ids ) ), ARRAY_A );

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['order_id'] ] = (string) $row['entered_at'];
		}

		return $out;
	}

	/**
	 * Exposed for the test scripts: the same shape, for one order.
	 *
	 * @param int $orderId Order id.
	 * @return array<int,array<string,string>>
	 */
	public static function historyFor( $orderId ) {
		return HistoryRepository::forOrder( $orderId, 'order' );
	}
}
