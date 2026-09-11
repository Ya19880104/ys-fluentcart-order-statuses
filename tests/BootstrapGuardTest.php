<?php
/**
 * The plugin must stand down cleanly when FluentCart is missing or too old.
 *
 * Loaded last: it requires the real plugin file, which registers hooks.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

declare( strict_types=1 );

use YangSheep\FluentCart\OrderStatuses\Bootstrap;
use YangSheep\FluentCart\OrderStatuses\Settings;

YsStatusTest::group( 'Bootstrap version gate' );

YsStatusTest::same( false, Bootstrap::fluentCartIsUsable(), 'no FluentCart means no hooks' );

define( 'FLUENTCART_VERSION', '1.5.9' );
YsStatusTest::same( false, Bootstrap::fluentCartIsUsable(), 'FluentCart older than the minimum is refused' );

YsStatusTest::group( 'Constants and reserved slug lists' );

YsStatusTest::same( 20, Settings::MAX_SLUG_LENGTH, 'the slug cap matches the VARCHAR(20) column' );
YsStatusTest::same( 'ys_fct_status_settings', Settings::OPTION, 'the option name is the documented one' );
YsStatusTest::ok( in_array( 'canceled', Settings::BUILTIN_ORDER, true ), 'canceled is reserved' );
YsStatusTest::ok( in_array( 'unshippable', Settings::BUILTIN_SHIPPING, true ), 'unshippable is reserved' );
YsStatusTest::same( array( 'any', 'unpaid_only', 'paid_only' ), Settings::PAYMENT_REQUIREMENTS, 'the three payment requirements are stable' );
YsStatusTest::same( array( 'keep', 'let_core_decide' ), Settings::ON_PAYMENT, 'the two payment behaviours are stable' );

foreach ( Settings::OVERRIDABLE_ORDER as $slug ) {
	YsStatusTest::ok( in_array( $slug, Settings::BUILTIN_ORDER, true ), sprintf( 'overridable "%s" is a real built-in', $slug ) );
}
