<?php
/**
 * Which order the current REST request is about.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The answer to the open question in the design doc.
 *
 * `fluent_cart/editable_order_statuses` is applied by
 * `Status::getEditableOrderStatuses()`, which takes no arguments and passes an
 * empty array as the filter's second parameter — so a callback on it has no way
 * to know which order (if any) is being edited. It is called both with an order
 * in scope (`OrderResource::updateStatuses()`, mid-request) and with none at
 * all (`MenuHandler`'s localize block, once per admin page load).
 *
 * What IS knowable is the REST route: every call that carries an order does so
 * as `/fluent-cart/v2/orders/{id}/...`. `rest_pre_dispatch` fires before the
 * controller runs and hands us the matched route and its parameters, so we
 * record the id there and drop it again on `rest_post_dispatch`.
 *
 * This is per-request state on purpose: a filter callback that guessed from
 * `$_REQUEST` would also fire during page loads that have nothing to do with an
 * order, and would be wrong in exactly the situation that matters.
 */
final class OrderContext {

	/** @var int Order id of the request in flight, or 0. */
	private static $orderId = 0;

	/** Matches any FluentCart order-scoped REST route. */
	const ROUTE_PATTERN = '#^/[a-z0-9\-]+/v\d+/orders/(\d+)(?:/|$)#i';

	/**
	 * @return void
	 */
	public function register() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'capture' ), 1, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'release' ), 99, 3 );
	}

	/**
	 * @param mixed $result   Short-circuit value.
	 * @param mixed $server   REST server.
	 * @param mixed $request  REST request.
	 * @return mixed Untouched.
	 */
	public static function capture( $result, $server, $request ) {
		self::$orderId = 0;

		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $result;
		}

		if ( preg_match( self::ROUTE_PATTERN, (string) $request->get_route(), $matches ) ) {
			self::$orderId = (int) $matches[1];
		}

		return $result;
	}

	/**
	 * @param mixed $response Response.
	 * @param mixed $server   REST server.
	 * @param mixed $request  REST request.
	 * @return mixed Untouched.
	 */
	public static function release( $response, $server, $request ) {
		self::$orderId = 0;

		return $response;
	}

	/**
	 * @return int Order id, or 0 when the current request is not order-scoped.
	 */
	public static function orderId() {
		return self::$orderId;
	}

	/**
	 * Test seam / programmatic override.
	 *
	 * @param int $orderId Order id.
	 * @return void
	 */
	public static function set( $orderId ) {
		self::$orderId = (int) $orderId;
	}
}
