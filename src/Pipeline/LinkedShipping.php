<?php
/**
 * "When the order reaches this status, mark it shipped."
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Pipeline;

use YangSheep\FluentCart\OrderStatuses\History\Recorder;
use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\ActivityLog;
use YangSheep\FluentCart\OrderStatuses\Support\OrderRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One direction only: order status → shipping status.
 *
 * The last step of an order pipeline is usually also a fulfilment fact, and
 * making staff set it twice is how the two axes drift apart. A custom order
 * status can therefore name one shipping status, and moving an order into it
 * moves the shipping status too.
 *
 * The write goes through `OrderResource::updateStatuses()` rather than a direct
 * column update, on purpose. That method is where FluentCart resets each
 * physical line item's `fulfilled_quantity`, remembers the previous status when
 * moving to `unshippable`, validates against `editable_shipping_statuses` and
 * dispatches `OrderStatusUpdated` — which is what fires
 * `shipping_status_changed_to_<slug>`. Writing the column ourselves would give
 * the right value and none of the behaviour around it. If that class is ever
 * missing, `writeDirectly()` below is the fallback, and it fires the events by
 * hand so listeners still see the change.
 *
 * Nothing here runs in reverse: changing the shipping status never changes the
 * order status. A two-way binding between two axes that both have manual
 * controls is a loop waiting to happen.
 */
final class LinkedShipping {

	/** After `Payment\RestoreHandler` (5), before `History\Recorder` (30). */
	const PRIORITY = 20;

	/** Shipping statuses that mean "this order will never ship". */
	const NON_SHIPPING = array( '', 'unshippable' );

	/** @var array<int,bool> Orders handled in this request. */
	private static $handled = array();

	/** @var bool Re-entrancy guard. */
	private static $running = false;

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'fluent_cart/order_status_changed', array( $this, 'maybeSync' ), self::PRIORITY );
	}

	/**
	 * @param mixed $data `['order','old_status','new_status','manageStock','activity']`.
	 * @return void
	 */
	public function maybeSync( $data ) {
		if ( self::$running || ! is_array( $data ) || empty( $data['order'] ) ) {
			return;
		}

		$order = $data['order'];

		if ( ! is_object( $order ) || empty( $order->id ) ) {
			return;
		}

		$orderId   = (int) $order->id;
		$newStatus = isset( $data['new_status'] ) ? (string) $data['new_status'] : '';

		$target = self::linkedStatusFor( $newStatus );

		if ( '' === $target ) {
			return;
		}

		// One sync per order per request. A payment that lands while the order
		// is already on a linked status reaches this hook a second time through
		// the restore path, and repeating the write would repeat the events.
		if ( isset( self::$handled[ $orderId ] ) ) {
			return;
		}

		$row = OrderRepository::find( $orderId );

		if ( null === $row ) {
			return;
		}

		$current = (string) $row['shipping_status'];

		if ( $current === $target ) {
			self::$handled[ $orderId ] = true;
			return;
		}

		if ( in_array( $current, self::NON_SHIPPING, true ) ) {
			// A digital order has no shipping status at all — the column is an
			// empty string, and writing one would invent a fulfilment state for
			// something that is never fulfilled. Say so in the timeline instead
			// of failing quietly.
			self::$handled[ $orderId ] = true;

			ActivityLog::order(
				$orderId,
				__( 'Shipping status not linked', 'ys-fluentcart-order-statuses' ),
				sprintf(
					/* translators: 1: order status label, 2: shipping status slug */
					__( 'The order status %1$s is linked to the shipping status “%2$s”, but this order has no shipping status to set (it is a digital or non-shippable order). The order status was changed; the shipping status was left alone.', 'ys-fluentcart-order-statuses' ),
					self::labelFor( $newStatus ),
					$target
				),
				'info'
			);

			return;
		}

		self::$running             = true;
		self::$handled[ $orderId ] = true;

		Recorder::markLinkedWrite( true );

		$result = self::write( $orderId, $target );

		Recorder::markLinkedWrite( false );

		self::$running = false;

		if ( is_wp_error( $result ) ) {
			ActivityLog::order(
				$orderId,
				__( 'Linked shipping status failed', 'ys-fluentcart-order-statuses' ),
				sprintf(
					/* translators: 1: shipping status slug, 2: error message */
					__( 'Tried to set the shipping status to “%1$s” because of the order status change, and FluentCart refused: %2$s', 'ys-fluentcart-order-statuses' ),
					$target,
					$result->get_error_message()
				),
				'warning'
			);

			return;
		}

		ActivityLog::order(
			$orderId,
			__( 'Shipping status updated automatically', 'ys-fluentcart-order-statuses' ),
			sprintf(
				/* translators: 1: order status label, 2: old shipping slug, 3: new shipping slug */
				__( 'The order status %1$s is linked to a shipping status, so the shipping status was changed from “%2$s” to “%3$s”.', 'ys-fluentcart-order-statuses' ),
				self::labelFor( $newStatus ),
				$current,
				$target
			)
		);

		/**
		 * Fires after a linked shipping status has been applied.
		 *
		 * @param int    $orderId    Order id.
		 * @param string $target     Shipping slug written.
		 * @param string $orderSlug  Order status that triggered it.
		 */
		do_action( 'ys_fct_status/linked_shipping_applied', $orderId, $target, $newStatus );
	}

	/**
	 * The shipping status an order slug is linked to, or ''.
	 *
	 * @param string $slug Order status slug.
	 * @return string
	 */
	public static function linkedStatusFor( $slug ) {
		if ( '' === (string) $slug ) {
			return '';
		}

		$custom = Settings::customStatuses( 'order', StatusRegistry::settings() );

		if ( ! isset( $custom[ $slug ] ) ) {
			return '';
		}

		return isset( $custom[ $slug ]['linked_shipping_status'] ) ? (string) $custom[ $slug ]['linked_shipping_status'] : '';
	}

	/**
	 * @param string $slug Order status slug.
	 * @return string Configured label, or the slug.
	 */
	private static function labelFor( $slug ) {
		$custom = Settings::customStatuses( 'order', StatusRegistry::settings() );

		return isset( $custom[ $slug ] ) ? $custom[ $slug ]['label'] : (string) $slug;
	}

	/**
	 * @param int    $orderId Order id.
	 * @param string $target  Shipping slug.
	 * @return true|\WP_Error
	 */
	private static function write( $orderId, $target ) {
		$resource = '\\FluentCart\\Api\\Resource\\OrderResource';

		if ( ! class_exists( $resource ) || ! method_exists( $resource, 'updateStatuses' ) ) {
			return self::writeDirectly( $orderId, $target );
		}

		try {
			$result = $resource::updateStatuses(
				array(
					'order'        => array( 'id' => $orderId ),
					'action'       => 'change_shipping_status',
					'statuses'     => array( 'shipping_status' => $target ),
					// The order's own change already settled stock; this write
					// is about fulfilment, not inventory.
					'manage_stock' => false,
				)
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'ys_fct_status_linked_failed', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Fallback for a FluentCart that no longer exposes the resource API.
	 *
	 * Writes the column and fires the same two actions core would, so
	 * notification add-ons keep working. It cannot reproduce the
	 * `fulfilled_quantity` bookkeeping, and the activity note says so.
	 *
	 * @param int    $orderId Order id.
	 * @param string $target  Shipping slug.
	 * @return true|\WP_Error
	 */
	private static function writeDirectly( $orderId, $target ) {
		global $wpdb;

		$row = OrderRepository::find( $orderId );

		if ( null === $row ) {
			return new \WP_Error( 'ys_fct_status_linked_missing', __( 'Order not found.', 'ys-fluentcart-order-statuses' ) );
		}

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			OrderRepository::table(),
			array( 'shipping_status' => $target ),
			array( 'id' => $orderId ),
			array( '%s' ),
			array( '%d' )
		);

		if ( ! is_int( $updated ) || $updated < 1 ) {
			return new \WP_Error( 'ys_fct_status_linked_write', __( 'The shipping status could not be written.', 'ys-fluentcart-order-statuses' ) );
		}

		$payload = array(
			'order'       => (object) array( 'id' => $orderId ),
			'old_status'  => (string) $row['shipping_status'],
			'new_status'  => $target,
			'manageStock' => false,
			'activity'    => array(),
		);

		do_action( 'fluent_cart/shipping_status_changed_to_' . $target, $payload );
		do_action( 'fluent_cart/shipping_status_changed', $payload );

		return true;
	}
}
