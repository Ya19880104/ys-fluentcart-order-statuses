<?php
/**
 * "One step at a time" — the optional strict pipeline.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Pipeline;

use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\OrderContext;
use YangSheep\FluentCart\OrderStatuses\Support\OrderRepository;
use YangSheep\FluentCart\OrderStatuses\Support\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Off by default, and narrow when it is on.
 *
 * With `pipeline_strict` enabled, an order that is *on* a pipeline step may only
 * move to the step immediately before or after it. Skipping — "in production"
 * straight to "shipped" — is refused with a message naming the step that was
 * missed, because the point of the pipeline is that each step corresponds to
 * work that really happened.
 *
 * Three things it deliberately does not do:
 *
 * - It never blocks a move *out of* the pipeline. Cancelling, completing or
 *   putting an order on hold has to work from anywhere; a workflow rule that
 *   can trap an order is worse than no rule.
 * - It never blocks a move *into* the pipeline from outside it. An order sitting
 *   on `on-hold` or `completed` can be placed on whichever step matches reality.
 *   `payment_requirement` is what keeps unpaid orders out, and that is a
 *   separate question with its own guard.
 * - It does not reorder anything. The pipeline is whatever order the operator
 *   put the statuses in on the settings screen.
 *
 * Enforced in the same two layers as `Payment\RequirementGuard`: a 422 from
 * `rest_pre_dispatch` (what the operator sees) and a narrowed
 * `editable_order_statuses` for the order in flight (what core's own write-side
 * allow-list sees).
 */
final class StrictGuard {

	/** After `RequirementGuard` (9): "this order is not paid" is the better message. */
	const VETO_PRIORITY = 10;

	/** Alongside the requirement guard's own narrowing pass. */
	const FILTER_PRIORITY = 30;

	/**
	 * @return void
	 */
	public function register() {
		add_filter( 'rest_pre_dispatch', array( $this, 'vetoRestChange' ), self::VETO_PRIORITY, 3 );
		add_filter( 'fluent_cart/editable_order_statuses', array( $this, 'filterEditable' ), self::FILTER_PRIORITY );
	}

	/**
	 * @param mixed $result  Short-circuit value; non-null skips the controller.
	 * @param mixed $server  REST server.
	 * @param mixed $request REST request.
	 * @return mixed
	 */
	public function vetoRestChange( $result, $server, $request ) {
		unset( $server );

		if ( null !== $result || ! Settings::pipelineStrict( StatusRegistry::settings() ) ) {
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

		// Before FluentCart's permission check, like the requirement veto: a
		// stranger gets FluentCart's own 401 / 403, never the workflow step.
		if ( ! Permissions::canChangeOrderStatuses() ) {
			return $result;
		}

		$row = OrderRepository::find( $orderId );

		if ( null === $row ) {
			return $result;
		}

		$problem = self::rejectionReason( (string) $row['status'], $newStatus );

		if ( null === $problem ) {
			return $result;
		}

		return new \WP_Error( 'ys_fct_status_pipeline_strict', $problem, array( 'status' => 422 ) );
	}

	/**
	 * Drop the steps this order may not jump to.
	 *
	 * @param mixed $statuses Slug => label.
	 * @return array
	 */
	public function filterEditable( $statuses ) {
		$statuses = is_array( $statuses ) ? $statuses : array();

		if ( ! Settings::pipelineStrict( StatusRegistry::settings() ) ) {
			return $statuses;
		}

		$orderId = OrderContext::orderId();

		if ( $orderId <= 0 ) {
			return $statuses;
		}

		$row = OrderRepository::find( $orderId );

		if ( null === $row ) {
			return $statuses;
		}

		$current = (string) $row['status'];

		foreach ( array_keys( $statuses ) as $slug ) {
			if ( $slug !== $current && null !== self::rejectionReason( $current, (string) $slug ) ) {
				unset( $statuses[ $slug ] );
			}
		}

		return $statuses;
	}

	/**
	 * Why this transition is refused, or null when it is allowed.
	 *
	 * @param string $from Current order status.
	 * @param string $to   Requested order status.
	 * @return string|null Human-readable reason.
	 */
	public static function rejectionReason( $from, $to ) {
		if ( $from === $to ) {
			return null;
		}

		$settings = StatusRegistry::settings();
		$pipeline = Settings::pipeline( $settings );

		$fromPos = array_search( $from, $pipeline, true );
		$toPos   = array_search( $to, $pipeline, true );

		// Leaving the pipeline, or arriving from outside it, is always allowed.
		if ( false === $fromPos || false === $toPos ) {
			return null;
		}

		if ( abs( (int) $toPos - (int) $fromPos ) <= 1 ) {
			return null;
		}

		$expected = (int) $toPos > (int) $fromPos ? (int) $fromPos + 1 : (int) $fromPos - 1;

		return sprintf(
			/* translators: 1: current status label, 2: requested status label, 3: the adjacent step's label */
			__( 'The order workflow runs one step at a time. This order is on “%1$s”, so it cannot move straight to “%2$s” — the next step is “%3$s”. Turn off strict order workflow on the Order Statuses screen to allow skipping.', 'ys-fluentcart-order-statuses' ),
			self::labelFor( $from, $settings ),
			self::labelFor( $to, $settings ),
			self::labelFor( $pipeline[ $expected ], $settings )
		);
	}

	/**
	 * @param string $slug     Order status slug.
	 * @param array  $settings Normalised settings.
	 * @return string
	 */
	private static function labelFor( $slug, array $settings ) {
		$custom = Settings::customStatuses( 'order', $settings );

		if ( isset( $custom[ $slug ] ) ) {
			return $custom[ $slug ]['label'];
		}

		if ( ! empty( $settings['overrides']['order'][ $slug ]['label'] ) ) {
			return $settings['overrides']['order'][ $slug ]['label'];
		}

		return $slug;
	}
}
