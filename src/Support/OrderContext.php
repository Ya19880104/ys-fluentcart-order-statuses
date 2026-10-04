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

	/**
	 * The saved view the list request in flight is asking for, or ''.
	 *
	 * Needed for the same reason the order id is, and unavailable for a similar
	 * reason. `BaseFilter::parseAcceptedView()` returns **null** whenever the
	 * incoming `active_view` turns out to be a saved view rather than one of
	 * core's fixed tabs — it stashes the matched view in a protected property
	 * and clears `activeView` — so by the time
	 * `fluent_cart/orders_list_filter_query` fires, the array it is handed
	 * (`BaseFilter::toArray()`) says the active view is null. The request
	 * parameter is still the truth, and `rest_pre_dispatch` is where it can be
	 * read.
	 *
	 * @var string
	 */
	private static $activeView = '';

	/**
	 * The order status each status-changing request found, read before it ran.
	 *
	 * FluentCart reports a cancellation with the *shipping* status in the
	 * old-status slot (see `History\Recorder::canceledFrom()`), and by the time
	 * the event fires the row already says `canceled`. The only moment the
	 * real previous value can be read is before the controller runs.
	 *
	 * @var array<int,string>
	 */
	private static $statusBefore = array();

	/** Matches any FluentCart order-scoped REST route. */
	const ROUTE_PATTERN = '#^/[a-z0-9\-]+/v\d+/orders/(\d+)(?:/|$)#i';

	/** The two routes that change an order status: FluentCart's own and the order-page control's. */
	const STATUS_WRITE_PATTERN = '#/orders/\d+/(?:statuses|change)$#';

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
		self::$orderId      = 0;
		self::$activeView   = '';
		self::$statusBefore = array();

		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $result;
		}

		if ( preg_match( self::ROUTE_PATTERN, (string) $request->get_route(), $matches ) ) {
			self::$orderId = (int) $matches[1];

			// One uncached read, and only for the two routes that write a status.
			if ( preg_match( self::STATUS_WRITE_PATTERN, (string) $request->get_route() )
				&& method_exists( $request, 'get_method' ) && 'GET' !== strtoupper( (string) $request->get_method() ) ) {
				$status = OrderRepository::freshStatus( self::$orderId );

				if ( null !== $status ) {
					self::$statusBefore[ self::$orderId ] = $status;
				}
			}
		}

		$view = $request->get_param( 'active_view' );

		if ( is_string( $view ) ) {
			self::$activeView = sanitize_key( $view );
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
		self::$orderId      = 0;
		self::$activeView   = '';
		self::$statusBefore = array();

		return $response;
	}

	/**
	 * @return int Order id, or 0 when the current request is not order-scoped.
	 */
	public static function orderId() {
		return self::$orderId;
	}

	/**
	 * @param int $orderId Order id.
	 * @return string The order status before the request in flight changed it, or '' when unknown.
	 */
	public static function statusBefore( $orderId ) {
		$orderId = (int) $orderId;

		return isset( self::$statusBefore[ $orderId ] ) ? self::$statusBefore[ $orderId ] : '';
	}

	/**
	 * @return string The `active_view` of the request in flight, or ''.
	 */
	public static function activeView() {
		return self::$activeView;
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

	/**
	 * Test seam / programmatic override.
	 *
	 * @param string $view Saved-view slug.
	 * @return void
	 */
	public static function setActiveView( $view ) {
		self::$activeView = (string) $view;
	}

	/**
	 * The order status a REST request is asking for, or ''.
	 *
	 * Shared by the two `rest_pre_dispatch` vetoes (`Payment\RequirementGuard`
	 * and `Pipeline\StrictGuard`) so they agree on exactly which requests they
	 * are looking at: `PUT /…/orders/{id}/statuses` with
	 * `action=change_order_status`. Anything else — a shipping change, a
	 * mark-as-paid, a refund — is none of their business.
	 *
	 * @param mixed $request REST request.
	 * @return string
	 */
	public static function requestedOrderStatus( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return '';
		}

		if ( ! preg_match( '#/orders/\d+/statuses$#', (string) $request->get_route() ) ) {
			return '';
		}

		if ( 'change_order_status' !== (string) $request->get_param( 'action' ) ) {
			return '';
		}

		$statuses = $request->get_param( 'statuses' );

		return is_array( $statuses ) && isset( $statuses['order_status'] ) ? (string) $statuses['order_status'] : '';
	}
}
