<?php
/**
 * Writing every status change into the history table.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\History;

use YangSheep\FluentCart\OrderStatuses\Payment\RestoreHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two hooks, one table.
 *
 * `fluent_cart/order_status_changed` and `fluent_cart/shipping_status_changed`
 * both fire from `OrderStatusUpdated::afterDispatch()`, for every path that
 * changes a status: the admin dropdown, the REST API, the payment sync and this
 * plugin's own linked-shipping write. Listening there rather than wrapping the
 * writes means nothing has to be routed through this plugin to be recorded.
 *
 * Priority 30 puts this after `Payment\RestoreHandler` (priority 5), which
 * matters: when core overwrites a custom status on payment and the restore puts
 * it straight back, the order never really left the status it was in. Recording
 * the raw pair would leave a zero-second stay in `processing` in the middle of
 * every paid order's timeline and pull the average dwell towards nothing, so
 * that specific round trip is skipped instead.
 */
final class Recorder {

	/** After the restore handler, so the recorded value is the settled one. */
	const PRIORITY = 30;

	/**
	 * Set by `Pipeline\LinkedShipping` around its own write so the row it causes
	 * is attributable. Not a parameter, because the write travels through
	 * FluentCart and comes back as an ordinary event.
	 *
	 * @var bool
	 */
	private static $linkedWrite = false;

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'fluent_cart/order_status_changed', array( $this, 'recordOrder' ), self::PRIORITY );
		add_action( 'fluent_cart/shipping_status_changed', array( $this, 'recordShipping' ), self::PRIORITY );
		add_action( 'ys_fct_status/custom_status_restored', array( $this, 'recordRestore' ), 10, 3 );
	}

	/**
	 * @param bool $on Whether the next shipping write comes from a linked status.
	 * @return void
	 */
	public static function markLinkedWrite( $on ) {
		self::$linkedWrite = (bool) $on;
	}

	/**
	 * @param mixed $data Event payload.
	 * @return void
	 */
	public function recordOrder( $data ) {
		$parsed = self::parse( $data );

		if ( null === $parsed ) {
			return;
		}

		$restored = RestoreHandler::restoredThisRequest();

		// Core moved the order to `processing` on payment and we put it straight
		// back: no net change, nothing to record.
		if ( isset( $restored[ $parsed['order_id'] ] ) && $restored[ $parsed['order_id'] ] === $parsed['old_status'] ) {
			return;
		}

		HistoryRepository::record(
			$parsed['order_id'],
			'order',
			$parsed['old_status'],
			$parsed['new_status'],
			'hook'
		);
	}

	/**
	 * @param mixed $data Event payload.
	 * @return void
	 */
	public function recordShipping( $data ) {
		$parsed = self::parse( $data );

		if ( null === $parsed ) {
			return;
		}

		HistoryRepository::record(
			$parsed['order_id'],
			'shipping',
			$parsed['old_status'],
			$parsed['new_status'],
			self::$linkedWrite ? 'linked' : 'hook'
		);
	}

	/**
	 * A restore that did NOT return the order to where it started.
	 *
	 * `recordOrder()` skips the no-op round trip; this covers the case where the
	 * order genuinely moved, so the timeline still shows how it got there.
	 *
	 * @param int    $orderId Order id.
	 * @param string $slug    Restored slug.
	 * @param mixed  $data    Original payload.
	 * @return void
	 */
	public function recordRestore( $orderId, $slug, $data ) {
		unset( $data );

		$orderId = (int) $orderId;

		if ( $orderId <= 0 ) {
			return;
		}

		/**
		 * Whether a post-payment restore should leave its own history row.
		 *
		 * Off by default: the pair it would produce (`X → processing`,
		 * `processing → X`) describes core's behaviour, not the order's.
		 *
		 * @param bool   $enabled Default false.
		 * @param int    $orderId Order id.
		 * @param string $slug    Restored slug.
		 */
		if ( ! apply_filters( 'ys_fct_status/record_restore_history', false, $orderId, $slug ) ) {
			return;
		}

		HistoryRepository::record( $orderId, 'order', 'processing', $slug, 'restore' );
	}

	/**
	 * @param mixed $data Event payload.
	 * @return array{order_id:int,old_status:string,new_status:string}|null
	 */
	private static function parse( $data ) {
		if ( ! is_array( $data ) || empty( $data['order'] ) ) {
			return null;
		}

		$order = $data['order'];

		if ( ! is_object( $order ) || empty( $order->id ) ) {
			return null;
		}

		$old = isset( $data['old_status'] ) ? (string) $data['old_status'] : '';
		$new = isset( $data['new_status'] ) ? (string) $data['new_status'] : '';

		if ( '' === $new || $old === $new ) {
			return null;
		}

		return array(
			'order_id'   => (int) $order->id,
			'old_status' => $old,
			'new_status' => $new,
		);
	}
}
