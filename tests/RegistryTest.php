<?php
/**
 * Merging custom statuses and overrides into FluentCart's maps.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

declare( strict_types=1 );

use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;

update_option( Settings::OPTION, Settings::sanitize( YsStatusFixture::full() ) );
StatusRegistry::flushCache();

$registry = new StatusRegistry();

YsStatusTest::group( 'order_statuses' );

$statuses = $registry->orderStatuses( YsStatusFixture::coreOrderStatuses() );

YsStatusTest::same( '處理中', $statuses['processing'], 'a built-in is relabelled' );
YsStatusTest::same( 'Completed', $statuses['completed'], 'an un-overridden built-in is untouched' );
YsStatusTest::same( '美國採購中', $statuses['sourcing'], 'a custom status is appended' );
YsStatusTest::same( true, isset( $statuses['hidden_step'] ), 'a non-editable status still shows in the display list' );
YsStatusTest::same( false, isset( $statuses['off_step'] ), 'a disabled status is absent everywhere' );
YsStatusTest::same( 5 + 3, count( $statuses ), 'exactly the five core plus three enabled custom statuses' );

YsStatusTest::group( 'editable_order_statuses' );

$editable = $registry->editableOrderStatuses( YsStatusFixture::coreEditableOrderStatuses() );

YsStatusTest::same( true, isset( $editable['sourcing'] ), 'an editable custom status is settable' );
YsStatusTest::same( false, isset( $editable['hidden_step'] ), 'a non-editable custom status is not settable' );
YsStatusTest::same( false, isset( $editable['off_step'] ), 'a disabled custom status is not settable' );
YsStatusTest::same( '處理中', $editable['processing'], 'overrides reach the editable list too' );

YsStatusTest::group( 'shipping and payment' );

$shipping = $registry->shippingStatuses( YsStatusFixture::coreShippingStatuses() );

YsStatusTest::same( '未出貨', $shipping['unshipped'], 'a shipping built-in is relabelled' );
YsStatusTest::same( '已到美國倉', $shipping['us_warehouse'], 'a custom shipping status is appended' );

$payment = $registry->paymentStatuses( YsStatusFixture::corePaymentStatuses() );

YsStatusTest::same( '待付款', $payment['pending'], 'a payment built-in is relabelled' );
YsStatusTest::same( 8, count( $payment ), 'no payment status is ever added' );

YsStatusTest::group( 'colour map' );

$colors = StatusRegistry::colorMap();

YsStatusTest::same( '#db8a3e', $colors['sourcing'], 'a custom colour is exposed' );
YsStatusTest::same( '#2563eb', $colors['processing'], 'an override colour is exposed' );
YsStatusTest::same( false, isset( $colors['pending'] ), 'a label-only override contributes no colour' );
YsStatusTest::same( false, isset( $colors['off_step'] ), 'a disabled status contributes no colour' );

YsStatusTest::group( 'admin_app_data' );

$data = $registry->adminAppData( array( 'existing' => 'kept' ) );

YsStatusTest::same( 'kept', $data['existing'], 'the rest of the payload is left alone' );
YsStatusTest::same( '#db8a3e', $data['ys_status_colors']['sourcing'], 'the colour map is attached for the SPA' );

YsStatusTest::group( 'admin_filter_options' );

$options = $registry->adminFilterOptions(
	array(
		'order_filter_options' => array(
			'advance' => array(
				'order' => array(
					'label'    => 'Order Property',
					'children' => array(
						array(
							'label'   => 'Order Status',
							'value'   => 'status',
							'options' => array(
								'completed'  => 'Completed',
								'processing' => 'Processing',
							),
						),
						array( 'label' => 'Order Type', 'value' => 'type', 'options' => array( 'payment' => 'Single Payment' ) ),
					),
				),
			),
		),
	)
);

$statusNode = $options['order_filter_options']['advance']['order']['children'][0]['options'];

YsStatusTest::same( '美國採購中', $statusNode['sourcing'], 'a custom status becomes a filter option' );
YsStatusTest::same( '處理中', $statusNode['processing'], 'the override relabels the filter option' );
YsStatusTest::same( 'Completed', $statusNode['completed'], 'other options are untouched' );
YsStatusTest::same(
	array( 'payment' => 'Single Payment' ),
	$options['order_filter_options']['advance']['order']['children'][1]['options'],
	'other filter nodes are untouched'
);

YsStatusTest::same( array(), $registry->adminFilterOptions( array() ), 'a payload with no order filters is handled' );
YsStatusTest::same( array(), $registry->adminFilterOptions( 'nonsense' ), 'a non-array payload is handled' );

YsStatusTest::group( 'a reserved slug never reaches core\'s map' );

// Two independent guards stand between a hand-edited option row and a
// relabelled `completed`: `sanitize()` drops the definition on read, and
// `merge()` refuses to overwrite a slug core already owns. This asserts the
// observable result of both.
update_option(
	Settings::OPTION,
	array(
		'version'            => 1,
		'restore_on_payment' => 'yes',
		'order'              => array(
			array( 'slug' => 'completed', 'label' => 'Hijacked', 'enabled' => true, 'editable' => true, 'color' => '#000000', 'sort_order' => 1, 'description' => '', 'payment_requirement' => 'any', 'on_payment' => 'keep' ),
		),
		'shipping'           => array(),
		'overrides'          => array( 'order' => array(), 'payment' => array(), 'shipping' => array() ),
	)
);
StatusRegistry::flushCache();

$statuses = $registry->orderStatuses( YsStatusFixture::coreOrderStatuses() );

YsStatusTest::same( 'Completed', $statuses['completed'], 'core keeps its own label' );

update_option( Settings::OPTION, Settings::sanitize( YsStatusFixture::full() ) );
StatusRegistry::flushCache();
