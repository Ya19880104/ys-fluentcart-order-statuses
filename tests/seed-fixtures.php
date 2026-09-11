<?php
/**
 * Local fixtures for the T1–T15 walkthrough.
 *
 *   wp eval-file wp-content/plugins/ys-fluentcart-order-statuses/tests/seed-fixtures.php
 *
 * Creates, idempotently and with a STATUS- prefix on everything:
 *   - one physical product `STATUS-Physical-01` ($60) with a single variation
 *   - one FluentCart customer `status-shopper@example.test` (+ WP user)
 *
 * Orders are created on demand by `tests/status-scenarios.php`, not here.
 * Nothing outside those names is read or written.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

global $wpdb;

$now = current_time( 'mysql', true );

// ── Product ──────────────────────────────────────────────────────────────────

$title    = 'STATUS-Physical-01';
$price    = 6000; // cents
$existing = get_page_by_path( sanitize_title( $title ), OBJECT, 'fluent-products' );
$postId   = $existing ? (int) $existing->ID : 0;

if ( ! $postId ) {
	$postId = (int) wp_insert_post(
		array(
			'post_title'   => $title,
			'post_name'    => sanitize_title( $title ),
			'post_content' => 'Fixture product for the custom order statuses addon.',
			'post_status'  => 'publish',
			'post_type'    => 'fluent-products',
		)
	);
}

$detailId = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}fct_product_details WHERE post_id = %d", $postId ) );

if ( ! $detailId ) {
	$wpdb->insert(
		$wpdb->prefix . 'fct_product_details',
		array(
			'post_id'             => $postId,
			'fulfillment_type'    => 'physical',
			'min_price'           => $price,
			'max_price'           => $price,
			'manage_stock'        => 0,
			'stock_availability'  => 'in-stock',
			'variation_type'      => 'simple',
			'manage_downloadable' => 0,
			'other_info'          => '[]',
			'created_at'          => $now,
			'updated_at'          => $now,
		)
	);
	$detailId = (int) $wpdb->insert_id;
}

$variationId = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}fct_product_variations WHERE post_id = %d", $postId ) );

if ( ! $variationId ) {
	$wpdb->insert(
		$wpdb->prefix . 'fct_product_variations',
		array(
			'post_id'          => $postId,
			'serial_index'     => 1,
			'variation_title'  => $title,
			'payment_type'     => 'onetime',
			'stock_status'     => 'in-stock',
			'fulfillment_type' => 'physical',
			'item_status'      => 'active',
			'manage_cost'      => 'false',
			'item_price'       => $price,
			'item_cost'        => 0,
			'compare_price'    => 0,
			'other_info'       => wp_json_encode( array( 'payment_type' => 'onetime', 'description' => '' ) ),
			'downloadable'     => 'false',
			'created_at'       => $now,
			'updated_at'       => $now,
		)
	);
	$variationId = (int) $wpdb->insert_id;
}

$wpdb->update(
	$wpdb->prefix . 'fct_product_details',
	array(
		'default_variation_id' => $variationId,
		'fulfillment_type'     => 'physical',
		'min_price'            => $price,
		'max_price'            => $price,
		'updated_at'           => $now,
	),
	array( 'id' => $detailId )
);

// ── Customer ─────────────────────────────────────────────────────────────────

$email  = 'status-shopper@example.test';
$userId = email_exists( $email );

if ( ! $userId ) {
	$userId = wp_insert_user(
		array(
			'user_login' => 'status-shopper',
			'user_email' => $email,
			'user_pass'  => wp_generate_password( 20 ),
			'first_name' => 'Status',
			'last_name'  => 'Shopper',
			'role'       => 'subscriber',
		)
	);
}

$userId = (int) $userId;

$customerId = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}fct_customers WHERE email = %s", $email ) );

if ( ! $customerId ) {
	$wpdb->insert(
		$wpdb->prefix . 'fct_customers',
		array(
			'user_id'    => $userId,
			'email'      => $email,
			'first_name' => 'Status',
			'last_name'  => 'Shopper',
			'status'     => 'active',
			'created_at' => $now,
			'updated_at' => $now,
		)
	);
	$customerId = (int) $wpdb->insert_id;
}

echo wp_json_encode(
	array(
		'product_id'   => $postId,
		'variation_id' => $variationId,
		'customer_id'  => $customerId,
		'user_id'      => $userId,
		'price'        => $price,
	)
) . PHP_EOL;
