<?php
/**
 * T14: does a paid order sitting on a custom status still count as revenue?
 *
 *   wp eval-file tests/revenue-probe.php
 *
 * Asks FluentCart's own reporting endpoint for today's numbers, flips one order
 * between a custom status and `processing`, and asks again. If the numbers do
 * not move, revenue is driven by `payment_status` and custom order statuses are
 * invisible to it — which is the claim in the README.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib-orders.php';

global $wpdb;

$orderId = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}fct_orders WHERE status = %s AND payment_status = %s ORDER BY id DESC LIMIT 1", 'sourcing', 'paid' )
);

if ( ! $orderId ) {
	echo 'No paid order on a custom status — run tests/status-scenarios.php first.' . PHP_EOL;
	exit( 1 );
}

$range = array(
	'date_range' => wp_json_encode( array( gmdate( 'Y-m-d 00:00:00' ), gmdate( 'Y-m-d 23:59:59' ) ) ),
);

/**
 * @return array Selected numbers from FluentCart's own dashboard report.
 */
function ys_revenue_snapshot( $range ) {
	$request = new WP_REST_Request( 'GET', '/fluent-cart/v2/reports/dashboard-stats' );
	$request->set_query_params( $range );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

	wp_set_current_user( 1 );

	$data = rest_do_request( $request )->get_data();

	return $data;
}

ys_status_out( 'probe order', $orderId );
ys_status_out( 'status before', ys_status_order_row( $orderId ) );

$before = ys_revenue_snapshot( $range );
ys_status_out( 'report while on the custom status', $before );

// Flip to processing without going through any status machinery.
$wpdb->update( $wpdb->prefix . 'fct_orders', array( 'status' => 'processing' ), array( 'id' => $orderId ) );
wp_cache_flush();

$after = ys_revenue_snapshot( $range );
ys_status_out( 'report while on processing', $after );

// Put it back.
$wpdb->update( $wpdb->prefix . 'fct_orders', array( 'status' => 'sourcing' ), array( 'id' => $orderId ) );
wp_cache_flush();

ys_status_out( 'status restored', ys_status_order_row( $orderId ) );
ys_status_out( 'identical', wp_json_encode( $before ) === wp_json_encode( $after ) ? 'yes' : 'no' );
