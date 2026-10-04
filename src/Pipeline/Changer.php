<?php
/**
 * "Where may this order go next?" — asked once, answered for both readers.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Pipeline;

use YangSheep\FluentCart\OrderStatuses\Payment\RequirementGuard;
use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\Labels;
use YangSheep\FluentCart\OrderStatuses\Support\OrderContext;
use YangSheep\FluentCart\OrderStatuses\Support\OrderRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One rule, two readings.
 *
 * The order page's status control has to offer exactly the moves the write
 * would accept. Two lists computed two ways drift the moment a rule changes,
 * and the failure is the worst kind: an option the operator can see, click and
 * be refused, with no way to tell whether the refusal or the offer is the bug.
 *
 * So this class is the only place that answers the question, and it answers it
 * by asking the same things the write will ask:
 *
 * - `Status::getEditableOrderStatuses()` / `getEditableShippingStatuses()` —
 *   core's own allow-list, which `OrderResource::updateStatuses()` validates
 *   against and which this plugin has already filtered. Both are called with
 *   `Support\OrderContext` pointed at the order, because that is what makes
 *   `Payment\RequirementGuard` and `Pipeline\StrictGuard` narrow the list for
 *   *this* order rather than for no order at all.
 * - `RequirementGuard::rejectionReason()` and `StrictGuard::rejectionReason()`
 *   for the sentence explaining a refusal, so the 422 the operator sees from
 *   this plugin's route is word-for-word the 422 they would have seen from
 *   FluentCart's.
 * - the canceled-order rule, which is core's and is reproduced here only so
 *   the control can say why it is empty instead of offering moves that would
 *   all fail.
 */
final class Changer {

	/** Core refuses every order-status change once an order is canceled. */
	const CANCELED = 'canceled';

	/** FluentCart's own "this order is finished". */
	const COMPLETED = 'completed';

	/**
	 * Order statuses the control moves to only after the operator confirms.
	 *
	 * Neither is an ordinary step. FluentCart refuses every later order-status
	 * change once an order is canceled, and completing an order is what its
	 * reports and the customer's account read as "done" — FluentCart's own
	 * screen puts a confirmation on Cancel Order for the same reason. The route
	 * insists on `confirmed: true` for both, so a cached copy of an older script
	 * or a bare API call cannot make either move in one step.
	 */
	const NEEDS_CONFIRMATION = array( self::COMPLETED, self::CANCELED );

	/**
	 * @param string $axis 'order' or 'shipping'.
	 * @param string $slug Target slug.
	 * @return bool Whether moving there has to be confirmed.
	 */
	public static function needsConfirmation( $axis, $slug ) {
		return 'order' === $axis && in_array( (string) $slug, self::NEEDS_CONFIRMATION, true );
	}

	/**
	 * Everything the order-page control and the REST response need.
	 *
	 * @param int $orderId Order id.
	 * @return array<string,mixed>|null Null when the order does not exist.
	 */
	public static function state( $orderId ) {
		$orderId = (int) $orderId;
		$row     = OrderRepository::find( $orderId );

		if ( null === $row ) {
			return null;
		}

		$settings = StatusRegistry::settings();

		$out = array(
			'order_id' => $orderId,
			'axes'     => array(),
		);

		foreach ( Settings::AXES as $axis ) {
			$current = 'shipping' === $axis ? (string) $row['shipping_status'] : (string) $row['status'];

			// Computed once and handed on: `nextStep()` answers by looking for
			// the following step in this very list, and building it twice would
			// run core's whole `editable_*_statuses` filter chain a second time
			// for an answer that cannot have changed in between.
			$targets = self::targets( $orderId, $axis, $row );

			$out['axes'][ $axis ] = array(
				'current'   => $current,
				'label'     => Labels::forSlug( $axis, $current, $settings ),
				'color'     => self::colorFor( $axis, $current, $settings ),
				'step'      => Settings::pipelinePositionFor( $axis, $current, $settings ),
				'steps'     => count( Settings::pipelineFor( $axis, $settings ) ),
				'targets'   => $targets,
				'next'      => self::nextStep( $orderId, $axis, $row, $targets ),
				'locked'    => self::lockReason( $axis, $row ),
				'available' => 'shipping' !== $axis || '' !== $current,
			);
		}

		return $out;
	}

	/**
	 * The moves this order may make on one axis, in workflow order.
	 *
	 * @param int        $orderId Order id.
	 * @param string     $axis    'order' or 'shipping'.
	 * @param array|null $row     Optional pre-read order row.
	 * @return array<int,array<string,string>> `['slug','label','color']`.
	 */
	public static function targets( $orderId, $axis, array $row = null ) {
		$orderId = (int) $orderId;
		$axis    = 'shipping' === $axis ? 'shipping' : 'order';
		$row     = null === $row ? OrderRepository::find( $orderId ) : $row;

		if ( null === $row ) {
			return array();
		}

		if ( null !== self::lockReason( $axis, $row ) ) {
			return array();
		}

		$settings = StatusRegistry::settings();
		$current  = 'shipping' === $axis ? (string) $row['shipping_status'] : (string) $row['status'];
		$colors   = Labels::colors( $axis, $settings );

		$out = array();

		foreach ( self::editable( $orderId, $axis ) as $slug => $label ) {
			$slug = (string) $slug;

			// Core answers "Order already has the same status" with a 400, so
			// the option would be a guaranteed error rather than a no-op.
			if ( $slug === $current ) {
				continue;
			}

			if ( null !== self::rejectionFor( $orderId, $axis, $slug, $row ) ) {
				continue;
			}

			$out[] = array(
				'slug'    => $slug,
				'label'   => (string) $label,
				'color'   => isset( $colors[ $slug ] ) ? $colors[ $slug ] : '',
				'confirm' => self::needsConfirmation( $axis, $slug ),
			);
		}

		return $out;
	}

	/**
	 * The next workflow step, when the order is on one and there is a next.
	 *
	 * @param int        $orderId Order id.
	 * @param string     $axis    'order' or 'shipping'.
	 * @param array|null $row     Optional pre-read order row.
	 * @param array|null $targets Optional pre-computed `targets()`.
	 * @return array<string,string>|null `['slug','label','color']`.
	 */
	public static function nextStep( $orderId, $axis, array $row = null, array $targets = null ) {
		$orderId = (int) $orderId;
		$axis    = 'shipping' === $axis ? 'shipping' : 'order';
		$row     = null === $row ? OrderRepository::find( $orderId ) : $row;

		if ( null === $row ) {
			return null;
		}

		$settings = StatusRegistry::settings();
		$pipeline = Settings::pipelineFor( $axis, $settings );
		$current  = 'shipping' === $axis ? (string) $row['shipping_status'] : (string) $row['status'];

		$position = array_search( $current, $pipeline, true );

		// Only an order that is *on* the workflow has a next step. One sitting
		// on `completed`, or on a status somebody deleted, has a status to fix
		// rather than a step to advance, and guessing which step it "should"
		// be on is exactly the kind of help that writes the wrong row.
		if ( false === $position || ! isset( $pipeline[ $position + 1 ] ) ) {
			return null;
		}

		$next = (string) $pipeline[ $position + 1 ];

		$targets = null === $targets ? self::targets( $orderId, $axis, $row ) : $targets;

		foreach ( $targets as $target ) {
			if ( $target['slug'] === $next ) {
				return $target;
			}
		}

		return null;
	}

	/**
	 * Why this move would be refused, or null when it would be accepted.
	 *
	 * @param int        $orderId Order id.
	 * @param string     $axis    'order' or 'shipping'.
	 * @param string     $slug    Requested slug.
	 * @param array|null $row     Optional pre-read order row.
	 * @return string|null Human-readable reason.
	 */
	public static function rejectionFor( $orderId, $axis, $slug, array $row = null ) {
		$orderId = (int) $orderId;
		$axis    = 'shipping' === $axis ? 'shipping' : 'order';
		$slug    = (string) $slug;
		$row     = null === $row ? OrderRepository::find( $orderId ) : $row;

		if ( null === $row ) {
			return __( 'That order no longer exists.', 'ys-fluentcart-order-statuses' );
		}

		$locked = self::lockReason( $axis, $row );

		if ( null !== $locked ) {
			return $locked;
		}

		// The two order-axis rules are asked FIRST, and the order matters.
		// Both of them also narrow `editable_order_statuses` for this order, so
		// by the time the allow-list is consulted the status they object to has
		// already been removed from it — and the generic "not a status this
		// order can be moved to" would be the only thing the operator ever saw.
		// The specific sentence, naming the status and the reason, is the whole
		// point of refusing in words rather than by omission.
		if ( 'order' === $axis ) {
			// FluentCart offers Mark As Complete only on an order that has been
			// paid. The control follows the same rule rather than finishing an
			// order nobody has paid for, and says so in the words the payment
			// condition of a custom status already uses.
			if ( self::COMPLETED === $slug && ! OrderRepository::isPaid( isset( $row['payment_status'] ) ? $row['payment_status'] : '' ) ) {
				return sprintf(
					/* translators: %s: status label */
					__( '“%s” can only be used on orders that have been paid. This order has not been paid yet.', 'ys-fluentcart-order-statuses' ),
					Labels::forSlug( 'order', self::COMPLETED, StatusRegistry::settings() )
				);
			}

			$requirement = RequirementGuard::rejectionReason( $slug, $orderId );

			if ( null !== $requirement ) {
				return $requirement;
			}

			if ( Settings::pipelineStrict( StatusRegistry::settings() ) ) {
				$strict = StrictGuard::rejectionReason( (string) $row['status'], $slug );

				if ( null !== $strict ) {
					return $strict;
				}
			}
		}

		// Whatever is left: core does not offer this slug for this axis at all
		// — it is not a status, or it is one the operator marked "not
		// selectable in the admin".
		//
		// The shipping axis reaches here directly, because it carries neither
		// order-axis rule: `payment_requirement` is a question about money and
		// `pipeline_strict` is defined over the order-status workflow. Core has
		// nothing else to say about a shipping change either, which is exactly
		// why a post-payment workflow belongs on this axis.
		$editable = self::editable( $orderId, $axis );

		if ( ! isset( $editable[ $slug ] ) ) {
			return sprintf(
				/* translators: %s: status slug */
				__( '“%s” is not a status this order can be moved to.', 'ys-fluentcart-order-statuses' ),
				$slug
			);
		}

		return null;
	}

	/**
	 * Why this whole axis is closed for this order, or null when it is open.
	 *
	 * @param string $axis 'order' or 'shipping'.
	 * @param array  $row  Order row.
	 * @return string|null
	 */
	public static function lockReason( $axis, array $row ) {
		if ( 'shipping' === $axis ) {
			// A digital order's shipping column is an empty string, not
			// `unshipped`: it has no fulfilment state and inventing one would
			// put a shipping status on something that is never shipped.
			return '' === (string) $row['shipping_status']
				? __( 'This order has nothing to ship, so it has no shipping status.', 'ys-fluentcart-order-statuses' )
				: null;
		}

		// `OrderResource::updateStatuses()` refuses every order-status change
		// once the order is canceled. Reproduced here so the control can say so
		// rather than offering moves that would all come back as a 400.
		return self::CANCELED === (string) $row['status']
			? __( 'This order has been canceled, and FluentCart does not allow the order status to be changed afterwards.', 'ys-fluentcart-order-statuses' )
			: null;
	}

	/**
	 * Core's allow-list for one axis, narrowed to the order in flight.
	 *
	 * @param int    $orderId Order id.
	 * @param string $axis    'order' or 'shipping'.
	 * @return array<string,string> slug => label.
	 */
	private static function editable( $orderId, $axis ) {
		$status = '\\FluentCart\\App\\Helpers\\Status';
		$method = 'shipping' === $axis ? 'getEditableShippingStatuses' : 'getEditableOrderStatuses';

		// Point the request-scoped order context at this order for the duration
		// of the call. Without it the two `editable_order_statuses` narrowing
		// passes see no order and return the unfiltered list — which is correct
		// for an admin page load and wrong for this question.
		$previous = OrderContext::orderId();
		OrderContext::set( (int) $orderId );

		try {
			if ( class_exists( $status ) && method_exists( $status, $method ) ) {
				$statuses = $status::$method();
			} else {
				$statuses = apply_filters(
					'shipping' === $axis ? 'fluent_cart/editable_shipping_statuses' : 'fluent_cart/editable_order_statuses',
					Labels::builtin()[ $axis ],
					array()
				);
			}
		} catch ( \Throwable $e ) {
			$statuses = array();
		}

		OrderContext::set( $previous );

		return is_array( $statuses ) ? $statuses : array();
	}

	/**
	 * @param string $axis     Axis.
	 * @param string $slug     Slug.
	 * @param array  $settings Normalised settings.
	 * @return string
	 */
	private static function colorFor( $axis, $slug, array $settings ) {
		$colors = Labels::colors( $axis, $settings );

		return isset( $colors[ $slug ] ) ? $colors[ $slug ] : '';
	}
}
