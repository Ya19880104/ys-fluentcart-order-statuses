<?php
/**
 * The T1–T15 walkthrough, driven against the real site.
 *
 *   wp eval-file tests/status-scenarios.php
 *
 * Every status change goes through FluentCart's own REST routes
 * (`rest_do_request`), so `rest_pre_dispatch`, the route permission callback,
 * `OrderResource::updateStatuses()` and `StatusHelper::syncOrderStatuses()` all
 * run exactly as they do for the admin SPA. Assertions read the database row
 * back afterwards, not the model.
 *
 * Creates orders with a `STATUS-` note; touches nothing else.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib-orders.php';

$GLOBALS['ys_status_pass']   = 0;
$GLOBALS['ys_status_failed'] = array();

/**
 * @param string $label    What is being asserted.
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @return void
 */
function ys_assert( $label, $expected, $actual ) {
	if ( $expected === $actual ) {
		$GLOBALS['ys_status_pass']++;
		echo '  ok   ' . $label . PHP_EOL;
		return;
	}

	$GLOBALS['ys_status_failed'][] = $label;
	echo '  FAIL ' . $label . PHP_EOL;
	echo '       expected: ' . wp_json_encode( $expected, JSON_UNESCAPED_UNICODE ) . PHP_EOL;
	echo '       actual:   ' . wp_json_encode( $actual, JSON_UNESCAPED_UNICODE ) . PHP_EOL;
}

/**
 * @param string $name Section name.
 * @return void
 */
function ys_section( $name ) {
	echo PHP_EOL . '# ' . $name . PHP_EOL;
}

/**
 * The status configuration every scenario starts from.
 *
 * @param string $restore 'yes' or 'no'.
 * @return array
 */
function ys_status_config( $restore = 'yes' ) {
	return array(
		'restore_on_payment' => $restore,
		'order'              => array(
			array(
				'slug'                => 'sourcing',
				'label'               => '美國採購中',
				'color'               => '#DB8A3E',
				'description'         => 'Paid; buying the item in the US.',
				'editable'            => true,
				'enabled'             => true,
				'payment_requirement' => 'any',
				'on_payment'          => 'keep',
				'sort_order'          => 10,
			),
			array(
				'slug'                => 'core_decides',
				'label'               => 'Core decides',
				'color'               => '#2563eb',
				'editable'            => true,
				'enabled'             => true,
				'payment_requirement' => 'any',
				'on_payment'          => 'let_core_decide',
				'sort_order'          => 20,
			),
			array(
				'slug'                => 'paid_step',
				'label'               => 'Paid only step',
				'color'               => '#16a34a',
				'editable'            => true,
				'enabled'             => true,
				'payment_requirement' => 'paid_only',
				'on_payment'          => 'keep',
				'sort_order'          => 30,
			),
			array(
				'slug'                => 'unpaid_step',
				'label'               => 'Unpaid only step',
				'color'               => '#dc2626',
				'editable'            => true,
				'enabled'             => true,
				'payment_requirement' => 'unpaid_only',
				'on_payment'          => 'keep',
				'sort_order'          => 40,
			),
			array(
				'slug'                => 'temp_step',
				'label'               => 'Temporary step',
				'color'               => '#7c3aed',
				'description'         => 'Used by T11 so the migration does not disturb the other orders.',
				'editable'            => true,
				'enabled'             => true,
				'payment_requirement' => 'any',
				'on_payment'          => 'keep',
				'sort_order'          => 50,
			),
		),
		'shipping'           => array(
			array(
				'slug'       => 'us_warehouse',
				'label'      => '已到美國倉',
				'color'      => '#16244A',
				'editable'   => true,
				'enabled'    => true,
				'sort_order' => 10,
			),
		),
		'overrides'          => array(
			'order'    => array( 'processing' => array( 'label' => '處理中', 'color' => '#2563eb' ) ),
			'payment'  => array( 'pending' => array( 'label' => '待付款' ) ),
			'shipping' => array( 'unshipped' => array( 'label' => '未出貨' ) ),
		),
	);
}

/**
 * @param string $restore 'yes' or 'no'.
 * @return void
 */
function ys_apply_config( $restore = 'yes' ) {
	Settings::save( ys_status_config( $restore ) );
	StatusRegistry::flushCache();
}

/**
 * @param int    $orderId Order id.
 * @param string $slug    Order status slug.
 * @return array{status:int,data:mixed}
 */
function ys_set_order_status( $orderId, $slug ) {
	return ys_status_rest(
		'PUT',
		'/fluent-cart/v2/orders/' . $orderId . '/statuses',
		array(
			'action'       => 'change_order_status',
			'manage_stock' => false,
			'statuses'     => array( 'order_status' => $slug ),
		)
	);
}

/**
 * @param int    $orderId Order id.
 * @param string $slug    Shipping status slug.
 * @return array{status:int,data:mixed}
 */
function ys_set_shipping_status( $orderId, $slug ) {
	return ys_status_rest(
		'PUT',
		'/fluent-cart/v2/orders/' . $orderId . '/statuses',
		array(
			'action'       => 'change_shipping_status',
			'manage_stock' => false,
			'statuses'     => array( 'shipping_status' => $slug ),
		)
	);
}

/**
 * @param int $orderId Order id.
 * @return array{status:int,data:mixed}
 */
function ys_mark_paid( $orderId ) {
	return ys_status_rest(
		'POST',
		'/fluent-cart/v2/orders/' . $orderId . '/mark-as-paid',
		array(
			'payment_method' => 'cod',
			'mark_paid_note' => 'STATUS- scenario: marked paid',
		)
	);
}

/**
 * @param int    $orderId Order id.
 * @param string $needle  Substring to look for in an activity title.
 * @return bool
 */
function ys_has_activity( $orderId, $needle ) {
	foreach ( ys_status_activity( $orderId, 12 ) as $row ) {
		if ( false !== strpos( $row['title'] . ' ' . $row['content'], $needle ) ) {
			return true;
		}
	}

	return false;
}

// ─────────────────────────────────────────────────────────────────────────────

ys_apply_config( 'yes' );

$fired = array();

foreach ( array( 'sourcing', 'core_decides', 'us_warehouse' ) as $slug ) {
	add_action(
		'fluent_cart/order_status_changed_to_' . $slug,
		function ( $data ) use ( $slug, &$fired ) {
			$fired[] = $slug . '#' . ( isset( $data['order']->id ) ? $data['order']->id : '?' );
		}
	);

	add_action(
		'fluent_cart/shipping_status_changed_to_' . $slug,
		function ( $data ) use ( $slug, &$fired ) {
			$fired[] = 'ship:' . $slug . '#' . ( isset( $data['order']->id ) ? $data['order']->id : '?' );
		}
	);
}

$report = array();

// ── T1 ───────────────────────────────────────────────────────────────────────

ys_section( 'T1 — set a custom order status through FluentCart\'s own API' );

$t1 = ys_status_make_order();
$res = ys_set_order_status( $t1, 'sourcing' );
$row = ys_status_order_row( $t1 );

ys_assert( 'T1 REST accepted', 200, $res['status'] );
ys_assert( 'T1 DB status is sourcing', 'sourcing', $row['status'] );
ys_assert( 'T1 activity records the change', true, ys_has_activity( $t1, 'sourcing' ) );
ys_assert( 'T1 slug appears in editable list', true, in_array( 'sourcing', array_keys( \FluentCart\App\Helpers\Status::getEditableOrderStatuses() ), true ) );
ys_assert( 'T1 label comes from settings', '美國採購中', \FluentCart\App\Helpers\Status::getOrderStatuses()['sourcing'] );
$report['T1'] = array( 'order' => $t1, 'row' => $row );

// ── T2 ───────────────────────────────────────────────────────────────────────

ys_section( 'T2 — order list label, colour and filter option' );

$filters = apply_filters(
	'fluent_cart/admin_filter_options',
	array( 'order_filter_options' => \FluentCart\App\Services\Filter\OrderFilter::getTableFilterOptions() ),
	array()
);

$statusNode = array();

foreach ( $filters['order_filter_options']['advance'] as $group ) {
	foreach ( (array) ( isset( $group['children'] ) ? $group['children'] : array() ) as $child ) {
		if ( isset( $child['value'] ) && 'status' === $child['value'] ) {
			$statusNode = $child['options'];
		}
	}
}

ys_assert( 'T2 custom status is a filter option', '美國採購中', isset( $statusNode['sourcing'] ) ? $statusNode['sourcing'] : null );
ys_assert( 'T2 built-in relabel reaches the filter', '處理中', isset( $statusNode['processing'] ) ? $statusNode['processing'] : null );
ys_assert( 'T2 colour map carries the slug', '#db8a3e', StatusRegistry::colorMap()['sourcing'] );
$report['T2'] = $statusNode;

// ── T3 ───────────────────────────────────────────────────────────────────────

ys_section( 'T3 — slug validation' );

$bad = array(
	'too_long'           => 'this_slug_is_far_too_long_for_varchar20',
	'reserved'           => 'completed',
	'invalid_characters' => '9lives',
);

foreach ( $bad as $expected => $slug ) {
	$code = Settings::slugError( Settings::sanitizeSlug( $slug ), 'order' );
	ys_assert( 'T3 ' . $expected . ' rejected', $expected, $code );
}

ys_assert( 'T3 duplicate rejected', 'duplicate', Settings::slugError( 'sourcing', 'order', array( 'sourcing' ) ) );

$rest = ys_status_rest(
	'POST',
	'/ys-fct-status/v1/settings',
	array(
		'settings' => array(
			'order' => array(
				array( 'slug' => 'completed', 'label' => 'Nope' ),
			),
		),
	)
);

ys_assert( 'T3 REST refuses a reserved slug', 422, $rest['status'] );
$report['T3'] = $rest['data'];

ys_apply_config( 'yes' );

// ── T4 ───────────────────────────────────────────────────────────────────────

ys_section( 'T4 — on_payment=keep survives payment (physical + digital)' );

$t4 = ys_status_make_order();
ys_set_order_status( $t4, 'sourcing' );
$paid = ys_mark_paid( $t4 );
$row = ys_status_order_row( $t4 );

ys_assert( 'T4 mark-as-paid accepted', 200, $paid['status'] );
ys_assert( 'T4 order status is still sourcing', 'sourcing', $row['status'] );
ys_assert( 'T4 payment status is paid', 'paid', $row['payment_status'] );
ys_assert( 'T4 total_paid matches', '6000', $row['total_paid'] );
ys_assert( 'T4 activity explains the restore', true, ys_has_activity( $t4, 'Custom order status kept' ) );
$report['T4'] = array( 'order' => $t4, 'row' => $row, 'activity' => ys_status_activity( $t4, 4 ) );

$t4d = ys_status_make_order( array( 'fulfillment_type' => 'digital' ) );
ys_set_order_status( $t4d, 'sourcing' );
ys_mark_paid( $t4d );
$rowD = ys_status_order_row( $t4d );

ys_assert( 'T4 digital order is not auto-completed', 'sourcing', $rowD['status'] );
ys_assert( 'T4 digital payment status is paid', 'paid', $rowD['payment_status'] );
$report['T4-digital'] = array( 'order' => $t4d, 'row' => $rowD );

// ── T5 ───────────────────────────────────────────────────────────────────────

ys_section( 'T5 — on_payment=let_core_decide hands over to core' );

$t5 = ys_status_make_order();
ys_set_order_status( $t5, 'core_decides' );
ys_mark_paid( $t5 );
$row = ys_status_order_row( $t5 );

ys_assert( 'T5 core moved the order to processing', 'processing', $row['status'] );
ys_assert( 'T5 payment status is paid', 'paid', $row['payment_status'] );
ys_assert( 'T5 no restore note was written', false, ys_has_activity( $t5, 'Custom order status kept' ) );
$report['T5'] = array( 'order' => $t5, 'row' => $row );

// ── T4b: global switch ───────────────────────────────────────────────────────

ys_section( 'T4b — the global restore switch turns the whole mechanism off' );

ys_apply_config( 'no' );

$t4b = ys_status_make_order();
ys_set_order_status( $t4b, 'sourcing' );
ys_mark_paid( $t4b );
$row = ys_status_order_row( $t4b );

ys_assert( 'T4b with restore off, core wins', 'processing', $row['status'] );
$report['T4b'] = array( 'order' => $t4b, 'row' => $row );

ys_apply_config( 'yes' );

// ── T4c: a deliberate admin change is not undone ─────────────────────────────

ys_section( 'T4c — an admin moving a paid order to Processing is not bounced back' );

$t4c = ys_status_make_order();
ys_set_order_status( $t4c, 'sourcing' );
ys_mark_paid( $t4c );
ys_assert( 'T4c restore happened first', 'sourcing', ys_status_order_row( $t4c )['status'] );

ys_set_order_status( $t4c, 'processing' );
$row = ys_status_order_row( $t4c );

ys_assert( 'T4c admin choice sticks', 'processing', $row['status'] );
$report['T4c'] = array( 'order' => $t4c, 'row' => $row );

// ── T6 ───────────────────────────────────────────────────────────────────────

ys_section( 'T6 — payment_requirement' );

$t6 = ys_status_make_order();
$res = ys_set_order_status( $t6, 'paid_step' );

ys_assert( 'T6 paid_only refused on an unpaid order', 422, $res['status'] );
ys_assert( 'T6 the refusal says why', true, false !== strpos( (string) ( is_array( $res['data'] ) ? $res['data']['message'] : '' ), 'paid' ) );
ys_assert( 'T6 the order was not changed', 'on-hold', ys_status_order_row( $t6 )['status'] );

$res = ys_set_order_status( $t6, 'unpaid_step' );
ys_assert( 'T6 unpaid_only accepted on an unpaid order', 200, $res['status'] );
ys_assert( 'T6 DB shows unpaid_step', 'unpaid_step', ys_status_order_row( $t6 )['status'] );

ys_mark_paid( $t6 );
ys_assert( 'T6 unpaid_step was kept after payment', 'unpaid_step', ys_status_order_row( $t6 )['status'] );

$res = ys_set_order_status( $t6, 'paid_step' );
ys_assert( 'T6 paid_only accepted once paid', 200, $res['status'] );
ys_assert( 'T6 DB shows paid_step', 'paid_step', ys_status_order_row( $t6 )['status'] );

$res = ys_set_order_status( $t6, 'unpaid_step' );
ys_assert( 'T6 unpaid_only refused once paid', 422, $res['status'] );
$report['T6'] = array( 'order' => $t6 );

// ── T7 ───────────────────────────────────────────────────────────────────────

ys_section( 'T7 — custom shipping status' );

$t7 = ys_status_make_order();
$res = ys_set_shipping_status( $t7, 'us_warehouse' );
$row = ys_status_order_row( $t7 );

ys_assert( 'T7 REST accepted', 200, $res['status'] );
ys_assert( 'T7 DB shipping_status', 'us_warehouse', $row['shipping_status'] );
ys_assert( 'T7 order status untouched', 'on-hold', $row['status'] );

ys_mark_paid( $t7 );
$row = ys_status_order_row( $t7 );

ys_assert( 'T7 payment does not touch shipping_status', 'us_warehouse', $row['shipping_status'] );
ys_assert( 'T7 payment did move the order status', 'processing', $row['status'] );
$report['T7'] = array( 'order' => $t7, 'row' => $row );

// ── T8 ───────────────────────────────────────────────────────────────────────

ys_section( 'T8 — relabelling a built-in status' );

$orderStatuses = \FluentCart\App\Helpers\Status::getOrderStatuses();
$paymentStatuses = \FluentCart\App\Helpers\Status::getPaymentStatuses();
$shippingStatuses = \FluentCart\App\Helpers\Status::getShippingStatuses();

ys_assert( 'T8 processing is relabelled', '處理中', $orderStatuses['processing'] );
ys_assert( 'T8 completed keeps its label', 'Completed', $orderStatuses['completed'] );
ys_assert( 'T8 payment pending is relabelled', '待付款', $paymentStatuses['pending'] );
ys_assert( 'T8 shipping unshipped is relabelled', '未出貨', $shippingStatuses['unshipped'] );
ys_assert( 'T8 slug in the database is untouched', 'processing', ys_status_order_row( $t5 )['status'] );
$report['T8'] = array( 'order' => $orderStatuses, 'payment' => $paymentStatuses, 'shipping' => $shippingStatuses );

// ── T10 ──────────────────────────────────────────────────────────────────────

ys_section( 'T10 — per-status actions fire' );

ys_assert( 'T10 order_status_changed_to_sourcing fired', true, in_array( 'sourcing#' . $t1, $fired, true ) );
ys_assert( 'T10 order_status_changed_to_core_decides fired', true, in_array( 'core_decides#' . $t5, $fired, true ) );
ys_assert( 'T10 shipping_status_changed_to_us_warehouse fired', true, in_array( 'ship:us_warehouse#' . $t7, $fired, true ) );
$report['T10'] = $fired;

// ── T15 ──────────────────────────────────────────────────────────────────────

ys_section( 'T15 — a canceled order still cannot change status' );

$t15 = ys_status_make_order();
ys_set_order_status( $t15, 'canceled' );
ys_assert( 'T15 order is canceled', 'canceled', ys_status_order_row( $t15 )['status'] );

$res = ys_set_order_status( $t15, 'sourcing' );
ys_assert( 'T15 the change is refused', 'canceled', ys_status_order_row( $t15 )['status'] );
ys_assert( 'T15 core says why', true, false !== strpos( wp_json_encode( $res['data'] ), 'canceled' ) );
$report['T15'] = array( 'order' => $t15, 'response' => $res );

// ── T11 ──────────────────────────────────────────────────────────────────────

ys_section( 'T11 — deleting a status in use migrates its orders' );

// On its own status so the evidence left behind by T1–T7 stays readable in the
// database after this run.
$t11a = ys_status_make_order();
$t11b = ys_status_make_order();
ys_set_order_status( $t11a, 'temp_step' );
ys_set_order_status( $t11b, 'temp_step' );

$usage  = ys_status_rest( 'GET', '/ys-fct-status/v1/usage' );
$before = $usage['data']['usage']['order']['temp_step'];

ys_assert( 'T11 temp_step is in use before', 2, $before );

$migrate = ys_status_rest(
	'POST',
	'/ys-fct-status/v1/migrate',
	array( 'axis' => 'order', 'from' => 'temp_step', 'to' => 'processing' )
);

ys_assert( 'T11 migrate accepted', 200, $migrate['status'] );
ys_assert( 'T11 moved every order', $before, $migrate['data']['moved'] );
ys_assert( 'T11 usage is now zero', 0, $migrate['data']['usage']['order']['temp_step'] );
ys_assert( 'T11 the sample order moved', 'processing', ys_status_order_row( $t11a )['status'] );
ys_assert( 'T11 activity explains it', true, ys_has_activity( $t11a, 'migrated' ) );
ys_assert( 'T11 orders on other statuses were not touched', 'sourcing', ys_status_order_row( $t4 )['status'] );
$report['T11'] = array( 'orders' => array( $t11a, $t11b ), 'before' => $before, 'moved' => $migrate['data']['moved'] );

// ── T13 ──────────────────────────────────────────────────────────────────────

ys_section( 'T13 — export, wipe, import' );

$export = ys_status_rest( 'GET', '/ys-fct-status/v1/export' );
ys_assert( 'T13 export accepted', 200, $export['status'] );

$snapshot = $export['data'];

ys_status_rest( 'POST', '/ys-fct-status/v1/settings', array( 'settings' => array( 'order' => array(), 'shipping' => array(), 'overrides' => array() ) ) );
StatusRegistry::flushCache();

ys_assert( 'T13 everything is gone', array(), Settings::all()['order'] );
ys_assert( 'T13 core statuses still work', true, isset( \FluentCart\App\Helpers\Status::getOrderStatuses()['processing'] ) );

$import = ys_status_rest( 'POST', '/ys-fct-status/v1/import', array( 'payload' => $snapshot ) );
StatusRegistry::flushCache();

ys_assert( 'T13 import accepted', 200, $import['status'] );
ys_assert( 'T13 settings round-tripped exactly', $snapshot['settings'], Settings::all() );
$report['T13'] = array( 'exported' => count( $snapshot['settings']['order'] ), 'imported' => count( Settings::all()['order'] ) );

// ── T14 ──────────────────────────────────────────────────────────────────────

ys_section( 'T14 — a paid order in a custom status still counts as revenue' );

$t14 = ys_status_make_order();
ys_set_order_status( $t14, 'sourcing' );
ys_mark_paid( $t14 );
$row = ys_status_order_row( $t14 );

ys_assert( 'T14 the order is on a custom status', 'sourcing', $row['status'] );
ys_assert( 'T14 and is paid', 'paid', $row['payment_status'] );

global $wpdb;

$countedByPaymentStatus = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE id = %d AND payment_status IN ('paid','refunded','partially_paid','partially_refunded')",
		$t14
	)
);

$countedByOrderStatus = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE id = %d AND status IN ('completed','processing')",
		$t14
	)
);

ys_assert( 'T14 revenue reports (payment_status) include it', 1, $countedByPaymentStatus );
ys_assert( 'T14 order-status reports (hardcoded) exclude it', 0, $countedByOrderStatus );
$report['T14'] = array( 'order' => $t14, 'by_payment_status' => $countedByPaymentStatus, 'by_order_status' => $countedByOrderStatus );

// ─────────────────────────────────────────────────────────────────────────────

echo PHP_EOL . '=== order ids for the report ===' . PHP_EOL;
echo wp_json_encode(
	array(
		'T1'  => $t1,
		'T4'  => $t4,
		'T4d' => $t4d,
		'T4b' => $t4b,
		'T4c' => $t4c,
		'T5'  => $t5,
		'T6'  => $t6,
		'T7'  => $t7,
		'T11' => array( $t11a, $t11b ),
		'T14' => $t14,
		'T15' => $t15,
	)
) . PHP_EOL;

echo PHP_EOL;

if ( empty( $GLOBALS['ys_status_failed'] ) ) {
	echo sprintf( 'PASS — %d assertions.', $GLOBALS['ys_status_pass'] ) . PHP_EOL;
	exit( 0 );
}

echo sprintf( 'FAIL — %d passed, %d failed:', $GLOBALS['ys_status_pass'], count( $GLOBALS['ys_status_failed'] ) ) . PHP_EOL;

foreach ( $GLOBALS['ys_status_failed'] as $failure ) {
	echo '  - ' . $failure . PHP_EOL;
}

exit( 1 );
