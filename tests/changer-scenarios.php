<?php
/**
 * The v0.3 walkthrough: C1–C7 (the order-page status control) and S1–S6 (the
 * fulfilment workflow on the shipping axis).
 *
 *   wp eval-file tests/changer-scenarios.php
 *
 * Same rules as the two walkthroughs before it: every status change goes
 * through a real REST request — FluentCart's own routes, or this plugin's, both
 * dispatched with `rest_do_request()` — so `rest_pre_dispatch`, the route's
 * permission callback, `OrderResource::updateStatuses()` and
 * `StatusHelper::syncOrderStatuses()` all run exactly as they do for the admin
 * SPA, and every assertion reads the database back afterwards rather than
 * trusting a model that is still in memory.
 *
 * It rewrites `ys_fct_status_settings` with both templates and leaves them in
 * place. Orders keep whatever slug they are on; nothing outside this plugin's
 * own option and the `STATUS-` fixture orders is touched.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

use YangSheep\FluentCart\OrderStatuses\Admin\SavedViews;
use YangSheep\FluentCart\OrderStatuses\History\HistoryRepository;
use YangSheep\FluentCart\OrderStatuses\Pipeline\Changer;
use YangSheep\FluentCart\OrderStatuses\Pipeline\Template;
use YangSheep\FluentCart\OrderStatuses\Reports\ReportService;
use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\OrderContext;
use YangSheep\FluentCart\OrderStatuses\Support\OrderRepository;
use YangSheep\FluentCart\OrderStatuses\Support\Schema;

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
function ys_chg_assert( $label, $expected, $actual ) {
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
function ys_chg_section( $name ) {
	echo PHP_EOL . '# ' . $name . PHP_EOL;
}

/**
 * The route the order page's control posts to.
 *
 * @param int    $orderId Order id.
 * @param string $axis    'order' or 'shipping'.
 * @param string $slug    Target slug.
 * @return array{status:int,data:mixed}
 */
function ys_chg_change( $orderId, $axis, $slug ) {
	OrderRepository::flush();

	return ys_status_rest(
		'POST',
		'/ys-fct-status/v1/orders/' . $orderId . '/change',
		array(
			'axis'   => $axis,
			'status' => $slug,
		)
	);
}

/**
 * FluentCart's own shipping dialog, as the admin SPA drives it.
 *
 * @param int    $orderId Order id.
 * @param string $slug    Shipping slug.
 * @return array{status:int,data:mixed}
 */
function ys_chg_core_shipping( $orderId, $slug ) {
	OrderRepository::flush();

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
 * @param int    $orderId Order id.
 * @param string $slug    Order status slug.
 * @return array{status:int,data:mixed}
 */
function ys_chg_core_order( $orderId, $slug ) {
	OrderRepository::flush();

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
 * @param int $orderId Order id.
 * @return array{status:int,data:mixed}
 */
function ys_chg_mark_paid( $orderId ) {
	return ys_status_rest(
		'POST',
		'/fluent-cart/v2/orders/' . $orderId . '/mark-as-paid',
		array(
			'payment_method' => 'cod',
			'mark_paid_note' => 'STATUS- changer: marked paid',
		)
	);
}

/**
 * @param array $response `ys_status_rest()` result.
 * @return string
 */
function ys_chg_message( array $response ) {
	if ( is_wp_error( $response['data'] ) ) {
		return (string) $response['data']->get_error_message();
	}

	return (string) ( isset( $response['data']['message'] ) ? $response['data']['message'] : '' );
}

/**
 * @param int    $orderId Order id.
 * @param string $axis    Axis.
 * @return string[] Target slugs the control would offer.
 */
function ys_chg_targets( $orderId, $axis ) {
	OrderRepository::flush();

	$slugs = array();

	foreach ( Changer::targets( $orderId, $axis ) as $target ) {
		$slugs[] = $target['slug'];
	}

	return $slugs;
}

/**
 * @param int    $orderId Order id.
 * @param string $axis    Axis.
 * @return string Next-step slug, or ''.
 */
function ys_chg_next( $orderId, $axis ) {
	OrderRepository::flush();

	$next = Changer::nextStep( $orderId, $axis );

	return is_array( $next ) ? $next['slug'] : '';
}

/**
 * @param int    $orderId Order id.
 * @param string $needle  Substring to look for.
 * @return bool
 */
function ys_chg_has_activity( $orderId, $needle ) {
	foreach ( ys_status_activity( $orderId, 16 ) as $row ) {
		if ( false !== strpos( $row['title'] . ' ' . $row['content'], $needle ) ) {
			return true;
		}
	}

	return false;
}

/**
 * @param bool $yes Whether strict mode is on.
 * @return void
 */
function ys_chg_set_strict( $yes ) {
	$settings                    = Settings::all();
	$settings['pipeline_strict'] = $yes ? 'yes' : 'no';

	Settings::save( $settings );
	StatusRegistry::flushCache();
}

/**
 * @param bool $yes Whether the post-payment restore is on.
 * @return void
 */
function ys_chg_set_restore( $yes ) {
	$settings                       = Settings::all();
	$settings['restore_on_payment'] = $yes ? 'yes' : 'no';

	Settings::save( $settings );
	StatusRegistry::flushCache();
}

/**
 * @return int Fulfilled quantity across the order's physical items.
 */
function ys_chg_fulfilled( $orderId ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT SUM(fulfilled_quantity) FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d", $orderId )
	);
}

// ─────────────────────────────────────────────────────────────────────────────

$fired = array();

foreach ( array( 'in_production', 'ship_scheduled', 'shipped' ) as $slug ) {
	add_action(
		'fluent_cart/shipping_status_changed_to_' . $slug,
		function ( $data ) use ( $slug, &$fired ) {
			$fired[] = 'ship:' . $slug . '#' . ( isset( $data['order']->id ) ? $data['order']->id : '?' );
		}
	);
}

$ids = array();

// ── C1 ───────────────────────────────────────────────────────────────────────

ys_chg_section( 'C1 — the control changes a PAID order\'s status, which FluentCart cannot' );

$merged = Template::mergeInto( Settings::defaults() );
Settings::save( $merged['settings'] );
StatusRegistry::flushCache();

$c1        = ys_status_make_order();
$ids['C1'] = $c1;

ys_chg_mark_paid( $c1 );

ys_chg_assert( 'C1 paying puts the order on step 1', 'processing', ys_status_order_row( $c1 )['status'] );
ys_chg_assert( 'C1 the control offers the first workflow step', true, in_array( 'in_production', ys_chg_targets( $c1, 'order' ), true ) );
ys_chg_assert( 'C1 and never offers the status the order is already on', false, in_array( 'processing', ys_chg_targets( $c1, 'order' ), true ) );

$res = ys_chg_change( $c1, 'order', 'in_production' );
$row = ys_status_order_row( $c1 );

ys_chg_assert( 'C1 the change route accepted it', 200, $res['status'] );
ys_chg_assert( 'C1 the database says in_production', 'in_production', $row['status'] );
ys_chg_assert( 'C1 the response carries the fresh state', 'in_production', $res['data']['axes']['order']['current'] );
ys_chg_assert( 'C1 and the label the operator will see', 'In production', $res['data']['axes']['order']['label'] );
ys_chg_assert( 'C1 FluentCart wrote its own activity line', true, ys_chg_has_activity( $c1, 'Order status has been updated from processing to in_production' ) );

$trail = array();

foreach ( HistoryRepository::forOrder( $c1, 'order' ) as $entry ) {
	$trail[] = $entry['old_status'] . '>' . $entry['new_status'];
}

ys_chg_assert( 'C1 the history table recorded the move', array( 'on-hold>processing', 'processing>in_production' ), $trail );

// ── C2 ───────────────────────────────────────────────────────────────────────

ys_chg_section( 'C2 — a refused move comes back as a 422 the control can print' );

$c2        = ys_status_make_order();
$ids['C2'] = $c2;

ys_chg_assert( 'C2 an unpaid order is not offered a paid-only step', false, in_array( 'in_production', ys_chg_targets( $c2, 'order' ), true ) );

$res = ys_chg_change( $c2, 'order', 'in_production' );

ys_chg_assert( 'C2 posting it anyway is refused', 422, $res['status'] );
ys_chg_assert( 'C2 with the payment requirement\'s own words', true, false !== strpos( ys_chg_message( $res ), 'has not been paid yet' ) );
ys_chg_assert( 'C2 and the order did not move', 'on-hold', ys_status_order_row( $c2 )['status'] );

$res = ys_chg_change( $c2, 'order', 'on-hold' );

ys_chg_assert( 'C2 a move to the status it is already on is refused too', 422, $res['status'] );

// ── C3 ───────────────────────────────────────────────────────────────────────

ys_chg_section( 'C3 — strict workflow narrows the control and the write together' );

$c3        = ys_status_make_order();
$ids['C3'] = $c3;

ys_chg_mark_paid( $c3 );
ys_chg_change( $c3, 'order', 'in_production' );

ys_chg_set_strict( true );

ys_chg_assert( 'C3 the control drops the step that would be skipped', false, in_array( 'shipped_done', ys_chg_targets( $c3, 'order' ), true ) );
ys_chg_assert( 'C3 and keeps the adjacent one', true, in_array( 'ship_scheduled', ys_chg_targets( $c3, 'order' ), true ) );

$res = ys_chg_change( $c3, 'order', 'shipped_done' );

ys_chg_assert( 'C3 posting the skip is refused', 422, $res['status'] );
ys_chg_assert( 'C3 with the message naming the missed step', true, false !== strpos( ys_chg_message( $res ), 'one step at a time' ) );
ys_chg_assert( 'C3 the order did not move', 'in_production', ys_status_order_row( $c3 )['status'] );

ys_chg_set_strict( false );

ys_chg_assert( 'C3 with strict off the step is offered again', true, in_array( 'shipped_done', ys_chg_targets( $c3, 'order' ), true ) );

// ── C4 ───────────────────────────────────────────────────────────────────────

ys_chg_section( 'C4 — the one-click next step' );

ys_chg_assert( 'C4 the next step after in_production is ship_scheduled', 'ship_scheduled', ys_chg_next( $c3, 'order' ) );

$res = ys_chg_change( $c3, 'order', ys_chg_next( $c3, 'order' ) );

ys_chg_assert( 'C4 it is accepted', 200, $res['status'] );
ys_chg_assert( 'C4 and written', 'ship_scheduled', ys_status_order_row( $c3 )['status'] );
ys_chg_assert( 'C4 the next one is now the last step', 'shipped_done', ys_chg_next( $c3, 'order' ) );

$res = ys_chg_change( $c3, 'order', 'shipped_done' );

ys_chg_assert( 'C4 the last step is accepted', 200, $res['status'] );
ys_chg_assert( 'C4 there is no next step after the last one', '', ys_chg_next( $c3, 'order' ) );
ys_chg_assert( 'C4 the linked shipping status still followed', 'shipped', ys_status_order_row( $c3 )['shipping_status'] );

$c4        = ys_status_make_order( array( 'status' => 'completed' ) );
$ids['C4'] = $c4;

ys_chg_assert( 'C4 an order outside the workflow has no next step', '', ys_chg_next( $c4, 'order' ) );

// ── C5 ───────────────────────────────────────────────────────────────────────

ys_chg_section( 'C5 — a canceled order: the order axis closes, the shipping one does not' );

$c5        = ys_status_make_order();
$ids['C5'] = $c5;

ys_chg_mark_paid( $c5 );
ys_chg_core_order( $c5, 'canceled' );

ys_chg_assert( 'C5 the order is canceled', 'canceled', ys_status_order_row( $c5 )['status'] );
ys_chg_assert( 'C5 the control offers no order-status move at all', array(), ys_chg_targets( $c5, 'order' ) );

$res = ys_chg_change( $c5, 'order', 'processing' );

ys_chg_assert( 'C5 posting one is refused', 422, $res['status'] );
ys_chg_assert( 'C5 with core\'s own rule spelled out', true, false !== strpos( ys_chg_message( $res ), 'canceled' ) );

$res = ys_chg_change( $c5, 'shipping', 'shipped' );

ys_chg_assert( 'C5 the shipping axis is still open, as it is in core', 200, $res['status'] );
ys_chg_assert( 'C5 and the write landed', 'shipped', ys_status_order_row( $c5 )['shipping_status'] );

// ── C6 ───────────────────────────────────────────────────────────────────────

ys_chg_section( 'C6 — a digital order has no shipping axis to change' );

$c6        = ys_status_make_order( array( 'fulfillment_type' => 'digital' ) );
$ids['C6'] = $c6;

$state = Changer::state( $c6 );

ys_chg_assert( 'C6 the shipping axis is reported as unavailable', false, $state['axes']['shipping']['available'] );
ys_chg_assert( 'C6 and locked with a reason', true, false !== strpos( (string) $state['axes']['shipping']['locked'], 'nothing to ship' ) );
ys_chg_assert( 'C6 the control offers no shipping move', array(), ys_chg_targets( $c6, 'shipping' ) );

$res = ys_chg_change( $c6, 'shipping', 'shipped' );

ys_chg_assert( 'C6 posting one is refused', 422, $res['status'] );
ys_chg_assert( 'C6 the shipping column is still empty', '', ys_status_order_row( $c6 )['shipping_status'] );

// ── C7 ───────────────────────────────────────────────────────────────────────

ys_chg_section( 'C7 — FluentCart\'s "Sync Order Statuses" on a kept custom status' );

$c7        = ys_status_make_order();
$ids['C7'] = $c7;

ys_chg_mark_paid( $c7 );
ys_chg_change( $c7, 'order', 'in_production' );

ys_chg_assert( 'C7 the order is on a kept custom status', 'in_production', ys_status_order_row( $c7 )['status'] );

$sync = ys_status_rest( 'PUT', '/fluent-cart/v2/orders/' . $c7 . '/sync-statuses' );

ys_chg_assert( 'C7 the sync action succeeds', 200, $sync['status'] );
ys_chg_assert( 'C7 the custom status survived it', 'in_production', ys_status_order_row( $c7 )['status'] );
ys_chg_assert( 'C7 the order core hands back says so too', 'in_production', (string) ( isset( $sync['data']['status'] ) ? $sync['data']['status'] : '' ) );
ys_chg_assert( 'C7 the restore left a line in the activity', true, ys_chg_has_activity( $c7, 'Custom order status kept' ) );

// The proof that the sync really does re-trigger core's overwrite, rather than
// the restore never being needed: switch the restore off and run it again.
$c7b        = ys_status_make_order();
$ids['C7b'] = $c7b;

ys_chg_mark_paid( $c7b );
ys_chg_change( $c7b, 'order', 'in_production' );
ys_chg_set_restore( false );

ys_status_rest( 'PUT', '/fluent-cart/v2/orders/' . $c7b . '/sync-statuses' );

ys_chg_assert( 'C7 with the restore off, the sync DOES overwrite the custom status', 'processing', ys_status_order_row( $c7b )['status'] );

ys_chg_set_restore( true );

// ── S1 ───────────────────────────────────────────────────────────────────────

ys_chg_section( 'S1 — the fulfilment workflow template (shipping axis)' );

// Applied to a clean configuration, because that is the recommended path: a
// shop that wants a post-payment workflow presses this button and nothing else.
// The order template is merged back in afterwards so the scenarios below still
// have both axes to compare.
$merged = Template::mergeShippingInto( Settings::defaults() );
Settings::save( $merged['settings'] );
StatusRegistry::flushCache();

$settings = Settings::all();
$slugs    = array();

foreach ( $settings['shipping'] as $definition ) {
	$slugs[] = $definition['slug'];
}

ys_chg_assert( 'S1 two custom shipping statuses exist', array( 'in_production', 'ship_scheduled' ), $slugs );
ys_chg_assert( 'S1 unshipped is relabelled', 'Awaiting production', $settings['overrides']['shipping']['unshipped']['label'] );
ys_chg_assert( 'S1 the paid payment status is relabelled', 'Paid', $settings['overrides']['payment']['paid']['label'] );

ys_chg_assert(
	'S1 the fulfilment pipeline runs unshipped → customs → shipped',
	array( 'unshipped', 'in_production', 'ship_scheduled', 'shipped' ),
	Settings::pipelineFor( 'shipping', $settings )
);

ys_chg_assert(
	'S1 FluentCart\'s own shipping dialog offers them in that order, before shipped',
	array( 'unshipped', 'in_production', 'ship_scheduled', 'shipped', 'delivered', 'unshippable' ),
	array_keys( \FluentCart\App\Helpers\Status::getEditableShippingStatuses() )
);

ys_chg_assert( 'S1 applying it twice adds nothing', array(), Template::mergeShippingInto( Settings::all() )['added'] );
ys_chg_assert( 'S1 it adds no order statuses at all', array(), array_column( $settings['order'], 'slug' ) );

// Both templates together, which is what the rest of this walkthrough needs.
// The order template does not re-label `unshipped`: whoever got there first
// keeps the word, and on this axis "Awaiting production" is the one that
// describes the workflow the shop is actually running.
$both = Template::mergeInto( Settings::all() );
Settings::save( $both['settings'] );
StatusRegistry::flushCache();

$settings = Settings::all();

ys_chg_assert( 'S1 both workflows can coexist', array( 'in_production', 'ship_scheduled', 'shipped_done' ), array_column( $settings['order'], 'slug' ) );
ys_chg_assert( 'S1 and the shipping label set first is kept', 'Awaiting production', $settings['overrides']['shipping']['unshipped']['label'] );
ys_chg_assert( 'S1 the order workflow still links its last step to shipped', 'shipped', $settings['order'][2]['linked_shipping_status'] );
ys_chg_assert(
	'S1 the shared slug has its own definition on each axis',
	array( 'paid_only', 'any' ),
	array(
		$settings['order'][0]['payment_requirement'],
		isset( $settings['shipping'][0]['payment_requirement'] ) ? $settings['shipping'][0]['payment_requirement'] : 'any',
	)
);

// ── S2 ───────────────────────────────────────────────────────────────────────

ys_chg_section( 'S2 — driven end to end through FluentCart\'s own shipping route' );

$s2        = ys_status_make_order();
$ids['S2'] = $s2;

ys_chg_mark_paid( $s2 );

ys_chg_assert( 'S2 the order is paid', 'paid', ys_status_order_row( $s2 )['payment_status'] );

foreach ( array( 'in_production', 'ship_scheduled' ) as $step ) {
	$res = ys_chg_core_shipping( $s2, $step );
	$row = ys_status_order_row( $s2 );

	ys_chg_assert( 'S2 ' . $step . ' accepted by core', 200, $res['status'] );
	ys_chg_assert( 'S2 ' . $step . ' written to shipping_status', $step, $row['shipping_status'] );
	ys_chg_assert( 'S2 ' . $step . ' left the order status alone', 'processing', $row['status'] );
	ys_chg_assert( 'S2 ' . $step . ' fulfils nothing yet', 0, ys_chg_fulfilled( $s2 ) );
}

$res = ys_chg_core_shipping( $s2, 'shipped' );
$row = ys_status_order_row( $s2 );

ys_chg_assert( 'S2 shipped accepted', 200, $res['status'] );
ys_chg_assert( 'S2 the shipping status is shipped', 'shipped', $row['shipping_status'] );
ys_chg_assert( 'S2 core marked the items fulfilled', 1, ys_chg_fulfilled( $s2 ) );
ys_chg_assert( 'S2 shipping_status_changed_to_in_production fired for a custom slug', true, in_array( 'ship:in_production#' . $s2, $fired, true ) );
ys_chg_assert( 'S2 and shipping_status_changed_to_shipped for the built-in one', true, in_array( 'ship:shipped#' . $s2, $fired, true ) );

$trail = array();

foreach ( HistoryRepository::forOrder( $s2, 'shipping' ) as $entry ) {
	$trail[] = $entry['old_status'] . '>' . $entry['new_status'] . '(' . $entry['source'] . ')';
}

ys_chg_assert(
	'S2 the history table recorded every shipping-axis step',
	array( 'unshipped>in_production(hook)', 'in_production>ship_scheduled(hook)', 'ship_scheduled>shipped(hook)' ),
	$trail
);

// ── S3 ───────────────────────────────────────────────────────────────────────

ys_chg_section( 'S3 — the same workflow from the order page\'s control' );

$s3        = ys_status_make_order();
$ids['S3'] = $s3;

ys_chg_mark_paid( $s3 );

ys_chg_assert( 'S3 the shipping axis starts at step 1', 0, Changer::state( $s3 )['axes']['shipping']['step'] );
ys_chg_assert( 'S3 the next step is the first custom one', 'in_production', ys_chg_next( $s3, 'shipping' ) );

$res = ys_chg_change( $s3, 'shipping', 'in_production' );

ys_chg_assert( 'S3 the change route accepted it', 200, $res['status'] );
ys_chg_assert( 'S3 the database agrees', 'in_production', ys_status_order_row( $s3 )['shipping_status'] );
ys_chg_assert( 'S3 the response reports the new step', 1, $res['data']['axes']['shipping']['step'] );
ys_chg_assert( 'S3 and the next one after it', 'ship_scheduled', ys_chg_next( $s3, 'shipping' ) );
ys_chg_assert( 'S3 strict order workflow does not constrain the shipping axis', null, Changer::rejectionFor( $s3, 'shipping', 'shipped' ) );

// ── S4 ───────────────────────────────────────────────────────────────────────

ys_chg_section( 'S4 — the report, on the shipping axis' );

global $wpdb;

$funnel = ReportService::funnel( 'shipping' );
$byslug = array();

foreach ( $funnel as $step ) {
	$byslug[ $step['slug'] ] = $step;
}

ys_chg_assert(
	'S4 the funnel follows the fulfilment pipeline',
	array( 'unshipped', 'in_production', 'ship_scheduled', 'shipped' ),
	array_column( $funnel, 'slug' )
);

foreach ( array( 'in_production', 'ship_scheduled', 'shipped' ) as $slug ) {
	$sql = $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE shipping_status = %s", $slug )
	);

	ys_chg_assert(
		'S4 ' . $slug . ' matches SELECT COUNT(*) … WHERE shipping_status = ' . $slug,
		(int) $sql,
		(int) $byslug[ $slug ]['total_count']
	);
}

$paidSql = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE shipping_status = 'in_production' AND payment_status IN ('paid','partially_paid','partially_refunded')"
);

ys_chg_assert( 'S4 the paid split matches the same query with the payment clause', $paidSql, (int) $byslug['in_production']['paid_count'] );

$dwell = array();

foreach ( ReportService::dwell( 'shipping' ) as $row ) {
	$dwell[ $row['slug'] ] = $row;
}

ys_chg_assert( 'S4 the dwell table knows the custom shipping steps', true, isset( $dwell['in_production'] ) );
ys_chg_assert( 'S4 and has timed the completed stays on them', true, $dwell['in_production']['samples'] > 0 );

// Backdate the row that put S3 where it is, so it reads as stuck.
$wpdb->query(
	$wpdb->prepare(
		"UPDATE {$wpdb->prefix}ys_fct_status_history SET changed_at = %s WHERE order_id = %d AND axis = 'shipping' AND new_status = 'in_production'",
		gmdate( 'Y-m-d H:i:s', time() - ( 6 * DAY_IN_SECONDS ) ),
		$s3
	)
);

$stalled = ReportService::stalled( 0, 'shipping' );
$stuck   = array_column( $stalled['orders'], 'order_id' );

ys_chg_assert( 'S4 the stuck list is reported for the shipping axis', 'shipping', $stalled['axis'] );
ys_chg_assert( 'S4 the backdated order is in it', true, in_array( $s3, $stuck, true ) );
ys_chg_assert( 'S4 an order-axis stuck list does not contain it', false, in_array( $s3, array_column( ReportService::stalled( 0, 'order' )['orders'], 'order_id' ), true ) );

$overview = ReportService::overview( '', '', 'shipping' );

ys_chg_assert( 'S4 the overview reports which axis it answered', 'shipping', $overview['axis'] );
ys_chg_assert( 'S4 and still returns both distribution tables', true, ! empty( $overview['order_statuses'] ) && ! empty( $overview['shipping_statuses'] ) );

// ── S5 ───────────────────────────────────────────────────────────────────────

ys_chg_section( 'S5 — saved views on shipping_status' );

$views = array();

// Other add-ons contribute to this filter too, and not every table entry they
// add carries saved views.
foreach ( apply_filters( 'fluent_cart/admin_table_saved_views', array(), array( 'filterOptions' => array( 'x' ) ) ) as $table => $config ) {
	if ( ! is_array( $config ) || empty( $config['saved_views'] ) ) {
		continue;
	}

	foreach ( $config['saved_views'] as $view ) {
		$views[ $view['slug'] ] = $view;
	}
}

ys_chg_assert( 'S5 there is a view per custom shipping status', true, isset( $views['ys_shipping_in_production'], $views['ys_shipping_ship_scheduled'] ) );
ys_chg_assert( 'S5 the order-axis views are still there', true, isset( $views['ys_status_in_production'] ) );
ys_chg_assert( 'S5 the two axes do not collide on one slug', true, $views['ys_shipping_in_production']['slug'] !== $views['ys_status_in_production']['slug'] );
ys_chg_assert( 'S5 a shipping view carries no search expression', '', $views['ys_shipping_in_production']['query_params']['search'] );

$shipCount = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE shipping_status = 'in_production'"
);

ys_chg_assert( 'S5 the view name carries the live count', true, false !== strpos( $views['ys_shipping_in_production']['name'], '(' . $shipCount . ')' ) );
ys_chg_assert( 'S5 and says which axis it filters, so the two cannot be confused', true, false !== strpos( $views['ys_shipping_in_production']['name'], '· shipping' ) );

// `GET /orders` answers with a LengthAwarePaginator object, not an array — the
// REST layer serialises it on the way out, but `rest_do_request()` hands back
// what the controller returned.
$list      = ys_status_rest( 'GET', '/fluent-cart/v2/orders?active_view=ys_shipping_in_production&per_page=50' );
$paginator = isset( $list['data']['orders'] ) ? $list['data']['orders'] : null;
$page      = is_object( $paginator ) && method_exists( $paginator, 'toArray' ) ? $paginator->toArray() : array();
$rows      = isset( $page['data'] ) ? $page['data'] : array();

$offAxis = array();

foreach ( $rows as $listed ) {
	$shipping = is_array( $listed ) ? $listed['shipping_status'] : $listed->shipping_status;

	if ( 'in_production' !== $shipping ) {
		$offAxis[] = is_array( $listed ) ? $listed['id'] : $listed->id;
	}
}

ys_chg_assert( 'S5 the list request succeeds', 200, $list['status'] );
ys_chg_assert( 'S5 it returns exactly the orders on that shipping status', $shipCount, count( $rows ) );
ys_chg_assert( 'S5 and nothing that is not on it', array(), $offAxis );

ys_chg_assert( 'S5 an unknown view slug selects nothing on its own', '', SavedViews::shippingSlugFor( 'ys_shipping_not_a_status' ) );

// ── S6 ───────────────────────────────────────────────────────────────────────

ys_chg_section( 'S6 — the control on FluentCart\'s own order page' );

$widgets = apply_filters( 'fluent_cart/widgets/single_order_page', array(), array( 'order_id' => $s3 ) );
$titles  = array_column( $widgets, 'title' );
$changer = '';

foreach ( $widgets as $widget ) {
	if ( 'Order workflow' === $widget['title'] ) {
		$changer = $widget['content'];
	}
}

ys_chg_assert( 'S6 the order page gets both panels', array( 'Order workflow', 'Status history' ), $titles );
ys_chg_assert( 'S6 the control is addressed to this order', true, false !== strpos( $changer, 'data-ys-order="' . $s3 . '"' ) );
ys_chg_assert( 'S6 it carries a select per axis', true, false !== strpos( $changer, 'data-ys-changer-select="order"' ) && false !== strpos( $changer, 'data-ys-changer-select="shipping"' ) );
ys_chg_assert( 'S6 and a next-step button', true, false !== strpos( $changer, 'data-ys-changer-next="shipping"' ) );
ys_chg_assert( 'S6 there is no inline script in it (innerHTML would not run one)', false, false !== strpos( $changer, '<script' ) );

// Two layers, because the widget's content is injected with `innerHTML` and a
// status label is operator input. `sanitize_text_field()` strips the tag on the
// way into the option; `esc_html()` / `esc_attr()` deal with everything that
// survives it, which is every character a real label legitimately contains.
$hostile = Settings::all();

$hostile['shipping'][] = array(
	'slug'    => 'xss_probe',
	'label'   => '<img src=x onerror=alert(1)> "quoted" & bare',
	'color'   => '#ff0000',
	'enabled' => true,
);

Settings::save( $hostile );
StatusRegistry::flushCache();

$stored = '';

foreach ( Settings::all()['shipping'] as $definition ) {
	if ( 'xss_probe' === $definition['slug'] ) {
		$stored = $definition['label'];
	}
}

ys_chg_assert( 'S6 the sanitiser strips the tag before it is ever stored', '"quoted" & bare', $stored );

$widgets = apply_filters( 'fluent_cart/widgets/single_order_page', array(), array( 'order_id' => $s3 ) );
$changer = '';

foreach ( $widgets as $widget ) {
	if ( 'Order workflow' === $widget['title'] ) {
		$changer = $widget['content'];
	}
}

ys_chg_assert( 'S6 no markup from a label reaches the widget', false, false !== strpos( $changer, '<img' ) );
ys_chg_assert( 'S6 the quotes that survive sanitising are escaped', true, false !== strpos( $changer, '&quot;quoted&quot; &amp; bare' ) );
ys_chg_assert( 'S6 and the raw quotes are not in the markup', false, false !== strpos( $changer, '>"quoted" & bare<' ) );

$settings             = Settings::all();
$settings['shipping'] = array_values(
	array_filter(
		$settings['shipping'],
		static function ( $definition ) {
			return 'xss_probe' !== $definition['slug'];
		}
	)
);

Settings::save( $settings );
StatusRegistry::flushCache();

// ─────────────────────────────────────────────────────────────────────────────

echo PHP_EOL;

ys_status_out( '# Order ids', wp_json_encode( $ids, JSON_UNESCAPED_UNICODE ) );
ys_status_out( 'history rows', HistoryRepository::count() );
ys_status_out( 'history table', Schema::table() );

echo PHP_EOL;

if ( empty( $GLOBALS['ys_status_failed'] ) ) {
	echo sprintf( 'PASS — %d assertions.', $GLOBALS['ys_status_pass'] ) . PHP_EOL;
	return;
}

echo sprintf( 'FAIL — %d passed, %d failed:', $GLOBALS['ys_status_pass'], count( $GLOBALS['ys_status_failed'] ) ) . PHP_EOL;

foreach ( $GLOBALS['ys_status_failed'] as $failure ) {
	echo '  - ' . $failure . PHP_EOL;
}
