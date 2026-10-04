<?php
/**
 * Uninstall routine.
 *
 * The status definitions are the only thing that can translate a slug sitting
 * in `wp_fct_orders.status` back into a name a human recognises, so deleting
 * them is not a tidy-up — it is data loss for every order still on a custom
 * status. Nothing is removed by default. A site that really wants a clean slate
 * can opt in:
 *
 *     define( 'YS_FCT_STATUS_REMOVE_DATA', true );  // wp-config.php
 *
 * or set the `ys_fct_status_remove_data` option to 'yes' before deleting.
 *
 * Either way, order rows are never touched: use the Move button on the Order
 * Statuses screen first if orders should come off the custom slugs.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$ys_fct_status_should_remove = ( defined( 'YS_FCT_STATUS_REMOVE_DATA' ) && YS_FCT_STATUS_REMOVE_DATA )
	|| 'yes' === get_option( 'ys_fct_status_remove_data' );

if ( ! $ys_fct_status_should_remove ) {
	return;
}

delete_option( 'ys_fct_status_settings' );
delete_option( 'ys_fct_status_remove_data' );
delete_option( 'ys_fct_status_summary_last_sent' );
delete_option( 'ys_fct_status_db_version' );
delete_option( 'ys_fct_status_settings_backup' );

// The status history goes with the definitions — it is a table of slugs whose
// only translation just got deleted.
global $wpdb;

//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'ys_fct_status_history`' );

$ys_fct_status_cron = wp_next_scheduled( 'ys_fct_status_daily_summary' );

if ( $ys_fct_status_cron ) {
	wp_unschedule_event( $ys_fct_status_cron, 'ys_fct_status_daily_summary' );
}
