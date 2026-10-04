<?php
/**
 * Direct, prepared reads and writes against `wp_fct_orders`.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only place this add-on touches FluentCart's tables.
 *
 * It is deliberately `$wpdb` rather than the `Order` model: the two writes here
 * (restoring a status that core just overwrote, and migrating orders off a
 * status being deleted) must NOT re-enter `StatusHelper`/`OrderStatusUpdated`,
 * because the first would recurse into the very event that triggered it and the
 * second would fire one status-changed event per order during a settings save.
 * Both write their own activity entries instead.
 */
final class OrderRepository {

	/** Payment statuses that mean "money has arrived". Mirrors `Status::getOrderPaymentSuccessStatuses()`. */
	const PAID_STATUSES = array( 'paid', 'partially_paid', 'partially_refunded' );

	/** @var array<int,array|null> Per-request row cache, keyed by order id. */
	private static $cache = array();

	/**
	 * @return string Fully qualified orders table name.
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'fct_orders';
	}

	/**
	 * @return bool Whether FluentCart's orders table exists.
	 */
	public static function tableExists() {
		global $wpdb;

		$table = self::table();

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * One order's status columns.
	 *
	 * @param int $orderId Order id.
	 * @return array|null `['status','payment_status','shipping_status','fulfillment_type']` or null.
	 */
	public static function find( $orderId ) {
		global $wpdb;

		$orderId = (int) $orderId;

		if ( $orderId <= 0 ) {
			return null;
		}

		// Per-request cache. Three callers now ask for the same order inside one
		// REST request — the payment-requirement veto, the strict-workflow veto
		// and each of their `editable_order_statuses` passes, and that filter is
		// applied several times per request on its own.
		if ( array_key_exists( $orderId, self::$cache ) ) {
			return self::$cache[ $orderId ];
		}

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, status, payment_status, shipping_status, fulfillment_type FROM `' . self::table() . '` WHERE id = %d',
				$orderId
			),
			ARRAY_A
		);

		self::$cache[ $orderId ] = $row ? $row : null;

		return self::$cache[ $orderId ];
	}

	/**
	 * The order status exactly as the database holds it now.
	 *
	 * Deliberately not `find()`: its per-request cache may already hold a row
	 * read earlier in the request, and the one caller of this method needs
	 * the value from before a write that has not happened yet.
	 *
	 * @param int $orderId Order id.
	 * @return string|null Null when the order does not exist.
	 */
	public static function freshStatus( $orderId ) {
		global $wpdb;

		$orderId = (int) $orderId;

		if ( $orderId <= 0 ) {
			return null;
		}

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM `' . self::table() . '` WHERE id = %d', $orderId ) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Drop the cached row for one order, or all of them.
	 *
	 * Called after this plugin writes a status: the guards read the row again
	 * later in the same request, and a stale `status` there would let a second
	 * change through that the first should have blocked.
	 *
	 * @param int $orderId Order id, or 0 for everything.
	 * @return void
	 */
	public static function flush( $orderId = 0 ) {
		$orderId = (int) $orderId;

		if ( $orderId > 0 ) {
			unset( self::$cache[ $orderId ] );
			return;
		}

		self::$cache = array();
	}

	/**
	 * Set `status` without going through the model.
	 *
	 * @param int    $orderId Order id.
	 * @param string $status  New slug.
	 * @param string $expect  Only write when the row still holds this slug ('' = no check).
	 * @return bool Whether a row was written.
	 */
	public static function setOrderStatus( $orderId, $status, $expect = '' ) {
		global $wpdb;

		$orderId = (int) $orderId;

		if ( $orderId <= 0 || '' === $status ) {
			return false;
		}

		$where = array( 'id' => $orderId );

		if ( '' !== $expect ) {
			// Compare-and-set: if something else moved the order on between
			// core's write and ours, leave it alone rather than resurrecting a
			// status the operator has since changed.
			$where['status'] = $expect;
		}

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			self::table(),
			array( 'status' => $status ),
			$where,
			array( '%s' ),
			'' === $expect ? array( '%d' ) : array( '%d', '%s' )
		);

		self::flush( $orderId );

		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * How many orders currently sit on each slug of one axis.
	 *
	 * @param string   $axis  'order' or 'shipping'.
	 * @param string[] $slugs Slugs to count.
	 * @return array<string,int> slug => count (every requested slug present).
	 */
	public static function countByStatus( $axis, array $slugs ) {
		global $wpdb;

		$column = 'shipping' === $axis ? 'shipping_status' : 'status';
		$counts = array();

		foreach ( $slugs as $slug ) {
			$counts[ $slug ] = 0;
		}

		$slugs = array_values( array_filter( array_map( 'strval', $slugs ), 'strlen' ) );

		if ( empty( $slugs ) ) {
			return $counts;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $slugs ), '%s' ) );

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT `' . $column . '` AS slug, COUNT(*) AS total FROM `' . self::table() . '` WHERE `' . $column . '` IN (' . $placeholders . ') GROUP BY `' . $column . '`',
				$slugs
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$counts[ $row['slug'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Every value in one status column, with how many orders carry it.
	 *
	 * One GROUP BY for the whole column rather than an `IN (…)` list, because
	 * the settings screen needs the values nothing knows about as well as the
	 * known ones — see `Settings::orphanCounts()`.
	 *
	 * @param string $axis 'order' or 'shipping'.
	 * @return array<string,int> value => count.
	 */
	public static function countAll( $axis ) {
		global $wpdb;

		$column = 'shipping' === $axis ? 'shipping_status' : 'status';

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			'SELECT `' . $column . '` AS slug, COUNT(*) AS total FROM `' . self::table() . '` GROUP BY `' . $column . '`',
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$slug = null === $row['slug'] ? '' : (string) $row['slug'];

			$out[ $slug ] = ( isset( $out[ $slug ] ) ? $out[ $slug ] : 0 ) + (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Ids of the orders sitting on one slug, newest first.
	 *
	 * @param string $axis  'order' or 'shipping'.
	 * @param string $slug  Slug.
	 * @param int    $limit Maximum ids; 0 for all of them.
	 * @return int[]
	 */
	public static function idsWithStatus( $axis, $slug, $limit = 500 ) {
		global $wpdb;

		$column = 'shipping' === $axis ? 'shipping_status' : 'status';
		$limit  = (int) $limit;

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $limit > 0
			? $wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM `' . self::table() . '` WHERE `' . $column . '` = %s ORDER BY id DESC LIMIT %d',
					(string) $slug,
					$limit
				)
			)
			: $wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM `' . self::table() . '` WHERE `' . $column . '` = %s ORDER BY id DESC',
					(string) $slug
				)
			);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Move these orders from one slug to another, where they still hold it.
	 *
	 * Compare-and-set per row: an order that something else moved on between
	 * the caller's read and this write keeps the status it now has, and is not
	 * in the returned list. `updated_at` is written the way FluentCart's own
	 * model writes it (GMT, `Y-m-d H:i:s`), so a moved order no longer looks
	 * untouched since long before the move.
	 *
	 * @param string $axis 'order' or 'shipping'.
	 * @param int[]  $ids  Order ids — one batch.
	 * @param string $from Slug the orders are on.
	 * @param string $to   Slug to move them to.
	 * @return int[] Ids that were moved.
	 */
	public static function moveOrders( $axis, array $ids, $from, $to ) {
		global $wpdb;

		$column = 'shipping' === $axis ? 'shipping_status' : 'status';
		$ids    = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( empty( $ids ) || '' === (string) $from || '' === (string) $to || $from === $to ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$still = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM `' . self::table() . '` WHERE `' . $column . '` = %s AND id IN (' . $placeholders . ')',
				array_merge( array( (string) $from ), $ids )
			)
		);

		$still = array_values( array_filter( array_map( 'intval', (array) $still ) ) );

		if ( empty( $still ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $still ), '%d' ) );

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . self::table() . '` SET `' . $column . '` = %s, updated_at = %s WHERE `' . $column . '` = %s AND id IN (' . $placeholders . ')',
				array_merge( array( (string) $to, gmdate( 'Y-m-d H:i:s' ), (string) $from ), $still )
			)
		);

		self::flush();

		return $still;
	}

	/**
	 * @param string $paymentStatus Payment status slug.
	 * @return bool Whether it means the order has been paid (at least partly).
	 */
	public static function isPaid( $paymentStatus ) {
		return in_array( (string) $paymentStatus, self::PAID_STATUSES, true );
	}
}
