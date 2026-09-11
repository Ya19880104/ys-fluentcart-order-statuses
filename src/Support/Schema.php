<?php
/**
 * The status-history table.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one table this plugin owns.
 *
 * Everything else lives in a single option, but "how long has this order been
 * sitting in production?" cannot: it needs a row per transition, it is written
 * on a hot path (every status change), and the report reads it with GROUP BY.
 *
 * FluentCart does record status changes in `fct_activity`, but only as a
 * translated English sentence — "Order status has been updated from X to Y" —
 * with no columns to group by and no axis marker. Parsing that on every report
 * load would be both slow and wrong the moment the store language changes, so
 * it is used once, by `History\Backfill`, to seed this table and never again.
 *
 * The schema is versioned in its own option so `dbDelta` runs on upgrade
 * without waiting for a reactivation.
 */
final class Schema {

	/** Bump when the CREATE TABLE below changes. */
	const DB_VERSION = 1;

	const VERSION_OPTION = 'ys_fct_status_db_version';

	/** Axis values the table accepts. */
	const AXES = array( 'order', 'shipping' );

	/**
	 * Where a row came from. Not in the original spec, but `backfill` has to be
	 * distinguishable: it is the only source that can legitimately be deleted
	 * and rebuilt, and a second backfill would otherwise double every row.
	 */
	const SOURCES = array( 'hook', 'restore', 'linked', 'backfill' );

	/**
	 * @return string Fully qualified table name.
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'ys_fct_status_history';
	}

	/**
	 * @return void
	 */
	public static function maybeUpgrade() {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Create or update the table. Safe to call repeatedly.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace- and case-sensitive in ways that are not
		// negotiable: two spaces after PRIMARY KEY, one field per line, KEY
		// names spelled the same way every time, no backticks on the index
		// columns. Reformatting this is how you get a duplicated index on
		// every page load.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			axis varchar(10) NOT NULL DEFAULT 'order',
			old_status varchar(20) NOT NULL DEFAULT '',
			new_status varchar(20) NOT NULL DEFAULT '',
			changed_by varchar(100) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT 'hook',
			changed_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY ys_order_axis (order_id,axis),
			KEY ys_new_status (new_status),
			KEY ys_changed_at (changed_at)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * @return bool Whether the history table is present.
	 */
	public static function tableExists() {
		global $wpdb;

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) );
	}
}
