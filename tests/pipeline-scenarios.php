<?php
/**
 * The v0.2 walkthrough: P1–P5 (pipeline) and R1–R7 (reports).
 *
 *   wp eval-file tests/pipeline-scenarios.php
 *
 * Same rules as `status-scenarios.php`: every status change goes through
 * FluentCart's own REST routes, so `rest_pre_dispatch`, the route permission
 * callback, `OrderResource::updateStatuses()` and
 * `StatusHelper::syncOrderStatuses()` all run exactly as they do for the admin
 * SPA — and every assertion reads the database back afterwards rather than
 * trusting a model that is still in memory.
 *
 * It rewrites `ys_fct_status_settings` with the pipeline template. Orders keep
 * whatever slug they are on; nothing outside this plugin's own option and the
 * `STATUS-` fixture orders is touched.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

use YangSheep\FluentCart\OrderStatuses\History\Backfill;
use YangSheep\FluentCart\OrderStatuses\History\HistoryRepository;
use YangSheep\FluentCart\OrderStatuses\Pipeline\Template;
use YangSheep\FluentCart\OrderStatuses\Reports\DailySummary;
use YangSheep\FluentCart\OrderStatuses\Reports\ReportService;
use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
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
function ys_pipe_assert( $label, $expected, $actual ) {
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
function ys_pipe_section( $name ) {
	echo PHP_EOL . '# ' . $name . PHP_EOL;
}

/**
 * @param int    $orderId Order id.
 * @param string $slug    Order status slug.
 * @return array{status:int,data:mixed}
 */
function ys_pipe_set_status( $orderId, $slug ) {
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
function ys_pipe_mark_paid( $orderId ) {
	return ys_status_rest(
		'POST',
		'/fluent-cart/v2/orders/' . $orderId . '/mark-as-paid',
		array(
			'payment_method' => 'cod',
			'mark_paid_note' => 'STATUS- pipeline: marked paid',
		)
	);
}

/**
 * @param int    $orderId Order id.
 * @param string $needle  Substring to look for.
 * @return bool
 */
function ys_pipe_has_activity( $orderId, $needle ) {
	foreach ( ys_status_activity( $orderId, 16 ) as $row ) {
		if ( false !== strpos( $row['title'] . ' ' . $row['content'], $needle ) ) {
			return true;
		}
	}

	return false;
}

/**
 * @param int $orderId Order id.
 * @return int Total fulfilled quantity across the order's physical items.
 */
function ys_pipe_fulfilled( $orderId ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT SUM(fulfilled_quantity) FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d", $orderId )
	);
}

/**
 * @param string $slug Pipeline slug.
 * @return void
 */
function ys_pipe_set_strict( $yes ) {
	$settings                    = Settings::all();
	$settings['pipeline_strict'] = $yes ? 'yes' : 'no';

	Settings::save( $settings );
	StatusRegistry::flushCache();
}

// ─────────────────────────────────────────────────────────────────────────────

$fired = array();

foreach ( array( 'in_production', 'ship_scheduled', 'shipped_done' ) as $slug ) {
	add_action(
		'fluent_cart/order_status_changed_to_' . $slug,
		function ( $data ) use ( $slug, &$fired ) {
			$fired[] = 'order:' . $slug . '#' . ( isset( $data['order']->id ) ? $data['order']->id : '?' );
		}
	);
}

foreach ( array( 'shipped', 'delivered' ) as $slug ) {
	add_action(
		'fluent_cart/shipping_status_changed_to_' . $slug,
		function ( $data ) use ( $slug, &$fired ) {
			$fired[] = 'ship:' . $slug . '#' . ( isset( $data['order']->id ) ? $data['order']->id : '?' );
		}
	);
}

$ids = array();

// ── P1 ───────────────────────────────────────────────────────────────────────

ys_pipe_section( 'P1 — one-click template' );

$merged = Template::mergeInto( Settings::defaults() );
Settings::save( $merged['settings'] );
StatusRegistry::flushCache();

$settings = Settings::all();
$slugs    = array();

foreach ( $settings['order'] as $definition ) {
	$slugs[] = $definition['slug'];
}

ys_pipe_assert( 'P1 three workflow steps exist', array( 'in_production', 'ship_scheduled', 'shipped_done' ), $slugs );
ys_pipe_assert( 'P1 the pipeline starts at processing', array( 'processing', 'in_production', 'ship_scheduled', 'shipped_done' ), Settings::pipeline( $settings ) );
ys_pipe_assert( 'P1 processing is relabelled', 'Paid', $settings['overrides']['order']['processing']['label'] );
ys_pipe_assert( 'P1 the last step links to shipped', 'shipped', $settings['order'][2]['linked_shipping_status'] );
ys_pipe_assert( 'P1 FluentCart offers the steps in its editable list', true, isset( \FluentCart\App\Helpers\Status::getEditableOrderStatuses()['in_production'] ) );
ys_pipe_assert( 'P1 FluentCart shows the relabelled processing', 'Paid', \FluentCart\App\Helpers\Status::getOrderStatuses()['processing'] );

ys_pipe_assert(
	'P1 the dropdown reads in workflow order',
	array( 'processing', 'in_production', 'ship_scheduled', 'shipped_done' ),
	array_slice( array_keys( \FluentCart\App\Helpers\Status::getEditableOrderStatuses() ), 0, 4 )
);

$p1 = ys_status_make_order();
$ids['P1'] = $p1;

$refused = ys_pipe_set_status( $p1, 'in_production' );

$refusal = is_wp_error( $refused['data'] )
	? $refused['data']->get_error_message()
	: (string) ( isset( $refused['data']['message'] ) ? $refused['data']['message'] : '' );

ys_pipe_assert( 'P1 an unpaid order is refused the first step', 422, $refused['status'] );
ys_pipe_assert( 'P1 and the refusal explains that the order is not paid', true, false !== strpos( $refusal, 'has not been paid yet' ) );
ys_pipe_assert( 'P1 the unpaid order did not move', 'on-hold', ys_status_order_row( $p1 )['status'] );

// ── P2 ───────────────────────────────────────────────────────────────────────

ys_pipe_section( 'P2 — paid → in production → scheduled → shipped' );

$p2 = ys_status_make_order();
$ids['P2'] = $p2;

ys_pipe_mark_paid( $p2 );
$row = ys_status_order_row( $p2 );

ys_pipe_assert( 'P2 paying puts the order on step 1', 'processing', $row['status'] );
ys_pipe_assert( 'P2 the payment status is paid', 'paid', $row['payment_status'] );

foreach ( array( 'in_production', 'ship_scheduled' ) as $step ) {
	$res = ys_pipe_set_status( $p2, $step );
	$row = ys_status_order_row( $p2 );

	ys_pipe_assert( 'P2 ' . $step . ' accepted', 200, $res['status'] );
	ys_pipe_assert( 'P2 ' . $step . ' written to the database', $step, $row['status'] );
	ys_pipe_assert( 'P2 ' . $step . ' left the shipping status alone', 'unshipped', $row['shipping_status'] );
}

$res = ys_pipe_set_status( $p2, 'shipped_done' );
$row = ys_status_order_row( $p2 );

ys_pipe_assert( 'P2 shipped_done accepted', 200, $res['status'] );
ys_pipe_assert( 'P2 the order status is shipped_done', 'shipped_done', $row['status'] );
ys_pipe_assert( 'P2 the linked shipping status was applied', 'shipped', $row['shipping_status'] );
ys_pipe_assert( 'P2 fulfilled_quantity followed core\'s own logic', 1, ys_pipe_fulfilled( $p2 ) );
ys_pipe_assert( 'P2 shipping_status_changed_to_shipped fired', true, in_array( 'ship:shipped#' . $p2, $fired, true ) );
ys_pipe_assert( 'P2 order_status_changed_to_shipped_done fired', true, in_array( 'order:shipped_done#' . $p2, $fired, true ) );
ys_pipe_assert( 'P2 the activity explains the automatic shipping change', true, ys_pipe_has_activity( $p2, 'Shipping status updated automatically' ) );

$history = HistoryRepository::forOrder( $p2 );
$trail   = array();

foreach ( $history as $entry ) {
	$trail[] = $entry['axis'] . ':' . $entry['old_status'] . '>' . $entry['new_status'];
}

ys_pipe_assert(
	'P2 the history table recorded every step, on both axes',
	array(
		'order:on-hold>processing',
		'order:processing>in_production',
		'order:in_production>ship_scheduled',
		'order:ship_scheduled>shipped_done',
		'shipping:unshipped>shipped',
	),
	$trail
);

ys_pipe_assert( 'P2 the linked write is attributed', 'linked', $history[4]['source'] );

// ── P3 ───────────────────────────────────────────────────────────────────────

ys_pipe_section( 'P3 — strict workflow refuses a skipped step' );

$p3 = ys_status_make_order();
$ids['P3'] = $p3;

ys_pipe_mark_paid( $p3 );
ys_pipe_set_status( $p3, 'in_production' );

ys_pipe_set_strict( true );

$skip = ys_pipe_set_status( $p3, 'shipped_done' );
$row  = ys_status_order_row( $p3 );

ys_pipe_assert( 'P3 the skip is refused', 422, $skip['status'] );
ys_pipe_assert( 'P3 the order did not move', 'in_production', $row['status'] );

$message = is_wp_error( $skip['data'] ) ? $skip['data']->get_error_message() : (string) ( isset( $skip['data']['message'] ) ? $skip['data']['message'] : '' );

ys_pipe_assert( 'P3 the message names the step that was missed', true, false !== strpos( $message, 'Shipment scheduled' ) );
// The second enforcement layer: with the order in scope, the skipped step is
// gone from the list `OrderResource::updateStatuses()` validates against, so a
// code path that never reaches the REST veto is refused by core itself.
\YangSheep\FluentCart\OrderStatuses\Support\OrderContext::set( $p3 );

$narrowed = \FluentCart\App\Helpers\Status::getEditableOrderStatuses();

\YangSheep\FluentCart\OrderStatuses\Support\OrderContext::set( 0 );

ys_pipe_assert( 'P3 the skipped step is dropped from core\'s write allow-list', false, isset( $narrowed['shipped_done'] ) );
ys_pipe_assert( 'P3 the adjacent step is still in it', true, isset( $narrowed['ship_scheduled'] ) );

ys_pipe_assert( 'P3 the next step is still allowed', 200, ys_pipe_set_status( $p3, 'ship_scheduled' )['status'] );
ys_pipe_assert( 'P3 one step back is allowed too', 200, ys_pipe_set_status( $p3, 'in_production' )['status'] );
ys_pipe_assert( 'P3 leaving the workflow is always allowed', 200, ys_pipe_set_status( $p3, 'completed' )['status'] );

ys_pipe_set_strict( false );

$p3b = ys_status_make_order();
$ids['P3b'] = $p3b;

ys_pipe_mark_paid( $p3b );
ys_pipe_set_status( $p3b, 'in_production' );

ys_pipe_assert( 'P3 with strict off the same skip is accepted', 200, ys_pipe_set_status( $p3b, 'shipped_done' )['status'] );
ys_pipe_assert( 'P3 and it really skipped', 'shipped_done', ys_status_order_row( $p3b )['status'] );

// ── P4 ───────────────────────────────────────────────────────────────────────

ys_pipe_section( 'P4 — a digital order has no shipping status to set' );

$p4 = ys_status_make_order( array( 'fulfillment_type' => 'digital' ) );
$ids['P4'] = $p4;

ys_pipe_mark_paid( $p4 );
$before = ys_status_order_row( $p4 );

$res = ys_pipe_set_status( $p4, 'shipped_done' );
$row = ys_status_order_row( $p4 );

ys_pipe_assert( 'P4 the digital order starts with no shipping status', '', $before['shipping_status'] );
ys_pipe_assert( 'P4 the order status change is accepted', 200, $res['status'] );
ys_pipe_assert( 'P4 the order status changed', 'shipped_done', $row['status'] );
ys_pipe_assert( 'P4 the shipping status was left empty', '', $row['shipping_status'] );
ys_pipe_assert( 'P4 the activity explains the skip', true, ys_pipe_has_activity( $p4, 'Shipping status not linked' ) );

// ── P5 ───────────────────────────────────────────────────────────────────────

ys_pipe_section( 'P5 — payment does not overwrite a workflow status' );

// The template's steps are paid-only, so an unpaid order cannot be put on one
// in the first place. Relaxing that one condition is what makes "payment lands
// while the order is already on a workflow step" reachable at all — the rest of
// the mechanism (on_payment=keep, the restore, the digital guard) is untouched.
$settings                                 = Settings::all();
$settings['order'][0]['payment_requirement'] = 'any';

Settings::save( $settings );
StatusRegistry::flushCache();

$p5 = ys_status_make_order();
$ids['P5'] = $p5;

ys_pipe_assert( 'P5 an unpaid order can be put on the relaxed step', 200, ys_pipe_set_status( $p5, 'in_production' )['status'] );

ys_pipe_mark_paid( $p5 );
$row = ys_status_order_row( $p5 );

ys_pipe_assert( 'P5 the workflow status survived the payment', 'in_production', $row['status'] );
ys_pipe_assert( 'P5 the payment still landed', 'paid', $row['payment_status'] );
ys_pipe_assert( 'P5 the activity records the restore', true, ys_pipe_has_activity( $p5, 'Custom order status kept' ) );

$p5history = array();

foreach ( HistoryRepository::forOrder( $p5, 'order' ) as $entry ) {
	$p5history[] = $entry['old_status'] . '>' . $entry['new_status'];
}

ys_pipe_assert(
	'P5 the no-op round trip through processing is not recorded',
	array( 'on-hold>in_production' ),
	$p5history
);

$settings                                    = Settings::all();
$settings['order'][0]['payment_requirement'] = 'paid_only';

Settings::save( $settings );
StatusRegistry::flushCache();

// ── R1 ───────────────────────────────────────────────────────────────────────

ys_pipe_section( 'R1 — saved views on the Orders list' );

$tableConfig = apply_filters(
	'fluent_cart/admin_table_saved_views',
	array( 'order_table' => array( 'filters' => array() ) ),
	array( 'filterOptions' => array( 'order_filter_options' => array( 'advance' => array() ) ) )
);

$views = isset( $tableConfig['order_table']['saved_views'] ) ? $tableConfig['order_table']['saved_views'] : array();

ys_pipe_assert( 'R1 one view per workflow step', 3, count( $views ) );
ys_pipe_assert( 'R1 the view slug is namespaced', 'ys_status_in_production', $views[0]['slug'] );
ys_pipe_assert( 'R1 the name carries the live count', true, false !== strpos( $views[0]['name'], '(' ) );

echo '  ->   view names: ' . wp_json_encode( wp_list_pluck( $views, 'name' ), JSON_UNESCAPED_UNICODE ) . PHP_EOL;
echo '  ->   first view: ' . wp_json_encode( $views[0], JSON_UNESCAPED_UNICODE ) . PHP_EOL;

// The real thing: the SPA sends the slug back as `active_view` on the orders
// list request, and `BaseFilter::parseAcceptedView()` resolves it through this
// same filter — with an EMPTY table config, which is why the callback creates
// the `order_table` entry rather than extending it.
$listed = ys_status_rest( 'GET', '/fluent-cart/v2/orders', array() );
$viewed = ys_status_rest( 'GET', '/fluent-cart/v2/orders?active_view=ys_status_shipped_done&per_page=50', array() );

// `orders` comes back as FluentCart's paginator object, not an array.
$page           = $viewed['data']['orders']->toArray();
$viewedStatuses = array();

foreach ( (array) $page['data'] as $order ) {
	$viewedStatuses[ $order['status'] ] = true;
}

ys_pipe_assert( 'R1 the orders list still answers', 200, $listed['status'] );
ys_pipe_assert( 'R1 the saved view answers', 200, $viewed['status'] );
ys_pipe_assert( 'R1 and returns only that status', array( 'shipped_done' ), array_keys( $viewedStatuses ) );

$expected = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}fct_orders WHERE status = 'shipped_done'" );

ys_pipe_assert( 'R1 the count matches the database', $expected, (int) $page['total'] );

echo '  ->   saved view returned ' . (int) $page['total'] . ' order(s), all on shipped_done' . PHP_EOL;

// ── R2 ───────────────────────────────────────────────────────────────────────

ys_pipe_section( 'R2 — distribution, funnel and dwell match the database' );

$overview = ReportService::overview();
$byslug   = array();

foreach ( $overview['order_statuses'] as $row ) {
	$byslug[ $row['slug'] ] = $row;
}

global $wpdb;

$paidList = "'paid','partially_paid','partially_refunded'";

foreach ( array( 'in_production', 'ship_scheduled', 'shipped_done', 'processing', 'completed' ) as $slug ) {
	$sqlPaid = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE status = %s AND payment_status IN ({$paidList})", $slug )
	);

	$sqlUnpaid = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE status = %s AND payment_status NOT IN ({$paidList})", $slug )
	);

	$sqlAmount = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COALESCE(SUM(total_amount),0) FROM {$wpdb->prefix}fct_orders WHERE status = %s AND payment_status IN ({$paidList})", $slug )
	);

	ys_pipe_assert( 'R2 ' . $slug . ' paid count', $sqlPaid, isset( $byslug[ $slug ] ) ? $byslug[ $slug ]['paid_count'] : -1 );
	ys_pipe_assert( 'R2 ' . $slug . ' unpaid count', $sqlUnpaid, isset( $byslug[ $slug ] ) ? $byslug[ $slug ]['unpaid_count'] : -1 );
	ys_pipe_assert( 'R2 ' . $slug . ' paid amount', $sqlAmount, isset( $byslug[ $slug ] ) ? $byslug[ $slug ]['paid_amount'] : -1 );
}

$funnelSlugs = array();

foreach ( $overview['funnel'] as $step ) {
	$funnelSlugs[] = $step['slug'];
}

ys_pipe_assert( 'R2 the funnel follows the pipeline', array( 'processing', 'in_production', 'ship_scheduled', 'shipped_done' ), $funnelSlugs );
ys_pipe_assert( 'R2 the funnel numbers the steps', array( 1, 2, 3, 4 ), wp_list_pluck( $overview['funnel'], 'step' ) );

$dwell = array();

foreach ( $overview['dwell'] as $row ) {
	$dwell[ $row['slug'] ] = $row;
}

ys_pipe_assert( 'R2 dwell time is reported for in_production', true, isset( $dwell['in_production'] ) );
ys_pipe_assert( 'R2 dwell only counts stays that ended', true, $dwell['in_production']['samples'] > 0 );

echo '  ->   in_production: ' . wp_json_encode( $dwell['in_production'], JSON_UNESCAPED_UNICODE ) . PHP_EOL;

// The report's own arithmetic, checked against the same join done by hand.
$manual = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}ys_fct_status_history h
	 WHERE h.axis = 'order' AND h.new_status = 'in_production'
	 AND EXISTS (
	   SELECT 1 FROM {$wpdb->prefix}ys_fct_status_history h2
	   WHERE h2.order_id = h.order_id AND h2.axis = 'order'
	     AND (h2.changed_at > h.changed_at OR (h2.changed_at = h.changed_at AND h2.id > h.id))
	 )"
);

ys_pipe_assert( 'R2 the dwell sample count matches a hand-written join', $manual, $dwell['in_production']['samples'] );

// ── R3 ───────────────────────────────────────────────────────────────────────

ys_pipe_section( 'R3 — the stuck-order list' );

$r3 = ys_status_make_order();
$ids['R3'] = $r3;

ys_pipe_mark_paid( $r3 );
ys_pipe_set_status( $r3, 'in_production' );

$fresh = ReportService::stalled();

ys_pipe_assert( 'R3 a brand new order is not stuck', false, in_array( $r3, wp_list_pluck( $fresh['orders'], 'order_id' ), true ) );
ys_pipe_assert( 'R3 the threshold is the configured one', 3, $fresh['days'] );

// Backdate the row that put it there — the same thing five days of waiting
// would have done, without waiting five days.
$wpdb->query(
	$wpdb->prepare(
		"UPDATE {$wpdb->prefix}ys_fct_status_history SET changed_at = %s WHERE order_id = %d AND new_status = 'in_production'",
		gmdate( 'Y-m-d H:i:s', time() - ( 5 * DAY_IN_SECONDS ) ),
		$r3
	)
);

$late   = ReportService::stalled();
$lateId = array();

foreach ( $late['orders'] as $order ) {
	$lateId[ $order['order_id'] ] = $order;
}

ys_pipe_assert( 'R3 the backdated order is now listed', true, isset( $lateId[ $r3 ] ) );
ys_pipe_assert( 'R3 with the right status', 'in_production', $lateId[ $r3 ]['status'] );
ys_pipe_assert( 'R3 and about five days on it', true, $lateId[ $r3 ]['days'] >= 4.9 && $lateId[ $r3 ]['days'] <= 5.1 );
ys_pipe_assert( 'R3 a completed order is never counted as stuck', false, isset( $lateId[ $ids['P3'] ] ) );

// ── R4 ───────────────────────────────────────────────────────────────────────

ys_pipe_section( 'R4 — the order page widget' );

$widgets = apply_filters( 'fluent_cart/widgets/single_order_page', array(), array( 'order_id' => $p2 ) );
$mine    = null;

foreach ( (array) $widgets as $widget ) {
	if ( isset( $widget['title'] ) && 'Status history' === $widget['title'] ) {
		$mine = $widget;
	}
}

ys_pipe_assert( 'R4 the widget is registered', true, null !== $mine );
ys_pipe_assert( 'R4 it is an html widget', 'html', $mine['type'] );
ys_pipe_assert( 'R4 it lists the last step', true, false !== strpos( $mine['content'], 'Shipped' ) );
ys_pipe_assert( 'R4 it lists the shipping axis too', true, false !== strpos( $mine['content'], 'Shipping status' ) );
ys_pipe_assert( 'R4 it reports how long each stay was', true, false !== strpos( $mine['content'], 'stayed' ) );
ys_pipe_assert( 'R4 nothing unescaped reaches the innerHTML', false, false !== strpos( $mine['content'], '<script' ) );

$route = ys_status_rest( 'GET', '/fluent-cart/v2/widgets?filter=single_order_page&data[order_id]=' . $p2 . '&data[order_uuid]=x', array() );
$found = false;

foreach ( (array) ( isset( $route['data']['widgets'] ) ? $route['data']['widgets'] : array() ) as $widget ) {
	if ( isset( $widget['title'] ) && 'Status history' === $widget['title'] ) {
		$found = true;
	}
}

ys_pipe_assert( 'R4 FluentCart\'s own widgets route serves it', 200, $route['status'] );
ys_pipe_assert( 'R4 and it is in the response', true, $found );

// ── R5 ───────────────────────────────────────────────────────────────────────

ys_pipe_section( 'R5 — backfill from the activity log' );

$before = HistoryRepository::count();
$result = Backfill::run();
$after  = HistoryRepository::count();

ys_pipe_assert( 'R5 at least one historic change was recovered', true, $result['parsed'] > 0 );
ys_pipe_assert( 'R5 across more than one order', true, $result['orders'] > 1 );
ys_pipe_assert( 'R5 the table grew', true, $after > $before );

echo '  ->   backfill: ' . wp_json_encode( $result ) . PHP_EOL;

$second = Backfill::run();

ys_pipe_assert( 'R5 running it twice does not duplicate anything', $after, HistoryRepository::count() );
ys_pipe_assert( 'R5 the second pass removes its own previous rows first', $result['parsed'], $second['removed'] );

$backfilled = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ys_fct_status_history WHERE source = 'backfill'" );
$hooked     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ys_fct_status_history WHERE source <> 'backfill'" );

ys_pipe_assert( 'R5 hook-written rows were not touched', true, $hooked > 0 );

echo '  ->   history rows: backfill=' . $backfilled . ' hooks=' . $hooked . PHP_EOL;

// R3 moved a history row five days into the past; the activity line describing
// the same change still carries today's timestamp, so the backfill correctly
// treats it as a change it has not seen and files it under today. Re-apply the
// backdate so R6 and R7 look at the same overdue order R3 did. Only a test
// rewrites a recorded timestamp — this is not a situation a real store reaches.
$wpdb->query(
	$wpdb->prepare(
		"UPDATE {$wpdb->prefix}ys_fct_status_history SET changed_at = %s WHERE order_id = %d AND new_status = 'in_production'",
		gmdate( 'Y-m-d H:i:s', time() - ( 5 * DAY_IN_SECONDS ) ),
		$r3
	)
);

ys_pipe_assert( 'R5 the overdue order is overdue again', true, in_array( $r3, wp_list_pluck( ReportService::stalled()['orders'], 'order_id' ), true ) );

// ── R6 ───────────────────────────────────────────────────────────────────────

ys_pipe_section( 'R6 — CSV export' );

foreach ( array( 'distribution', 'dwell', 'stalled' ) as $type ) {
	$export = ys_status_rest( 'GET', '/ys-fct-status/v1/reports/export?type=' . $type, array() );

	ys_pipe_assert( 'R6 ' . $type . ' export answers', 200, $export['status'] );
	ys_pipe_assert( 'R6 ' . $type . ' starts with a UTF-8 BOM', "\xEF\xBB\xBF", substr( $export['data']['csv'], 0, 3 ) );
	// 0.3: the two reports that follow the report tab's axis switch carry the
	// axis in the filename, so exporting both workflows into one folder gives
	// two files whose names say which is which.
	$scope = in_array( $type, array( 'dwell', 'stalled' ), true ) ? $type . '-order' : $type;

	ys_pipe_assert( 'R6 ' . $type . ' has a filename', true, (bool) preg_match( '/^ys-order-status-' . $scope . '-\d{8}-\d{6}\.csv$/', $export['data']['filename'] ) );

	$lines = explode( "\r\n", trim( $export['data']['csv'] ) );

	ys_pipe_assert( 'R6 ' . $type . ' has a header and at least one row', true, count( $lines ) >= 2 );

	echo '  ->   ' . $type . ' header: ' . substr( $lines[0], 3 ) . PHP_EOL;
	echo '  ->   ' . $type . ' row 1:  ' . ( isset( $lines[1] ) ? $lines[1] : '(none)' ) . PHP_EOL;
}

$bad = ys_status_rest( 'GET', '/ys-fct-status/v1/reports/export?type=../../etc/passwd', array() );

ys_pipe_assert( 'R6 an unknown report type is refused', 400, $bad['status'] );

// ── R7 ───────────────────────────────────────────────────────────────────────

ys_pipe_section( 'R7 — the daily summary e-mail' );

$mails = array();

add_filter(
	'pre_wp_mail',
	function ( $short, $atts ) use ( &$mails ) {
		$mails[] = $atts;

		// Intercept: the local site has no mail transport, and a summary that
		// really tried to send would fail for a reason that has nothing to do
		// with whether it was triggered.
		return true;
	},
	10,
	2
);

$settings                  = Settings::all();
$settings['daily_summary'] = array( 'enabled' => 'no', 'email' => 'workflow@example.test' );

Settings::save( $settings );
StatusRegistry::flushCache();
delete_option( DailySummary::LAST_SENT_OPTION );

DailySummary::run( true );

ys_pipe_assert( 'R7 nothing is sent while the summary is off', 0, count( $mails ) );
ys_pipe_assert( 'R7 and no cron event is scheduled', false, (bool) wp_next_scheduled( DailySummary::HOOK ) );

$settings['daily_summary'] = array( 'enabled' => 'yes', 'email' => 'workflow@example.test' );

Settings::save( $settings );
StatusRegistry::flushCache();
DailySummary::syncSchedule();
delete_option( DailySummary::LAST_SENT_OPTION );

DailySummary::run( true );

ys_pipe_assert( 'R7 switching it on sends one message', 1, count( $mails ) );
ys_pipe_assert( 'R7 to the configured address', 'workflow@example.test', $mails[0]['to'] );
ys_pipe_assert( 'R7 the subject counts the overdue orders', true, false !== strpos( $mails[0]['subject'], 'need attention' ) );
ys_pipe_assert( 'R7 the body lists the workflow steps', true, false !== strpos( $mails[0]['message'], 'In production' ) );
ys_pipe_assert( 'R7 the body lists the stuck order', true, false !== strpos( $mails[0]['message'], '#' . $r3 ) );
ys_pipe_assert( 'R7 a cron event is scheduled', true, (bool) wp_next_scheduled( DailySummary::HOOK ) );

DailySummary::run();

ys_pipe_assert( 'R7 it does not send twice in one day', 1, count( $mails ) );

// The store's own name opens the subject line; masked so a committed run of this
// script never carries it.
echo '  ->   subject: ' . preg_replace( '/^\[[^\]]*\]/', '[store name]', $mails[0]['subject'] ) . PHP_EOL;

$settings['daily_summary'] = array( 'enabled' => 'no', 'email' => '' );

Settings::save( $settings );
StatusRegistry::flushCache();
DailySummary::syncSchedule();

ys_pipe_assert( 'R7 turning it off unschedules the event', false, (bool) wp_next_scheduled( DailySummary::HOOK ) );

// ─────────────────────────────────────────────────────────────────────────────

ys_pipe_section( 'Order ids' );

echo wp_json_encode( $ids ) . PHP_EOL;
echo 'history rows: ' . HistoryRepository::count() . PHP_EOL;
echo 'history table: ' . Schema::table() . PHP_EOL;

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
