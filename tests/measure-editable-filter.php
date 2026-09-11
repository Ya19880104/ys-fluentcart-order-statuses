<?php
/**
 * Measurement: can `fluent_cart/editable_order_statuses` see the order?
 *
 *   wp eval-file tests/measure-editable-filter.php
 *
 * The design doc left this open. This records, for every call to the filter
 * during a real REST status change:
 *   - how many arguments core passes and what the second one contains
 *   - whether an order can be identified any other way
 *   - which core function applied the filter
 *
 * Read-only apart from the one order it creates and the status it sets on it.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\OrderContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib-orders.php';

$observations = array();

add_filter(
	'fluent_cart/editable_order_statuses',
	function ( $statuses, $context = '__absent__' ) use ( &$observations ) {
		$args = func_get_args();

		$frames = array();

		foreach ( array_slice( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 14 ), 3, 6 ) as $frame ) {
			$frames[] = ( isset( $frame['class'] ) ? $frame['class'] . '::' : '' ) . $frame['function'];
		}

		$observations[] = array(
			'arg_count'        => count( $args ),
			'second_arg'       => isset( $args[1] ) ? $args[1] : '__absent__',
			'order_context_id' => OrderContext::orderId(),
			'is_rest'          => defined( 'REST_REQUEST' ) && REST_REQUEST,
			'callers'          => $frames,
			'returned_slugs'   => array_keys( (array) $statuses ),
		);

		return $statuses;
	},
	99,
	2
);

// A known-good custom status to look for in the returned list.
Settings::save(
	array(
		'restore_on_payment' => 'yes',
		'order'              => array(
			array(
				'slug'                => 'measure_probe',
				'label'               => 'Measure probe',
				'color'               => '#DB8A3E',
				'editable'            => true,
				'enabled'             => true,
				'payment_requirement' => 'paid_only',
				'on_payment'          => 'keep',
				'sort_order'          => 10,
			),
			array(
				'slug'                => 'measure_any',
				'label'               => 'Measure any',
				'color'               => '#16244A',
				'editable'            => true,
				'enabled'             => true,
				'payment_requirement' => 'any',
				'on_payment'          => 'keep',
				'sort_order'          => 20,
			),
		),
		'shipping'           => array(),
		'overrides'          => array(),
	)
);
StatusRegistry::flushCache();

ys_status_out( '## 1. Cold call, no request in flight' );
$cold = \FluentCart\App\Helpers\Status::getEditableOrderStatuses();
ys_status_out( 'slugs', array_keys( $cold ) );
ys_status_out( 'observations', count( $observations ) );
ys_status_out( 'last', end( $observations ) );

$observations = array();

/**
 * @param string $heading  Section heading.
 * @param string $slug     Status to try to set.
 * @param array  $shared   Observation buffer.
 * @return int Order id used.
 */
function ys_measure_case( $heading, $slug, array &$shared ) {
	$shared = array();

	$orderId = ys_status_make_order( array( 'note' => 'STATUS- measurement probe' ) );

	ys_status_out( PHP_EOL . $heading . ' (order ' . $orderId . ', want "' . $slug . '")' );
	ys_status_out( 'before', ys_status_order_row( $orderId ) );

	$result = ys_status_rest(
		'PUT',
		'/fluent-cart/v2/orders/' . $orderId . '/statuses',
		array(
			'action'       => 'change_order_status',
			'manage_stock' => false,
			'statuses'     => array( 'order_status' => $slug ),
		)
	);

	ys_status_out( 'rest status', $result['status'] );
	ys_status_out( 'rest body', is_array( $result['data'] ) ? array_intersect_key( $result['data'], array_flip( array( 'message', 'code', 'errors' ) ) ) : $result['data'] );
	ys_status_out( 'after', ys_status_order_row( $orderId ) );

	foreach ( $shared as $index => $observation ) {
		ys_status_out( '-- filter call #' . ( $index + 1 ) );
		ys_status_out( '   arg_count', $observation['arg_count'] );
		ys_status_out( '   second_arg', $observation['second_arg'] );
		ys_status_out( '   order_context_id', $observation['order_context_id'] );
		ys_status_out( '   is_rest', $observation['is_rest'] ? 'yes' : 'no' );
		ys_status_out( '   callers', $observation['callers'] );
		ys_status_out( '   returned_slugs', $observation['returned_slugs'] );
	}

	if ( empty( $shared ) ) {
		ys_status_out( '-- the filter was never reached (short-circuited before the controller)' );
	}

	return $orderId;
}

$allowed = ys_measure_case( '## 2. Permitted change (payment_requirement = any)', 'measure_any', $observations );
$refused = ys_measure_case( '## 3. Refused change (payment_requirement = paid_only, order unpaid)', 'measure_probe', $observations );

ys_status_out( PHP_EOL . 'probe order ids', array( $allowed, $refused ) );
