<?php
/**
 * Making FluentCart's mailer run for a custom status change.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Email;

use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one thing core does not do for us.
 *
 * `OrderStatusUpdated::afterDispatch()` fires
 * `fluent_cart/order_status_changed_to_<slug>` and
 * `fluent_cart/shipping_status_changed_to_<slug>` for **every** slug, custom
 * ones included. What core does not do is bind those actions: its
 * `EmailNotificationMailer::register()` binds only the events it ships
 * notifications for (`shipping_status_changed_to_shipped`,
 * `…_to_delivered`, the order and subscription events). So a notification
 * registered on a custom event would sit in the list, switched on, and never
 * send.
 *
 * Binding is per slug rather than on the generic `fluent_cart/order_status_changed`
 * so the string handed to `mailEmailsOfEvent()` is exactly the `event` the
 * notification was registered with — no name reconstruction, and no chance of a
 * built-in status change reaching our notifications.
 *
 * What deliberately sends nothing, because it fires no event at all:
 *
 *  - `OrderRepository::moveOrders()` — the "Move orders" tool writes the
 *    status column with one UPDATE per batch and never builds an
 *    `OrderStatusUpdated`.
 *  - `OrderRepository::setOrderStatus()` — the payment-restore path in
 *    `Payment\RestoreHandler`, same reason.
 *
 * Both are asserted by `tests/email-scenarios.php` (E5, E6) rather than trusted.
 */
final class Dispatcher {

	/** Core binds its own mail at 999; ours runs in the same slot. */
	const PRIORITY = 999;

	/**
	 * @return void
	 */
	public function register() {
		$settings = StatusRegistry::settings();

		foreach ( Settings::AXES as $axis ) {
			foreach ( Settings::customStatuses( $axis, $settings ) as $slug => $definition ) {
				// A static callback on purpose: `add_action` de-duplicates by
				// callback identity, and a bound closure or `$this` method
				// would bind a *second* time if anything ever re-registers
				// (the scenario suites do, after rewriting the status list) —
				// which would send every mail twice.
				add_action(
					'fluent_cart/' . NotificationRegistry::eventFor( $axis, $slug ),
					array( __CLASS__, 'dispatch' ),
					self::PRIORITY,
					1
				);
			}
		}

		add_filter( 'fluent_cart/should_send_email_notification', array( __CLASS__, 'shouldSend' ), 10, 2 );
	}

	/**
	 * @param mixed $data `OrderStatusUpdated::toArray()`.
	 * @return void
	 */
	public static function dispatch( $data ) {
		if ( ! is_array( $data ) || ! class_exists( '\FluentCart\App\Services\Email\EmailNotificationMailer' ) ) {
			return;
		}

		$hook  = (string) current_action();
		$event = 0 === strpos( $hook, 'fluent_cart/' ) ? substr( $hook, strlen( 'fluent_cart/' ) ) : $hook;

		if ( '' === $event ) {
			return;
		}

		$mailer = new \FluentCart\App\Services\Email\EmailNotificationMailer();

		$mailer->mailEmailsOfEvent( $event, $data );
	}

	/**
	 * The two reasons one of our mails is dropped at the last moment.
	 *
	 * Core applies this immediately before building each mail, once per
	 * notification, with the order in hand.
	 *
	 * @param mixed $should  Whether to send.
	 * @param mixed $context `['event', 'mail_name', 'order']`.
	 * @return bool
	 */
	public static function shouldSend( $should, $context = array() ) {
		$name = is_array( $context ) && isset( $context['mail_name'] ) ? (string) $context['mail_name'] : '';

		if ( ! NotificationRegistry::isOurName( $name ) ) {
			return (bool) $should;
		}

		// The kill switch. A staging copy of a production database has real
		// customer addresses in it and real orders that a test will move
		// through the workflow; one constant in wp-config.php stops every mail
		// this plugin would send, without touching the operator's toggles.
		if ( defined( 'YS_FCT_STATUS_DISABLE_EMAILS' ) && YS_FCT_STATUS_DISABLE_EMAILS ) {
			return false;
		}

		$parts = NotificationRegistry::parseName( $name );

		// Only the customer copy depends on the customer's address. The admin
		// copy goes to the shop's own mailing address, which an order without a
		// customer e-mail does not make any less deliverable — and an order in
		// that state is exactly the one an admin wants to hear about.
		if ( null !== $parts && 'customer' === $parts['recipient'] && '' === self::customerEmail( $context ) ) {
			return false;
		}

		return (bool) $should;
	}

	/**
	 * @param array $context Filter context.
	 * @return string
	 */
	private static function customerEmail( array $context ) {
		$order = isset( $context['order'] ) ? $context['order'] : null;

		if ( ! is_object( $order ) ) {
			return '';
		}

		$customer = isset( $order->customer ) ? $order->customer : null;

		if ( is_object( $customer ) && isset( $customer->email ) ) {
			return trim( (string) $customer->email );
		}

		if ( is_array( $customer ) && isset( $customer['email'] ) ) {
			return trim( (string) $customer['email'] );
		}

		return '';
	}
}
