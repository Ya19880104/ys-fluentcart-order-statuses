<?php
/**
 * Every number on the Order Status Report tab.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Reports;

use YangSheep\FluentCart\OrderStatuses\History\HistoryRepository;
use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\Labels;
use YangSheep\FluentCart\OrderStatuses\Support\OrderRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only, and deliberately close to the SQL.
 *
 * The counts here have to agree with what an operator sees when they open the
 * Orders list and filter by the same status, so every figure is one GROUP BY
 * over `wp_fct_orders` with no model layer, no caching and no cleverness. The
 * test report quotes the raw queries next to the rendered numbers for exactly
 * that reason.
 *
 * Money is in the minor unit FluentCart stores (cents), untouched. Dividing by
 * 100 is a presentation decision and belongs where the currency symbol is.
 *
 * The paid/unpaid split uses `OrderRepository::PAID_STATUSES`, which mirrors
 * core's `getOrderPaymentSuccessStatuses()` — "money arrived", including a
 * partial payment or a partial refund. That is the distinction the client asked
 * about, and it is a payment-status question, never an order-status one.
 */
final class ReportService {

	/** How many stalled orders the report will list at once. */
	const STALL_LIMIT = 200;

	/**
	 * Orders per status, split by whether money arrived.
	 *
	 * @param string $axis     'order' or 'shipping'.
	 * @param string $sinceGmt Optional `Y-m-d H:i:s` lower bound on `created_at`.
	 * @param string $untilGmt Optional upper bound.
	 * @return array<int,array<string,mixed>> One row per status, pipeline order first.
	 */
	public static function distribution( $axis, $sinceGmt = '', $untilGmt = '' ) {
		global $wpdb;

		$axis   = 'shipping' === $axis ? 'shipping' : 'order';
		$column = 'shipping' === $axis ? 'shipping_status' : 'status';

		if ( ! OrderRepository::tableExists() ) {
			return array();
		}

		$paid         = OrderRepository::PAID_STATUSES;
		$placeholders = implode( ', ', array_fill( 0, count( $paid ), '%s' ) );

		$where  = '1 = 1';
		$params = array_merge( $paid, $paid, $paid, $paid );

		if ( '' !== $sinceGmt ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $sinceGmt;
		}

		if ( '' !== $untilGmt ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $untilGmt;
		}

		$sql = 'SELECT `' . $column . '` AS slug,'
			. ' SUM(CASE WHEN payment_status IN (' . $placeholders . ') THEN 1 ELSE 0 END) AS paid_count,'
			. ' SUM(CASE WHEN payment_status IN (' . $placeholders . ') THEN total_amount ELSE 0 END) AS paid_amount,'
			. ' SUM(CASE WHEN payment_status IN (' . $placeholders . ') THEN 0 ELSE 1 END) AS unpaid_count,'
			. ' SUM(CASE WHEN payment_status IN (' . $placeholders . ') THEN 0 ELSE total_amount END) AS unpaid_amount'
			. ' FROM `' . OrderRepository::table() . '`'
			. ' WHERE ' . $where
			. ' GROUP BY `' . $column . '`';

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$settings = StatusRegistry::settings();
		$labels   = Labels::resolved( $axis, $settings );
		$colors   = Labels::colors( $axis, $settings );
		$custom   = Settings::customStatuses( $axis, $settings );

		$found = array();

		foreach ( (array) $rows as $row ) {
			$slug = (string) $row['slug'];

			$found[ $slug ] = array(
				'slug'          => $slug,
				'label'         => isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug,
				'color'         => isset( $colors[ $slug ] ) ? $colors[ $slug ] : '',
				'is_custom'     => isset( $custom[ $slug ] ),
				'paid_count'    => (int) $row['paid_count'],
				'paid_amount'   => (int) $row['paid_amount'],
				'unpaid_count'  => (int) $row['unpaid_count'],
				'unpaid_amount' => (int) $row['unpaid_amount'],
				'total_count'   => (int) $row['paid_count'] + (int) $row['unpaid_count'],
				'total_amount'  => (int) $row['paid_amount'] + (int) $row['unpaid_amount'],
			);
		}

		// A configured status with no orders still belongs on the chart — "zero
		// orders are in production" is a finding, and a row that silently
		// disappears looks like a bug in the report.
		foreach ( array_keys( $labels ) as $slug ) {
			if ( isset( $found[ $slug ] ) ) {
				continue;
			}

			$found[ $slug ] = array(
				'slug'          => $slug,
				'label'         => $labels[ $slug ],
				'color'         => isset( $colors[ $slug ] ) ? $colors[ $slug ] : '',
				'is_custom'     => isset( $custom[ $slug ] ),
				'paid_count'    => 0,
				'paid_amount'   => 0,
				'unpaid_count'  => 0,
				'unpaid_amount' => 0,
				'total_count'   => 0,
				'total_amount'  => 0,
			);
		}

		return self::sortForAxis( $found, $axis, $settings );
	}

	/**
	 * The pipeline as a funnel: how many orders sit on each step right now.
	 *
	 * Deliberately unfiltered by date. "How much work is queued" is a question
	 * about the present, and an order placed last month that is still in
	 * production is exactly the one the operator needs to see.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function funnel() {
		$settings     = StatusRegistry::settings();
		$pipeline     = Settings::pipeline( $settings );
		$distribution = array();

		foreach ( self::distribution( 'order' ) as $row ) {
			$distribution[ $row['slug'] ] = $row;
		}

		$out = array();

		foreach ( $pipeline as $index => $slug ) {
			if ( ! isset( $distribution[ $slug ] ) ) {
				continue;
			}

			$row          = $distribution[ $slug ];
			$row['step']  = $index + 1;
			$row['entry'] = Settings::PIPELINE_ENTRY === $slug;

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Average and longest stay per status, in days.
	 *
	 * @param string $axis     'order' or 'shipping'.
	 * @param string $sinceGmt Optional lower bound on when the status was entered.
	 * @param string $untilGmt Optional upper bound.
	 * @return array<int,array<string,mixed>>
	 */
	public static function dwell( $axis, $sinceGmt = '', $untilGmt = '' ) {
		$axis     = 'shipping' === $axis ? 'shipping' : 'order';
		$settings = StatusRegistry::settings();
		$labels   = Labels::resolved( $axis, $settings );
		$colors   = Labels::colors( $axis, $settings );
		$custom   = Settings::customStatuses( $axis, $settings );

		$stats = HistoryRepository::dwell( $axis, $sinceGmt, $untilGmt );
		$found = array();

		foreach ( $stats as $slug => $stat ) {
			$found[ $slug ] = array(
				'slug'      => $slug,
				'label'     => isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug,
				'color'     => isset( $colors[ $slug ] ) ? $colors[ $slug ] : '',
				'is_custom' => isset( $custom[ $slug ] ),
				'samples'   => $stat['samples'],
				'avg_days'  => round( $stat['avg_seconds'] / DAY_IN_SECONDS, 2 ),
				'max_days'  => round( $stat['max_seconds'] / DAY_IN_SECONDS, 2 ),
			);
		}

		foreach ( array_keys( $custom ) as $slug ) {
			if ( isset( $found[ $slug ] ) ) {
				continue;
			}

			$found[ $slug ] = array(
				'slug'      => $slug,
				'label'     => isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug,
				'color'     => isset( $colors[ $slug ] ) ? $colors[ $slug ] : '',
				'is_custom' => true,
				'samples'   => 0,
				'avg_days'  => 0.0,
				'max_days'  => 0.0,
			);
		}

		return self::sortForAxis( $found, $axis, $settings );
	}

	/**
	 * Orders that have been on a pipeline step for longer than the threshold.
	 *
	 * @param int $days Threshold; 0 uses the configured one.
	 * @return array{days:int,orders:array<int,array<string,mixed>>}
	 */
	public static function stalled( $days = 0 ) {
		$settings = StatusRegistry::settings();
		$days     = $days > 0 ? (int) $days : Settings::stallDays( $settings );

		$labels = Labels::resolved( 'order', $settings );

		// Only the custom steps: an order sitting in `completed` for a month is
		// not stuck, it is finished.
		$slugs  = array_keys( Settings::customStatuses( 'order', $settings ) );
		$orders = HistoryRepository::stalled( $slugs, $days, self::STALL_LIMIT );

		foreach ( $orders as $index => $order ) {
			$orders[ $index ]['label'] = isset( $labels[ $order['status'] ] )
				? $labels[ $order['status'] ]
				: $order['status'];
		}

		return array(
			'days'   => $days,
			'orders' => $orders,
		);
	}

	/**
	 * Everything the report tab renders, in one round trip.
	 *
	 * @param string $sinceGmt Optional lower bound.
	 * @param string $untilGmt Optional upper bound.
	 * @return array<string,mixed>
	 */
	public static function overview( $sinceGmt = '', $untilGmt = '' ) {
		return array(
			'range'             => array(
				'since' => $sinceGmt,
				'until' => $untilGmt,
			),
			'order_statuses'    => self::distribution( 'order', $sinceGmt, $untilGmt ),
			'shipping_statuses' => self::distribution( 'shipping', $sinceGmt, $untilGmt ),
			'funnel'            => self::funnel(),
			'dwell'             => self::dwell( 'order', $sinceGmt, $untilGmt ),
			'stalled'           => self::stalled(),
			'currencies'        => self::currencies(),
			'history_rows'      => HistoryRepository::count(),
			'orders_url'        => admin_url( 'admin.php?page=fluent-cart#/orders' ),
		);
	}

	/**
	 * Currency codes present in the orders table.
	 *
	 * The report sums `total_amount` across whatever is there, so a store with
	 * more than one currency gets told that the money column is a mixed total
	 * rather than being handed a wrong number with a confident symbol on it.
	 *
	 * @return string[]
	 */
	public static function currencies() {
		global $wpdb;

		if ( ! OrderRepository::tableExists() ) {
			return array();
		}

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$codes = $wpdb->get_col(
			'SELECT DISTINCT currency FROM `' . OrderRepository::table() . '` WHERE currency <> \'\' ORDER BY currency ASC'
		);

		return array_map( 'strval', (array) $codes );
	}

	/**
	 * Pipeline steps first, then everything else alphabetically.
	 *
	 * @param array  $rows     Slug-keyed rows.
	 * @param string $axis     Axis.
	 * @param array  $settings Normalised settings.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sortForAxis( array $rows, $axis, array $settings ) {
		$order = array();

		if ( 'order' === $axis ) {
			$order = Settings::pipeline( $settings );
		} else {
			$order = array_keys( Settings::customStatuses( 'shipping', $settings ) );
		}

		$out = array();

		foreach ( $order as $slug ) {
			if ( isset( $rows[ $slug ] ) ) {
				$out[] = $rows[ $slug ];
				unset( $rows[ $slug ] );
			}
		}

		ksort( $rows );

		foreach ( $rows as $row ) {
			$out[] = $row;
		}

		return $out;
	}
}
