<?php
/**
 * v0.3: the fulfilment workflow on the shipping axis, and the saved views on it.
 *
 * `Pipeline\Changer` itself is not here: every one of its answers needs an order
 * row, so it is covered end to end by `tests/changer-scenarios.php` against a
 * real database instead of being mocked into agreeing with itself.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

declare( strict_types=1 );

use YangSheep\FluentCart\OrderStatuses\Admin\SavedViews;
use YangSheep\FluentCart\OrderStatuses\Pipeline\Template;
use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;

// ── the shipping pipeline ────────────────────────────────────────────────────

YsStatusTest::group( 'Settings::pipelineFor — shipping' );

$flow = Settings::sanitize(
	array(
		'shipping' => array(
			array( 'slug' => 'ship_b', 'label' => 'Second', 'sort_order' => 20 ),
			array( 'slug' => 'ship_a', 'label' => 'First', 'sort_order' => 10 ),
			array( 'slug' => 'ship_off', 'label' => 'Disabled', 'sort_order' => 30, 'enabled' => false ),
		),
		'order'    => array(
			array( 'slug' => 'step_a', 'label' => 'Order step', 'sort_order' => 10 ),
		),
	)
);

YsStatusTest::same(
	array( 'unshipped', 'ship_a', 'ship_b', 'shipped' ),
	Settings::pipelineFor( 'shipping', $flow ),
	'the fulfilment pipeline is unshipped, the enabled customs in list order, then shipped'
);

YsStatusTest::same(
	array( 'processing', 'step_a' ),
	Settings::pipelineFor( 'order', $flow ),
	'the order pipeline has no closing built-in — "finished" there is a destination, not a step'
);

YsStatusTest::same(
	Settings::pipeline( $flow ),
	Settings::pipelineFor( 'order', $flow ),
	'pipeline() is still the order axis'
);

YsStatusTest::same( 0, Settings::pipelinePositionFor( 'shipping', 'unshipped', $flow ), 'unshipped is step 0 of the fulfilment workflow' );
YsStatusTest::same( 3, Settings::pipelinePositionFor( 'shipping', 'shipped', $flow ), 'shipped closes it' );
YsStatusTest::same( -1, Settings::pipelinePositionFor( 'shipping', 'delivered', $flow ), 'delivered is an outcome, not a step' );
YsStatusTest::same( -1, Settings::pipelinePositionFor( 'shipping', 'unshippable', $flow ), 'and so is unshippable' );
YsStatusTest::same( -1, Settings::pipelinePositionFor( 'shipping', 'ship_off', $flow ), 'a disabled status is not in the workflow' );
YsStatusTest::same( -1, Settings::pipelinePositionFor( 'order', 'ship_a', $flow ), 'a shipping slug has no position on the order axis' );

// ── the two maps FluentCart reads ────────────────────────────────────────────

YsStatusTest::group( 'StatusRegistry — shipping statuses in workflow order' );

$GLOBALS['ys_status_options'][ Settings::OPTION ] = $flow;
StatusRegistry::flushCache();

$registry = new StatusRegistry();

$core = array(
	'unshipped'   => 'Unshipped',
	'shipped'     => 'Shipped',
	'delivered'   => 'Delivered',
	'unshippable' => 'Unshippable',
);

YsStatusTest::same(
	array( 'unshipped', 'ship_a', 'ship_b', 'shipped', 'delivered', 'unshippable' ),
	array_keys( $registry->editableShippingStatuses( $core ) ),
	'the write-side allow-list reads in fulfilment order'
);

// Measured on 1.6.3: the admin SPA localises `shipping_statuses` and not
// `editable_shipping_statuses`, so this is the map the "Change Shipping Status"
// dialog is built from and it has to be ordered too.
YsStatusTest::same(
	array( 'unshipped', 'ship_a', 'ship_b', 'shipped', 'delivered', 'unshippable' ),
	array_keys( $registry->shippingStatuses( $core ) ),
	'and so does the display map the shipping dialog reads'
);

YsStatusTest::same(
	array( 'unshipped', 'ship_a', 'ship_b', 'shipped' ),
	array_keys( StatusRegistry::inPipelineOrderFor( 'shipping', array( 'shipped' => 'S', 'ship_b' => 'B', 'unshipped' => 'U', 'ship_a' => 'A' ) ) ),
	'inPipelineOrderFor sorts whatever it is given'
);

// ── the fulfilment template ──────────────────────────────────────────────────

YsStatusTest::group( 'Template::mergeShippingInto' );

$fresh = Template::mergeShippingInto( Settings::defaults() );

YsStatusTest::same( array( 'in_production', 'ship_scheduled' ), $fresh['added'], 'two custom shipping steps are added' );
YsStatusTest::same( array(), $fresh['settings']['order'], 'and no order statuses at all' );

YsStatusTest::same(
	array( 'unshipped', 'in_production', 'ship_scheduled', 'shipped' ),
	Settings::pipelineFor( 'shipping', $fresh['settings'] ),
	'which gives paid → in production → shipment scheduled → shipped on the shipping axis'
);

YsStatusTest::same( 'Awaiting production', $fresh['settings']['overrides']['shipping']['unshipped']['label'], 'unshipped is relabelled for a production queue' );
YsStatusTest::same( 'Paid', $fresh['settings']['overrides']['payment']['paid']['label'], 'and the payment status reads "Paid"' );
YsStatusTest::same( 'Paid', $fresh['settings']['overrides']['order']['processing']['label'], 'as does the order status core writes on payment' );

YsStatusTest::same(
	false,
	isset( Settings::customStatuses( 'shipping', $fresh['settings'] )['shipped'] ),
	'`shipped` is never redefined — core checks that exact slug when it marks items fulfilled'
);

$twice = Template::mergeShippingInto( $fresh['settings'] );

YsStatusTest::same( array(), $twice['added'], 'applying it twice adds nothing' );
YsStatusTest::same( array( 'in_production', 'ship_scheduled' ), $twice['skipped'], 'and says which steps were already there' );

$chosen                                                    = $fresh['settings'];
$chosen['overrides']['shipping']['unshipped']['label']     = 'Queued';
$kept                                                      = Template::mergeShippingInto( $chosen );

YsStatusTest::same( 'Queued', $kept['settings']['overrides']['shipping']['unshipped']['label'], 'a label the operator chose is never overwritten' );

// Both templates on one store: the same two slugs on both axes, each with its
// own definition, because the columns are independent.
$both = Template::mergeInto( $fresh['settings'] );

YsStatusTest::same(
	array( 'in_production', 'ship_scheduled', 'shipped_done' ),
	array_column( $both['settings']['order'], 'slug' ),
	'the order template still adds its three steps beside them'
);

YsStatusTest::same(
	array( 'in_production', 'ship_scheduled' ),
	array_column( $both['settings']['shipping'], 'slug' ),
	'and leaves the shipping ones alone'
);

YsStatusTest::same(
	'paid_only',
	$both['settings']['order'][0]['payment_requirement'],
	'the order-axis definition of the shared slug carries a payment requirement'
);

YsStatusTest::same(
	false,
	isset( $both['settings']['shipping'][0]['payment_requirement'] ),
	'and the shipping-axis one does not, because money is not a shipping question'
);

// ── saved views ──────────────────────────────────────────────────────────────

YsStatusTest::group( 'SavedViews — the shipping axis' );

$GLOBALS['ys_status_options'][ Settings::OPTION ] = Settings::sanitize( $both['settings'] );
StatusRegistry::flushCache();

$views = array();

// No `filterOptions`, so no counts and no database: this is the shape
// `BaseFilter::parseAcceptedView()` asks for when it resolves an incoming slug.
foreach ( ( new SavedViews() )->addOrderViews( array(), array() ) as $config ) {
	foreach ( $config['saved_views'] as $view ) {
		$views[ $view['slug'] ] = $view;
	}
}

YsStatusTest::same( 5, count( $views ), 'three order-axis views and two shipping-axis ones' );
YsStatusTest::ok( isset( $views['ys_status_in_production'] ), 'the order-axis view keeps its own slug' );
YsStatusTest::ok( isset( $views['ys_shipping_in_production'] ), 'and the shipping-axis view gets a different one' );
YsStatusTest::same( 'status = in_production', $views['ys_status_in_production']['query_params']['search'], 'an order view filters with a search expression core understands' );
YsStatusTest::same( '', $views['ys_shipping_in_production']['query_params']['search'], 'a shipping view carries none — `shipping_status` is not a searchable field' );
YsStatusTest::same( 'In production · shipping', $views['ys_shipping_in_production']['name'], 'and its name says which column it filters' );

YsStatusTest::group( 'SavedViews::shippingSlugFor' );

YsStatusTest::same( 'in_production', SavedViews::shippingSlugFor( 'ys_shipping_in_production' ), 'a view slug resolves to its status' );
YsStatusTest::same( '', SavedViews::shippingSlugFor( 'ys_status_in_production' ), 'an order-axis view is not a shipping filter' );
YsStatusTest::same( '', SavedViews::shippingSlugFor( 'ys_shipping_never_defined' ), 'an undefined status selects nothing' );
YsStatusTest::same( '', SavedViews::shippingSlugFor( 'completed' ), 'and neither does a core tab' );
YsStatusTest::same( '', SavedViews::shippingSlugFor( '' ), 'no active view selects nothing' );
