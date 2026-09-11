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
