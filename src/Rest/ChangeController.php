<?php
/**
 * The order page's status control, server side.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Rest;

use YangSheep\FluentCart\OrderStatuses\Pipeline\Changer;
use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\Support\OrderRepository;
use YangSheep\FluentCart\OrderStatuses\Support\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST ys-fct-status/v1/orders/{id}/change` and `GET …/state`.
 *
 * Why this route exists at all: measured on FluentCart 1.6.3, a **paid** order
 * has no order-status control anywhere in the admin. The header carries Refund,
 * a disabled Edit and a "More Action" menu whose entries are *Change Shipping
 * Status, Cancel Order, Sync Order Statuses, Receipt, Refund, Edit*. An
 * operator whose whole workflow is post-payment therefore cannot move an order
 * along it from FluentCart's own UI, however many custom statuses are
 * registered. (The Orders *list* has a bulk status action, and the shipping
 * axis has its own dialog — see the README for why that axis is the
 * recommended home for a fulfilment workflow.)
 *
 * The write does **not** touch the column. It goes through
 * `OrderResource::updateStatuses()` with core's own `change_order_status` /
 * `change_shipping_status` actions, exactly as `Pipeline\LinkedShipping`
 * already does, so everything that hangs off a status change still happens:
 * core's validation against `editable_*_statuses` (which this plugin filters),
 * the canceled-order refusal, `fulfilled_quantity` bookkeeping on the shipping
 * axis, the `OrderStatusUpdated` event and with it every
 * `*_status_changed_to_<slug>` action, FluentCart's own activity line, this
 * plugin's history row, and its linked-shipping follow-up.
 *
 * `manage_stock` is `false`, which is what the admin dropdown sends and what
 * `Payment\RestoreHandler` reads to tell a deliberate admin change apart from a
 * payment-path overwrite. Sending `true` here would make the restore handler
 * undo the operator's own move.
 */
final class ChangeController {

	/** The two actions `OrderResource::updateStatuses()` accepts. */
	const ACTIONS = array(
		'order'    => 'change_order_status',
		'shipping' => 'change_shipping_status',
	);

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * @return void
	 */
	public function registerRoutes() {
		$permission = array( Permissions::class, 'restCanManage' );

		register_rest_route(
			StatusController::NAMESPACE_V1,
			'/orders/(?P<id>\d+)/state',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'state' ),
				'permission_callback' => $permission,
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => static function ( $value ) {
							return (int) $value > 0;
						},
					),
				),
			)
		);

		register_rest_route(
			StatusController::NAMESPACE_V1,
			'/orders/(?P<id>\d+)/change',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'change' ),
				'permission_callback' => $permission,
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => static function ( $value ) {
							return (int) $value > 0;
						},
					),
				),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function state( $request ) {
		$orderId = (int) $request->get_param( 'id' );

		OrderRepository::flush( $orderId );

		$state = Changer::state( $orderId );

		if ( null === $state ) {
			return self::notFound();
		}

		return rest_ensure_response( $state );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function change( $request ) {
		$orderId = (int) $request->get_param( 'id' );
		$axis    = (string) $request->get_param( 'axis' );
		$slug    = Settings::sanitizeSlug( $request->get_param( 'status' ) );

		if ( ! in_array( $axis, Settings::AXES, true ) ) {
			return new \WP_Error(
				'ys_fct_status_bad_axis',
				__( 'Unknown status axis.', 'ys-fluentcart-order-statuses' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $slug ) {
			return new \WP_Error(
				'ys_fct_status_no_target',
				__( 'Pick a status to move this order to.', 'ys-fluentcart-order-statuses' ),
				array( 'status' => 400 )
			);
		}

		OrderRepository::flush( $orderId );

		$row = OrderRepository::find( $orderId );

		if ( null === $row ) {
			return self::notFound();
		}

		$current = 'shipping' === $axis ? (string) $row['shipping_status'] : (string) $row['status'];

		if ( $current === $slug ) {
			return new \WP_Error(
				'ys_fct_status_unchanged',
				__( 'This order is already on that status.', 'ys-fluentcart-order-statuses' ),
				array( 'status' => 422 )
			);
		}

		// The same question the control asked when it built its dropdown. A
		// refusal here means the page was left open while something else moved
		// the order on, or that somebody posted straight at the route.
		$problem = Changer::rejectionFor( $orderId, $axis, $slug, $row );

		if ( null !== $problem ) {
			return new \WP_Error(
				'ys_fct_status_refused',
				$problem,
				array( 'status' => 422 )
			);
		}

		$written = self::write( $orderId, $axis, $slug );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		OrderRepository::flush( $orderId );

		$state = Changer::state( $orderId );

		if ( null === $state ) {
			return self::notFound();
		}

		$state['changed'] = array(
			'axis' => $axis,
			'from' => $current,
			'to'   => $slug,
		);

		$state['message'] = 'shipping' === $axis
			? sprintf(
				/* translators: %s: shipping status label */
				__( 'Shipping status changed to %s.', 'ys-fluentcart-order-statuses' ),
				$state['axes']['shipping']['label']
			)
			: sprintf(
				/* translators: %s: order status label */
				__( 'Order status changed to %s.', 'ys-fluentcart-order-statuses' ),
				$state['axes']['order']['label']
			);

		return rest_ensure_response( $state );
	}

	/**
	 * Hand the write to FluentCart.
	 *
	 * @param int    $orderId Order id.
	 * @param string $axis    'order' or 'shipping'.
	 * @param string $slug    Target slug.
	 * @return true|\WP_Error
	 */
	private static function write( $orderId, $axis, $slug ) {
		$resource = '\\FluentCart\\Api\\Resource\\OrderResource';

		if ( ! class_exists( $resource ) || ! method_exists( $resource, 'updateStatuses' ) ) {
			return new \WP_Error(
				'ys_fct_status_no_resource',
				__( 'This version of FluentCart does not expose the order status API.', 'ys-fluentcart-order-statuses' ),
				array( 'status' => 500 )
			);
		}

		$statuses = 'shipping' === $axis
			? array( 'shipping_status' => $slug )
			: array( 'order_status' => $slug );

		try {
			$result = $resource::updateStatuses(
				array(
					'order'        => array( 'id' => (int) $orderId ),
					'action'       => self::ACTIONS[ $axis ],
					'statuses'     => $statuses,
					// `false` is what the admin dropdown sends, and what
					// `Payment\RestoreHandler` reads to know this is a person
					// rather than a payment. See the class docblock.
					'manage_stock' => false,
				)
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'ys_fct_status_write_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $result ) ) {
			// Core's own words, with its own HTTP status where it set one.
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 422;

			return new \WP_Error(
				'ys_fct_status_core_refused',
				$result->get_error_message(),
				array( 'status' => $status )
			);
		}

		return true;
	}

	/**
	 * @return \WP_Error
	 */
	private static function notFound() {
		return new \WP_Error(
			'ys_fct_status_no_order',
			__( 'That order no longer exists.', 'ys-fluentcart-order-statuses' ),
			array( 'status' => 404 )
		);
	}
}
