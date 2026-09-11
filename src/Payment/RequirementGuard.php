<?php
/**
 * "Only on paid orders" / "only on unpaid orders".
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Payment;

use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\OrderContext;
use YangSheep\FluentCart\OrderStatuses\Support\OrderRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforces each custom order status's `payment_requirement`.
 *
 * Two layers, because the admin SPA and the REST API need different things:
 *
 * 1. `rest_pre_dispatch` — vetoes the status change with a message that names
 *    the status and says why. This is the layer the operator actually sees.
 * 2. `fluent_cart/editable_order_statuses` at priority 30 — when the request in
 *    flight is order-scoped, the offending slugs are dropped from the allow-list
 *    `OrderResource::updateStatuses()` validates against. That covers any code
 *    path inside the same request that doesn't go through the REST veto, and it
 *    is the only way the requirement can reach core's own validation.
 *
 * What is deliberately NOT attempted: hiding the option in the admin dropdown.
 * The FluentCart admin is a Vue SPA that reads `editable_order_statuses` once,
 * from `window.fluentCartAdminApp`, at page load — before any order is open,
 * and with the order id living only in the URL fragment, which never reaches
 * the server. Per-order filtering of that list is impossible without shipping
 * Vue. The status is therefore offered and refused with an explanation.
 */
final class RequirementGuard {

	/**
	 * @return void
	 */
	public function register() {
		add_filter( 'rest_pre_dispatch', array( $this, 'vetoRestChange' ), 9, 3 );
		add_filter( 'fluent_cart/editable_order_statuses', array( $this, 'filterEditable' ), 30 );
	}

	/**
	 * @param mixed $result  Short-circuit value; non-null skips the controller.
	 * @param mixed $server  REST server.
	 * @param mixed $request REST request.
	 * @return mixed
	 */
	public function vetoRestChange( $result, $server, $request ) {
		if ( null !== $result || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $result;
		}

		$orderId = OrderContext::orderId();

		if ( $orderId <= 0 ) {
			return $result;
		}

		$newStatus = OrderContext::requestedOrderStatus( $request );

		if ( '' === $newStatus ) {
			return $result;
		}

		$problem = self::rejectionReason( $newStatus, $orderId );

		if ( null === $problem ) {
			return $result;
		}

		return new \WP_Error( 'ys_fct_status_requirement', $problem, array( 'status' => 422 ) );
	}

	/**
	 * Drop statuses the order in flight is not allowed to take.
	 *
	 * @param mixed $statuses Slug => label.
	 * @return array
	 */
	public function filterEditable( $statuses ) {
		$statuses = is_array( $statuses ) ? $statuses : array();

		$orderId = OrderContext::orderId();

		if ( $orderId <= 0 ) {
			return $statuses;
		}

		$row = OrderRepository::find( $orderId );

		if ( null === $row ) {
			return $statuses;
		}

		$isPaid = OrderRepository::isPaid( $row['payment_status'] );

		foreach ( Settings::customStatuses( 'order', StatusRegistry::settings() ) as $slug => $definition ) {
			if ( ! isset( $statuses[ $slug ] ) ) {
				continue;
			}

			if ( ! self::allows( $definition['payment_requirement'], $isPaid ) ) {
				unset( $statuses[ $slug ] );
			}
		}

		return $statuses;
	}

	/**
	 * @param string $requirement One of Settings::PAYMENT_REQUIREMENTS.
	 * @param bool   $isPaid      Whether the order has been paid.
	 * @return bool
	 */
	public static function allows( $requirement, $isPaid ) {
		if ( 'paid_only' === $requirement ) {
			return $isPaid;
		}

		if ( 'unpaid_only' === $requirement ) {
			return ! $isPaid;
		}

		return true;
	}

	/**
	 * Why this order may not take this status, or null when it may.
	 *
	 * Public and static because `Pipeline\Changer` asks the same question when
	 * it builds the order page's status dropdown: the list the operator is
	 * offered and the veto that would refuse the write have to be two readings
	 * of one rule, not two rules that happen to agree today.
	 *
	 * @param string $slug    Requested status.
	 * @param int    $orderId Order id.
	 * @return string|null Human-readable reason.
	 */
	public static function rejectionReason( $slug, $orderId ) {
		$custom = Settings::customStatuses( 'order', StatusRegistry::settings() );

		if ( ! isset( $custom[ $slug ] ) ) {
			return null;
		}

		$definition = $custom[ $slug ];

		if ( 'any' === $definition['payment_requirement'] ) {
			return null;
		}

		$row = OrderRepository::find( $orderId );

		if ( null === $row ) {
			return null;
		}

		$isPaid = OrderRepository::isPaid( $row['payment_status'] );

		if ( self::allows( $definition['payment_requirement'], $isPaid ) ) {
			return null;
		}

		if ( 'paid_only' === $definition['payment_requirement'] ) {
			return sprintf(
				/* translators: %s: status label */
				__( '“%s” can only be used on orders that have been paid. This order has not been paid yet.', 'ys-fluentcart-order-statuses' ),
				$definition['label']
			);
		}

		return sprintf(
			/* translators: %s: status label */
			__( '“%s” can only be used on orders that have not been paid yet. This order has already been paid.', 'ys-fluentcart-order-statuses' ),
			$definition['label']
		);
	}
}
