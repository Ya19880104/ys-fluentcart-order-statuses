<?php
/**
 * Reads and writes against the status-history table.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\History;

use YangSheep\FluentCart\OrderStatuses\Support\OrderRepository;
use YangSheep\FluentCart\OrderStatuses\Support\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every query against `ys_fct_status_history`, in one place.
 *
 * A row means "this order entered `new_status` at `changed_at`". Time *in* a
 * status is therefore the gap to the next row for the same order and axis —
 * which is why `dwell()` self-joins rather than storing a duration: a duration
 * column would be wrong for the row that is still open, and every report needs
 * that one most.
 *
 * Rows are ordered by `changed_at` and then `id`, never by `id` alone. Backfilled
 * rows are inserted long after the transitions they describe, so id order and
 * chronological order are not the same thing.
 */
final class HistoryRepository {

	/** Guard for pathological report queries. */
	const MAX_ROWS = 500;

	/**
	 * Write one transition.
	 *
	 * @param int    $orderId   Order id.
	 * @param string $axis      'order' or 'shipping'.
	 * @param string $oldStatus Slug before.
	 * @param string $newStatus Slug after.
	 * @param string $source    One of Schema::SOURCES.
	 * @param string $changedBy Who did it; '' resolves to the current user.
	 * @param string $changedAt GMT `Y-m-d H:i:s`; '' is now.
	 * @return bool Whether a row was written.
	 */
	public static function record( $orderId, $axis, $oldStatus, $newStatus, $source = 'hook', $changedBy = '', $changedAt = '' ) {
		global $wpdb;

		$orderId = (int) $orderId;
		$axis    = in_array( $axis, Schema::AXES, true ) ? $axis : 'order';
		$source  = in_array( $source, Schema::SOURCES, true ) ? $source : 'hook';

		if ( $orderId <= 0 || '' === (string) $newStatus ) {
			return false;
		}

		if ( ! Schema::tableExists() ) {
			return false;
		}

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$written = $wpdb->insert(
			Schema::table(),
			array(
				'order_id'   => $orderId,
				'axis'       => $axis,
				'old_status' => substr( (string) $oldStatus, 0, 20 ),
				'new_status' => substr( (string) $newStatus, 0, 20 ),
				'changed_by' => '' === $changedBy ? self::currentActor() : substr( (string) $changedBy, 0, 100 ),
				'source'     => $source,
				'changed_at' => '' === $changedAt ? gmdate( 'Y-m-d H:i:s' ) : $changedAt,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (bool) $written;
	}

	/**
	 * Who is making the change, as a display string.
	 *
	 * Deliberately a name and not a user id: the row outlives the user account,
	 * and "deleted user 7" is worse history than "Jane Doe".
	 *
	 * @return string
	 */
	public static function currentActor() {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return 'system';
		}

		$user = wp_get_current_user();

		if ( ! $user || empty( $user->ID ) ) {
			return defined( 'DOING_CRON' ) && DOING_CRON ? 'cron' : 'system';
		}

		$name = trim( (string) $user->display_name );

		return '' === $name ? (string) $user->user_login : $name;
	}

	/**
	 * One order's timeline, oldest first.
	 *
	 * @param int    $orderId Order id.
	 * @param string $axis    '' for both axes.
	 * @param int    $limit   Maximum rows.
	 * @return array<int,array<string,string>>
	 */
	public static function forOrder( $orderId, $axis = '', $limit = 100 ) {
		global $wpdb;

		$orderId = (int) $orderId;
		$limit   = max( 1, min( self::MAX_ROWS, (int) $limit ) );

		if ( $orderId <= 0 || ! Schema::tableExists() ) {
			return array();
		}

		$table = Schema::table();

		if ( in_array( $axis, Schema::AXES, true ) ) {
			//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, order_id, axis, old_status, new_status, changed_by, source, changed_at'
					. ' FROM `' . $table . '` WHERE order_id = %d AND axis = %s ORDER BY changed_at ASC, id ASC LIMIT %d',
					$orderId,
					$axis,
					$limit
				),
				ARRAY_A
			);
		} else {
			//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, order_id, axis, old_status, new_status, changed_by, source, changed_at'
					. ' FROM `' . $table . '` WHERE order_id = %d ORDER BY changed_at ASC, id ASC LIMIT %d',
					$orderId,
					$limit
				),
				ARRAY_A
			);
		}

		return (array) $rows;
	}

	/**
	 * Average and longest completed stay in each status.
	 *
	 * "Completed" is the operative word: only transitions that have a successor
	 * row count, because an order still sitting in a status has not finished its
	 * stay and averaging it in would drag every number towards zero the moment
	 * a batch of new orders arrives. The open ones are reported separately, by
	 * `stalled()`.
	 *
	 * @param string $axis      'order' or 'shipping'.
	 * @param string $sinceGmt  Optional `Y-m-d H:i:s` lower bound on entry time.
	 * @param string $untilGmt  Optional upper bound.
	 * @return array<string,array{samples:int,avg_seconds:int,max_seconds:int}>
	 */
	public static function dwell( $axis, $sinceGmt = '', $untilGmt = '' ) {
		global $wpdb;

		if ( ! Schema::tableExists() ) {
			return array();
		}

		$axis  = in_array( $axis, Schema::AXES, true ) ? $axis : 'order';
		$table = Schema::table();

		$where  = 'h.axis = %s';
		$params = array( $axis, $axis );

		if ( '' !== $sinceGmt ) {
			$where   .= ' AND h.changed_at >= %s';
			$params[] = $sinceGmt;
		}

		if ( '' !== $untilGmt ) {
			$where   .= ' AND h.changed_at <= %s';
			$params[] = $untilGmt;
		}

		$sql = 'SELECT h.new_status AS slug, COUNT(*) AS samples,'
			. ' AVG(TIMESTAMPDIFF(SECOND, h.changed_at, nx.changed_at)) AS avg_seconds,'
			. ' MAX(TIMESTAMPDIFF(SECOND, h.changed_at, nx.changed_at)) AS max_seconds'
			. ' FROM `' . $table . '` h'
			. ' JOIN `' . $table . '` nx ON nx.id = ('
			. '   SELECT h2.id FROM `' . $table . '` h2'
			. '   WHERE h2.order_id = h.order_id AND h2.axis = %s'
			. '     AND (h2.changed_at > h.changed_at OR (h2.changed_at = h.changed_at AND h2.id > h.id))'
			. '   ORDER BY h2.changed_at ASC, h2.id ASC LIMIT 1'
			. ' )'
			. ' WHERE ' . $where
			. ' GROUP BY h.new_status';

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ $row['slug'] ] = array(
				'samples'     => (int) $row['samples'],
				'avg_seconds' => (int) round( (float) $row['avg_seconds'] ),
				'max_seconds' => (int) $row['max_seconds'],
			);
		}

		return $out;
	}

	/**
	 * Orders that have been sitting on one of these statuses for too long.
	 *
	 * The clock starts at the history row that put the order where it is. When
	 * there is none — an order that predates the plugin and was never backfilled
	 * — `updated_at` stands in, which is the most recent thing core knows about
	 * that row and is never later than the real entry time.
	 *
	 * @param string[] $slugs     Status slugs to watch.
	 * @param int      $days      Threshold in days.
	 * @param int      $limit     Maximum rows.
	 * @param string   $axis      'order' or 'shipping'.
	 * @return array<int,array<string,mixed>>
	 */
	public static function stalled( array $slugs, $days, $limit = 200, $axis = 'order' ) {
		global $wpdb;

		$slugs = array_values( array_filter( array_map( 'strval', $slugs ), 'strlen' ) );

		if ( empty( $slugs ) || ! OrderRepository::tableExists() ) {
			return array();
		}

		$axis   = in_array( $axis, Schema::AXES, true ) ? $axis : 'order';
		$column = 'shipping' === $axis ? 'shipping_status' : 'status';
		$days   = max( 1, min( 365, (int) $days ) );
		$limit  = max( 1, min( self::MAX_ROWS, (int) $limit ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$orders  = OrderRepository::table();
		$history = Schema::table();

		$placeholders = implode( ', ', array_fill( 0, count( $slugs ), '%s' ) );

		// `$axis` and `$column` are both resolved from a two-value whitelist
		// immediately above, never interpolated from the request.
		$entered = Schema::tableExists()
			? '(SELECT MAX(h.changed_at) FROM `' . $history . '` h WHERE h.order_id = o.id AND h.axis = \'' . $axis . '\' AND h.new_status = o.`' . $column . '`)'
			: 'NULL';

		// The derived table is not decoration: `entered_at` is a correlated
		// subquery, and filtering it in a HAVING clause without a GROUP BY turns
		// the statement into an aggregate one under ONLY_FULL_GROUP_BY, which
		// then rejects every plain column beside it.
		$sql = 'SELECT * FROM ('
			. ' SELECT o.id, o.`' . $column . '` AS status, o.payment_status, o.total_amount, o.currency, o.created_at,'
			. ' COALESCE(' . $entered . ', o.updated_at) AS entered_at'
			. ' FROM `' . $orders . '` o'
			. ' WHERE o.`' . $column . '` IN (' . $placeholders . ')'
			. ' ) ys_stalled'
			. ' WHERE entered_at <= %s'
			. ' ORDER BY entered_at ASC'
			. ' LIMIT %d';

		$params = array_merge( $slugs, array( $cutoff, $limit ) );

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$now = time();
		$out = array();

		foreach ( (array) $rows as $row ) {
			$enteredAt = (string) $row['entered_at'];
			$seconds   = $enteredAt ? max( 0, $now - (int) strtotime( $enteredAt . ' UTC' ) ) : 0;

			$out[] = array(
				'order_id'   => (int) $row['id'],
				'status'     => (string) $row['status'],
				'payment'    => (string) $row['payment_status'],
				'total'      => (int) $row['total_amount'],
				'currency'   => (string) $row['currency'],
				'created_at' => (string) $row['created_at'],
				'entered_at' => $enteredAt,
				'days'       => round( $seconds / DAY_IN_SECONDS, 1 ),
			);
		}

		return $out;
	}

	/**
	 * @return int Total rows in the table.
	 */
	public static function count() {
		global $wpdb;

		if ( ! Schema::tableExists() ) {
			return 0;
		}

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . Schema::table() . '`' );
	}

	/**
	 * @param string $source One of Schema::SOURCES.
	 * @return int Rows removed.
	 */
	public static function deleteBySource( $source ) {
		global $wpdb;

		if ( ! in_array( $source, Schema::SOURCES, true ) || ! Schema::tableExists() ) {
			return 0;
		}

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( Schema::table(), array( 'source' => $source ), array( '%s' ) );

		return is_int( $deleted ) ? $deleted : 0;
	}
}
