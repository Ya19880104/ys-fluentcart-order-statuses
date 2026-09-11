<?php
/**
 * Keeping a custom order status when payment lands.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Payment;

use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\ActivityLog;
use YangSheep\FluentCart\OrderStatuses\Support\OrderRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one piece of FluentCart behaviour this add-on has to work around.
 *
 * `StatusHelper::syncOrderStatuses()` reads:
 *
 *     if (!in_array($orderStatus, Status::getOrderSuccessStatuses())) {
 *         if ($orderPaymentStatus == Status::PAYMENT_PAID) {
 *             $orderStatus = Status::ORDER_PROCESSING;
 *         }
 *     }
 *
 * `getOrderSuccessStatuses()` is a hardcoded `['completed','processing']` with
 * no filter, so a custom status can never be in it: the moment a payment is
 * recorded, an order sitting on `sourcing` is rewritten to `processing`. For a
 * proxy-shopping shop that is exactly backwards — "paid" is when the workflow
 * *starts*, not when it ends.
 *
 * There is no pre-write hook on that path, so the only correct place to act is
 * immediately after: `OrderStatusUpdated` dispatches synchronously, inside
 * `syncOrderStatuses()`, before anything else reads the new value. This class
 * listens there and writes the custom slug back.
 *
 * Two things make it safe:
 *
 * - The write is a plain `$wpdb->update` (see `OrderRepository`). Calling
 *   `StatusHelper` or `$order->updateStatus()` would dispatch another
 *   `OrderStatusUpdated` and re-enter this listener.
 * - Digital orders get auto-completed a few lines further down the same method,
 *   using the in-memory model. Restoring the row would be undone by that
 *   `save()`, so the restore also switches the auto-complete filter off for the
 *   order it just handled.
 *
 * What it must NOT do is undo a deliberate admin change. An admin choosing
 * "Processing" by hand reaches the same action — the discriminator is
 * `manageStock`, which core passes as `true` from the payment paths
 * (`syncOrderStatuses()`, `changeOrderStatus()`) and `false` from the admin
 * status dropdown (`OrderResource::updateStatuses()`).
 */
final class RestoreHandler {

	/** Core's hardcoded payment target. Restoring is only ever a reaction to this. */
	const CORE_PAYMENT_STATUS = 'processing';

	/**
	 * Order ids restored during this request: order id => slug restored to.
	 *
	 * Doubles as the double-fire guard (a webhook and a browser return can both
	 * call `syncOrderStatuses()`) and as the signal for the digital
	 * auto-complete filter below.
	 *
	 * @var array<int,string>
	 */
	private static $restored = array();

	/** @var bool Re-entrancy guard. */
	private static $running = false;

	/**
	 * @return void
	 */
	public function register() {
		// Priority 5: before any notification listener this shop might add, so
		// "order is now sourcing and paid" is true by the time they read it.
		add_action( 'fluent_cart/order_status_changed', array( $this, 'maybeRestore' ), 5 );
		add_filter( 'fluent_cart/order_status/auto_complete_digital_order', array( $this, 'blockDigitalAutoComplete' ), 20, 2 );
	}

	/**
	 * @param mixed $data `['order','old_status','new_status','manageStock','activity']`.
	 * @return void
	 */
	public function maybeRestore( $data ) {
		if ( self::$running || ! is_array( $data ) ) {
			return;
		}

		$order = isset( $data['order'] ) ? $data['order'] : null;

		if ( ! is_object( $order ) || empty( $order->id ) ) {
			return;
		}

		$orderId = (int) $order->id;

		if ( isset( self::$restored[ $orderId ] ) ) {
			return;
		}

		$oldStatus = isset( $data['old_status'] ) ? (string) $data['old_status'] : '';
		$newStatus = isset( $data['new_status'] ) ? (string) $data['new_status'] : '';

		$definition = $this->restorableDefinition( $oldStatus );

		if ( null === $definition ) {
			return;
		}

		if ( self::CORE_PAYMENT_STATUS !== $newStatus ) {
			return;
		}

		// `false` is the admin dropdown; `true` is a payment path. Without this
		// an admin deliberately moving a paid order from `sourcing` to
		// `Processing` would be bounced straight back.
		if ( true !== ( isset( $data['manageStock'] ) ? $data['manageStock'] : null ) ) {
			return;
		}

		$row = OrderRepository::find( $orderId );

		if ( null === $row || ! OrderRepository::isPaid( $row['payment_status'] ) ) {
			return;
		}

		self::$running = true;

		// Compare-and-set against the value core just wrote: if anything moved
		// the order on in between, leave it alone.
		$written = OrderRepository::setOrderStatus( $orderId, $oldStatus, self::CORE_PAYMENT_STATUS );

		if ( $written ) {
			self::$restored[ $orderId ] = $oldStatus;

			$this->syncModel( $order, $oldStatus );

			ActivityLog::order(
				$orderId,
				__( 'Custom order status kept', 'ys-fluentcart-order-statuses' ),
				sprintf(
					/* translators: 1: status label, 2: status slug */
					__( 'Payment was recorded. FluentCart set this order to Processing; YS Order Statuses restored the custom status %1$s (%2$s) because it is configured to be kept after payment.', 'ys-fluentcart-order-statuses' ),
					$definition['label'],
					$oldStatus
				)
			);

			/**
			 * Fires after a custom order status has been restored post-payment.
			 *
			 * @param int    $orderId Order id.
			 * @param string $slug    The restored slug.
			 * @param array  $data    The original status-changed payload.
			 */
			do_action( 'ys_fct_status/custom_status_restored', $orderId, $oldStatus, $data );
		}

		self::$running = false;
	}

	/**
	 * Stop core auto-completing a digital order whose status we just restored.
	 *
	 * `syncOrderStatuses()` applies this filter a few lines after dispatching
	 * the status-changed event, then — for digital fulfilment — sets the model
	 * to `completed` and saves it, which would overwrite the row we just wrote.
	 *
	 * @param mixed $enabled Whether core should auto-complete.
	 * @param mixed $context `['order' => Order]`.
	 * @return mixed
	 */
	public function blockDigitalAutoComplete( $enabled, $context = array() ) {
		if ( ! $enabled || ! is_array( $context ) || empty( $context['order'] ) ) {
			return $enabled;
		}

		$order = $context['order'];

		if ( ! is_object( $order ) || empty( $order->id ) ) {
			return $enabled;
		}

		return isset( self::$restored[ (int) $order->id ] ) ? false : $enabled;
	}

	/**
	 * The custom definition behind a slug, if it is one of ours and set to keep.
	 *
	 * @param string $slug Slug.
	 * @return array|null
	 */
	private function restorableDefinition( $slug ) {
		if ( '' === $slug ) {
			return null;
		}

		$settings = StatusRegistry::settings();

		if ( 'yes' !== $settings['restore_on_payment'] ) {
			return null;
		}

		$custom = Settings::customStatuses( 'order', $settings );

		if ( ! isset( $custom[ $slug ] ) ) {
			return null;
		}

		return 'keep' === $custom[ $slug ]['on_payment'] ? $custom[ $slug ] : null;
	}

	/**
	 * Bring the in-memory model back in line with the row we just wrote.
	 *
	 * The caller keeps using this object after the event returns, and a stale
	 * `processing` on it would be written back by the next `save()`.
	 *
	 * @param object $order  Order model.
	 * @param string $status Restored slug.
	 * @return void
	 */
	private function syncModel( $order, $status ) {
		try {
			$order->status = $status;

			// Fluent ORM is an Eloquent fork: marking this one attribute clean
			// stops an unrelated later `save()` from re-issuing a status write,
			// without touching whatever else the caller has pending.
			if ( method_exists( $order, 'syncOriginalAttribute' ) ) {
				$order->syncOriginalAttribute( 'status' );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// A model that refuses the assignment is not worth failing a
			// payment over — the database row is already correct.
			unset( $e );
		}
	}

	/**
	 * @return array<int,string> Order id => restored slug, for this request.
	 */
	public static function restoredThisRequest() {
		return self::$restored;
	}
}
