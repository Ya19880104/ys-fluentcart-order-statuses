<?php
/**
 * Shared helpers for the scripted local scenarios.
 *
 * Included by the other files in this directory; not runnable on its own.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

if ( ! function_exists( 'ys_status_fixture' ) ) {
	/**
	 * The ids created by seed-fixtures.php.
	 *
	 * @return array{product_id:int,variation_id:int,customer_id:int,user_id:int,price:int}
	 */
	function ys_status_fixture() {
		global $wpdb;

		$post = get_page_by_path( 'status-physical-01', OBJECT, 'fluent-products' );

		if ( ! $post ) {
			throw new RuntimeException( 'Run tests/seed-fixtures.php first.' );
		}

		$postId = (int) $post->ID;

		return array(
			'product_id'   => $postId,
			'variation_id' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}fct_product_variations WHERE post_id = %d", $postId ) ),
			'customer_id'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}fct_customers WHERE email = %s", 'status-shopper@example.test' ) ),
			'user_id'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}fct_customers WHERE email = %s", 'status-shopper@example.test' ) ),
			'price'        => 6000,
		);
	}
}

if ( ! function_exists( 'ys_status_make_order' ) ) {
	/**
	 * Create an unpaid COD order exactly as the checkout leaves one.
	 *
	 * `on-hold` + `pending` + one pending transaction is what FluentCart's
	 * offline/COD gateway writes, so `mark-as-paid` afterwards exercises the
	 * real `StatusHelper::syncOrderStatuses()` path.
	 *
	 * @param array $args Optional overrides: fulfillment_type, status, note.
	 * @return int Order id.
	 */
	function ys_status_make_order( array $args = array() ) {
		global $wpdb;

		$fixture = ys_status_fixture();
		$now     = current_time( 'mysql', true );
		$price   = $fixture['price'];

		$fulfillment = isset( $args['fulfillment_type'] ) ? $args['fulfillment_type'] : 'physical';
		$status      = isset( $args['status'] ) ? $args['status'] : 'on-hold';
		$note        = isset( $args['note'] ) ? $args['note'] : 'STATUS- fixture order';

		$wpdb->insert(
			$wpdb->prefix . 'fct_orders',
			array(
				'status'               => $status,
				'fulfillment_type'     => $fulfillment,
				'type'                 => 'payment',
				'mode'                 => 'live',
				'shipping_status'      => 'physical' === $fulfillment ? 'unshipped' : '',
				'customer_id'          => $fixture['customer_id'],
				'payment_method'       => 'cod',
				'payment_status'       => 'pending',
				'payment_method_title' => 'Cash on delivery',
				'currency'             => 'USD',
				'subtotal'             => $price,
				'total_amount'         => $price,
				'total_paid'           => 0,
				'note'                 => $note,
				'uuid'                 => wp_generate_uuid4(),
				'created_at'           => $now,
				'updated_at'           => $now,
			)
		);

		$orderId = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'fct_order_items',
			array(
				'order_id'         => $orderId,
				'post_id'          => $fixture['product_id'],
				'fulfillment_type' => $fulfillment,
				'payment_type'     => 'onetime',
				'post_title'       => 'STATUS-Physical-01',
				'title'            => 'STATUS-Physical-01',
				'object_id'        => $fixture['variation_id'],
				'quantity'         => 1,
				'unit_price'       => $price,
				'subtotal'         => $price,
				'line_total'       => $price,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);

		$wpdb->insert(
			$wpdb->prefix . 'fct_order_transactions',
			array(
				'order_id'            => $orderId,
				'order_type'          => 'payment',
				'transaction_type'    => 'charge',
				'vendor_charge_id'    => '',
				'payment_method'      => 'cod',
				'payment_mode'        => 'live',
				'payment_method_type' => 'cod',
				'status'              => 'pending',
				'currency'            => 'USD',
				'total'               => $price,
				'uuid'                => wp_generate_uuid4(),
				'created_at'          => $now,
				'updated_at'          => $now,
			)
		);

		return $orderId;
	}
}

if ( ! function_exists( 'ys_status_order_row' ) ) {
	/**
	 * @param int $orderId Order id.
	 * @return array|null
	 */
	function ys_status_order_row( $orderId ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status, payment_status, shipping_status, fulfillment_type, total_paid FROM {$wpdb->prefix}fct_orders WHERE id = %d", $orderId ),
			ARRAY_A
		);
	}
}

if ( ! function_exists( 'ys_status_activity' ) ) {
	/**
	 * @param int $orderId Order id.
	 * @param int $limit   Rows.
	 * @return array<int,array<string,string>>
	 */
	function ys_status_activity( $orderId, $limit = 6 ) {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, content, status FROM {$wpdb->prefix}fct_activity WHERE module_id = %d AND module_type LIKE %s ORDER BY id DESC LIMIT %d",
				$orderId,
				'%Order',
				$limit
			),
			ARRAY_A
		);
	}
}

if ( ! function_exists( 'ys_status_rest' ) ) {
	/**
	 * Dispatch a real FluentCart REST request as the administrator.
	 *
	 * Goes through `rest_do_request()`, so `rest_pre_dispatch`, the route's own
	 * permission callback and the controller all run exactly as they do for the
	 * admin SPA.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route, e.g. `/fluent-cart/v2/orders/9/statuses`.
	 * @param array  $body   JSON body.
	 * @return array{status:int,data:mixed}
	 */
	function ys_status_rest( $method, $route, array $body = array() ) {
		wp_set_current_user( 1 );

		// A query string has to be handed over as query params: the REST server
		// matches `get_route()` against the registered patterns, and a route
		// with `?…` glued on the end matches nothing at all.
		$query = array();

		if ( false !== strpos( $route, '?' ) ) {
			list( $route, $queryString ) = explode( '?', $route, 2 );

			parse_str( $queryString, $query );
		}

		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		if ( ! empty( $query ) ) {
			$request->set_query_params( $query );
		}

		if ( ! empty( $body ) ) {
			$request->set_body( wp_json_encode( $body ) );
		}

		$response = rest_do_request( $request );

		return array(
			'status' => $response->get_status(),
			'data'   => $response->get_data(),
		);
	}
}

if ( ! function_exists( 'ys_status_out' ) ) {
	/**
	 * @param string $label Label.
	 * @param mixed  $value Value.
	 * @return void
	 */
	function ys_status_out( $label, $value = null ) {
		if ( null === $value ) {
			echo $label . PHP_EOL;
			return;
		}

		echo $label . ': ' . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) ) . PHP_EOL;
	}
}
