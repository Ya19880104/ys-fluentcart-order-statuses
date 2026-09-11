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

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, status, payment_status, shipping_status, fulfillment_type FROM `' . self::table() . '` WHERE id = %d',
				$orderId
			),
			ARRAY_A
		);

		return $row ? $row : null;
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
	 * Ids of the orders sitting on one slug, newest first.
	 *
	 * @param string $axis  'order' or 'shipping'.
	 * @param string $slug  Slug.
	 * @param int    $limit Maximum ids.
	 * @return int[]
	 */
	public static function idsWithStatus( $axis, $slug, $limit = 500 ) {
		global $wpdb;

		$column = 'shipping' === $axis ? 'shipping_status' : 'status';
		$limit  = max( 1, min( 5000, (int) $limit ) );

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM `' . self::table() . '` WHERE `' . $column . '` = %s ORDER BY id DESC LIMIT %d',
				(string) $slug,
				$limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Move every order off one slug onto another.
	 *
	 * @param string $axis 'order' or 'shipping'.
	 * @param string $from Slug to empty.
	 * @param string $to   Slug to move to.
	 * @return int Rows changed.
	 */
	public static function migrateStatus( $axis, $from, $to ) {
		global $wpdb;

		$column = 'shipping' === $axis ? 'shipping_status' : 'status';

		if ( '' === (string) $from || $from === $to ) {
			return 0;
		}

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			self::table(),
			array( $column => (string) $to ),
			array( $column => (string) $from ),
			array( '%s' ),
			array( '%s' )
		);

		return is_int( $updated ) ? $updated : 0;
	}

	/**
	 * @param string $paymentStatus Payment status slug.
	 * @return bool Whether it means the order has been paid (at least partly).
	 */
	public static function isPaid( $paymentStatus ) {
		return in_array( (string) $paymentStatus, self::PAID_STATUSES, true );
	}
}
