<?php
/**
 * Slug validation, the settings whitelist and import round-tripping.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

declare( strict_types=1 );

use YangSheep\FluentCart\OrderStatuses\Settings;

YsStatusTest::group( 'Settings::sanitizeSlug' );

YsStatusTest::same( 'sourcing', Settings::sanitizeSlug( '  Sourcing  ' ), 'trims and lowercases' );
YsStatusTest::same( 'us_warehouse', Settings::sanitizeSlug( 'US Warehouse' ), 'spaces become underscores' );
YsStatusTest::same( 'inwarehouse', Settings::sanitizeSlug( 'in/warehouse!' ), 'strips punctuation' );
YsStatusTest::same( 'a-b_c9', Settings::sanitizeSlug( 'A-B_c9' ), 'keeps dashes, underscores and digits' );
YsStatusTest::same( '', Settings::sanitizeSlug( '   ' ), 'blank stays blank' );

YsStatusTest::group( 'Settings::slugError' );

YsStatusTest::same( null, Settings::slugError( 'sourcing', 'order' ), 'a clean slug passes' );
YsStatusTest::same( 'empty', Settings::slugError( '', 'order' ), 'empty is rejected' );
YsStatusTest::same( 'too_long', Settings::slugError( str_repeat( 'a', 21 ), 'order' ), '21 characters is too long for VARCHAR(20)' );
YsStatusTest::same( null, Settings::slugError( str_repeat( 'a', 20 ), 'order' ), '20 characters is exactly allowed' );
YsStatusTest::same( 'invalid_characters', Settings::slugError( '9lives', 'order' ), 'must start with a letter' );
YsStatusTest::same( 'invalid_characters', Settings::slugError( '_leading', 'order' ), 'may not start with an underscore' );
YsStatusTest::same( 'reserved', Settings::slugError( 'completed', 'order' ), 'core order slug is reserved' );
YsStatusTest::same( 'reserved', Settings::slugError( 'cancelled', 'order' ), 'the two-L spelling core still uses is reserved too' );
YsStatusTest::same( 'reserved', Settings::slugError( 'draft', 'order' ), 'pre-checkout slugs are reserved' );
YsStatusTest::same( 'reserved', Settings::slugError( 'shipped', 'shipping' ), 'core shipping slug is reserved' );
YsStatusTest::same( null, Settings::slugError( 'shipped', 'order' ), 'a shipping slug is free on the order axis' );
YsStatusTest::same( 'duplicate', Settings::slugError( 'sourcing', 'order', array( 'sourcing' ) ), 'a slug used twice is rejected' );

YsStatusTest::group( 'Settings::sanitizeColor' );

YsStatusTest::same( '#db8a3e', Settings::sanitizeColor( '#DB8A3E' ), 'lowercases' );
YsStatusTest::same( '#aabbcc', Settings::sanitizeColor( '#abc' ), 'expands the three-digit form' );
YsStatusTest::same( Settings::DEFAULT_COLOR, Settings::sanitizeColor( 'red' ), 'a colour name falls back' );
YsStatusTest::same( Settings::DEFAULT_COLOR, Settings::sanitizeColor( 'javascript:alert(1)' ), 'anything else falls back' );
YsStatusTest::same( Settings::DEFAULT_COLOR, Settings::sanitizeColor( '' ), 'blank falls back' );

YsStatusTest::group( 'Settings::sanitize — defaults and shape' );

$clean = Settings::sanitize( array() );

YsStatusTest::same( Settings::SCHEMA_VERSION, $clean['version'], 'schema version is stamped' );
YsStatusTest::same( 2, Settings::SCHEMA_VERSION, 'v0.2 ships schema 2' );
YsStatusTest::same( 'yes', $clean['restore_on_payment'], 'restore is on by default' );
YsStatusTest::same( 'no', $clean['pipeline_strict'], 'strict workflow is off by default' );
YsStatusTest::same( 3, $clean['stall_days'], 'the stuck-order threshold defaults to three days' );
YsStatusTest::same( 'no', $clean['daily_summary']['enabled'], 'the daily summary is off by default' );
YsStatusTest::same( array(), $clean['order'], 'no custom order statuses by default' );
YsStatusTest::same( array( 'order', 'payment', 'shipping' ), array_keys( $clean['overrides'] ), 'all three override axes exist' );

YsStatusTest::group( 'Settings::sanitize — definitions' );

$clean = Settings::sanitize(
	array(
		'restore_on_payment' => 'no',
		'order'              => array(
			array(
				'slug'                => ' Sourcing ',
				'label'               => '  美國採購中  ',
				'color'               => '#DB8A3E',
				'description'         => "line one\nline two",
				'editable'            => 'yes',
				'enabled'             => true,
				'payment_requirement' => 'paid_only',
				'on_payment'          => 'keep',
				'sort_order'          => 20,
				'trojan'              => 'dropped',
			),
			array(
				'slug'                => 'second',
				'label'               => 'Second',
				'sort_order'          => 10,
				'payment_requirement' => 'nonsense',
				'on_payment'          => 'nonsense',
			),
			array( 'slug' => 'completed', 'label' => 'Reserved, dropped' ),
			array( 'slug' => 'sourcing', 'label' => 'Duplicate, dropped' ),
			'not an array',
		),
	)
);

YsStatusTest::same( 'no', $clean['restore_on_payment'], 'restore can be switched off' );
YsStatusTest::same( 2, count( $clean['order'] ), 'reserved and duplicate definitions are dropped' );
YsStatusTest::same( 'second', $clean['order'][0]['slug'], 'sorted by sort_order' );
YsStatusTest::same( 'sourcing', $clean['order'][1]['slug'], 'the slug is normalised' );
YsStatusTest::same( '美國採購中', $clean['order'][1]['label'], 'the label is trimmed but not mangled' );
YsStatusTest::same( 'line one line two', $clean['order'][1]['description'], 'newlines are flattened' );
YsStatusTest::same( true, $clean['order'][1]['editable'], "'yes' counts as true" );
YsStatusTest::same( false, array_key_exists( 'trojan', $clean['order'][1] ), 'unknown keys are dropped' );
YsStatusTest::same( 'any', $clean['order'][0]['payment_requirement'], 'an unknown requirement falls back to any' );
YsStatusTest::same( 'keep', $clean['order'][0]['on_payment'], 'an unknown on_payment falls back to keep' );
YsStatusTest::same( Settings::DEFAULT_COLOR, $clean['order'][0]['color'], 'a missing colour gets the default' );

$clean = Settings::sanitize( array( 'order' => array( array( 'slug' => 'nameless' ) ) ) );
YsStatusTest::same( 'nameless', $clean['order'][0]['label'], 'a label-less status falls back to its slug' );

$clean = Settings::sanitize(
	array(
		'shipping' => array(
			array( 'slug' => 'us_warehouse', 'label' => '已到美國倉', 'payment_requirement' => 'paid_only', 'on_payment' => 'keep' ),
		),
	)
);

YsStatusTest::same( false, array_key_exists( 'payment_requirement', $clean['shipping'][0] ), 'shipping statuses carry no payment requirement' );
YsStatusTest::same( false, array_key_exists( 'on_payment', $clean['shipping'][0] ), 'shipping statuses carry no on_payment' );

YsStatusTest::group( 'Settings::sanitize — overrides' );

$clean = Settings::sanitize(
	array(
		'overrides' => array(
			'order'    => array(
				'processing'  => array( 'label' => '處理中', 'color' => '#2563EB' ),
				'draft'       => array( 'label' => 'Not overridable' ),
				'made_up'     => array( 'label' => 'Not a status' ),
				'completed'   => array( 'label' => '', 'color' => '' ),
			),
			'payment'  => array( 'pending' => array( 'label' => '待付款' ) ),
			'shipping' => array( 'unshipped' => array( 'label' => '未出貨' ) ),
			'invented' => array( 'x' => array( 'label' => 'y' ) ),
		),
	)
);

YsStatusTest::same( array( 'processing' ), array_keys( $clean['overrides']['order'] ), 'only overridable order slugs survive' );
YsStatusTest::same( '#2563eb', $clean['overrides']['order']['processing']['color'], 'the override colour is normalised' );
YsStatusTest::same( '', $clean['overrides']['payment']['pending']['color'], 'a label-only override keeps an empty colour' );
YsStatusTest::same( array( 'order', 'payment', 'shipping' ), array_keys( $clean['overrides'] ), 'an invented axis is dropped' );

YsStatusTest::group( 'Settings::customStatuses' );

$settings = Settings::sanitize(
	array(
		'order' => array(
			array( 'slug' => 'live_one', 'label' => 'Live', 'enabled' => true ),
			array( 'slug' => 'dead_one', 'label' => 'Dead', 'enabled' => false ),
		),
	)
);

YsStatusTest::same( array( 'live_one' ), array_keys( Settings::customStatuses( 'order', $settings ) ), 'disabled statuses are excluded' );
YsStatusTest::same( array(), Settings::customStatuses( 'shipping', $settings ), 'an empty axis yields an empty map' );

YsStatusTest::group( 'Settings round-trip (export -> import)' );

$original  = Settings::sanitize( YsStatusFixture::full() );
$roundTrip = Settings::sanitize( json_decode( (string) json_encode( $original ), true ) );

YsStatusTest::same( $original, $roundTrip, 'a sanitised document survives JSON unchanged' );

$fromWild = Settings::sanitize(
	array(
		'order'     => array( array( 'slug' => 'x_one', 'label' => '<script>alert(1)</script>Evil' ) ),
		'overrides' => array( 'order' => array( 'processing' => array( 'label' => '<b>Bold</b>' ) ) ),
	)
);

YsStatusTest::same( 'alert(1)Evil', $fromWild['order'][0]['label'], 'markup is stripped from an imported label' );
YsStatusTest::same( 'Bold', $fromWild['overrides']['order']['processing']['label'], 'markup is stripped from an imported override' );
