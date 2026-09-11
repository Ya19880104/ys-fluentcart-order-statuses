<?php
/**
 * The one-click "paid → in production → shipment scheduled → shipped" pipeline.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Pipeline;

use YangSheep\FluentCart\OrderStatuses\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A starting point, not a schema — and now two of them.
 *
 * Nothing here is written on activation. The operator presses a button, gets
 * the steps, and is then free to rename, recolour, reorder, extend or delete
 * any of them — after which this class has no further say. That matters because
 * the slugs end up in `wp_fct_orders`: a template that rewrote itself on every
 * update would be rewriting order history.
 *
 * Applying either template twice is a no-op. Steps whose slug already exists
 * are left exactly as they are (the operator's colour and label win), and a
 * built-in label the operator has already overridden is never re-overridden.
 *
 * **Which one to press.** Both spell the same workflow — paid, in production,
 * shipment scheduled, shipped — and they differ in which column carries it:
 *
 * - `orderSteps()` puts it on `status`. That column is rewritten to
 *   `processing` by the payment code every time money arrives, which is what
 *   `Payment\RestoreHandler` exists to undo, and FluentCart 1.6.3 offers no
 *   order-status control at all on a paid order, which is what this plugin's
 *   own control exists to supply.
 * - `shippingSteps()` puts it on `shipping_status`. Nothing in FluentCart ever
 *   writes that column by itself, and FluentCart's own "Change Shipping Status"
 *   dialog drives it on a paid order with no help from us.
 *
 * For a workflow that begins *after* payment — which is what a production
 * schedule is — the shipping axis is the one to use, and the README says so.
 */
final class Template {

	/**
	 * The three custom order statuses, in pipeline order.
	 *
	 * Step 0 is `processing`, which FluentCart already owns — see
	 * `Settings::PIPELINE_ENTRY`. The template only relabels it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function orderSteps() {
		return array(
			array(
				'slug'                   => 'in_production',
				'label'                  => __( 'In production', 'ys-fluentcart-order-statuses' ),
				'color'                  => '#b45309',
				'description'            => __( 'Paid, and the goods are being made.', 'ys-fluentcart-order-statuses' ),
				'editable'               => true,
				'enabled'                => true,
				'payment_requirement'    => 'paid_only',
				'on_payment'             => 'keep',
				'linked_shipping_status' => '',
				'sort_order'             => 10,
			),
			array(
				'slug'                   => 'ship_scheduled',
				'label'                  => __( 'Shipment scheduled', 'ys-fluentcart-order-statuses' ),
				'color'                  => '#2563eb',
				'description'            => __( 'Made, and booked onto a shipment.', 'ys-fluentcart-order-statuses' ),
				'editable'               => true,
				'enabled'                => true,
				'payment_requirement'    => 'paid_only',
				'on_payment'             => 'keep',
				'linked_shipping_status' => '',
				'sort_order'             => 20,
			),
			array(
				'slug'                   => 'shipped_done',
				'label'                  => __( 'Shipped', 'ys-fluentcart-order-statuses' ),
				'color'                  => '#15803d',
				'description'            => __( 'Gone. Sets the shipping status to Shipped as well.', 'ys-fluentcart-order-statuses' ),
				'editable'               => true,
				'enabled'                => true,
				'payment_requirement'    => 'paid_only',
				'on_payment'             => 'keep',
				// The whole reason `linked_shipping_status` exists: the last
				// step of an order-status pipeline is also a fulfilment fact,
				// and staff should not have to remember to set it twice.
				'linked_shipping_status' => 'shipped',
				'sort_order'             => 30,
			),
		);
	}

	/**
	 * Built-in labels the template suggests, so the operator sees one vocabulary.
	 *
	 * `processing` is the important one: core writes it on payment, and calling
	 * it "Processing" next to a step called "In production" is exactly the
	 * confusion this template exists to remove.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function overrides() {
		return array(
			'order'    => array(
				'processing' => array( 'label' => __( 'Paid', 'ys-fluentcart-order-statuses' ), 'color' => '#0f766e' ),
				'completed'  => array( 'label' => __( 'Completed', 'ys-fluentcart-order-statuses' ), 'color' => '' ),
				'on-hold'    => array( 'label' => __( 'Awaiting payment', 'ys-fluentcart-order-statuses' ), 'color' => '' ),
				'canceled'   => array( 'label' => __( 'Canceled', 'ys-fluentcart-order-statuses' ), 'color' => '' ),
			),
			'payment'  => array(
				'pending'  => array( 'label' => __( 'Awaiting payment', 'ys-fluentcart-order-statuses' ), 'color' => '' ),
				'paid'     => array( 'label' => __( 'Paid', 'ys-fluentcart-order-statuses' ), 'color' => '' ),
				'refunded' => array( 'label' => __( 'Refunded', 'ys-fluentcart-order-statuses' ), 'color' => '' ),
			),
			'shipping' => array(
				'unshipped' => array( 'label' => __( 'Not shipped', 'ys-fluentcart-order-statuses' ), 'color' => '' ),
				'shipped'   => array( 'label' => __( 'Shipped', 'ys-fluentcart-order-statuses' ), 'color' => '#15803d' ),
				'delivered' => array( 'label' => __( 'Delivered', 'ys-fluentcart-order-statuses' ), 'color' => '' ),
			),
		);
	}

	/**
	 * The fulfilment workflow, on the axis nothing overwrites.
	 *
	 * Two custom shipping statuses, and then FluentCart's own `shipped` — which
	 * is deliberately not redefined here. `shipped` is the value
	 * `OrderResource::updateStatuses()` checks when it decides whether to set
	 * each physical line item's `fulfilled_quantity`, so a custom "shipped"
	 * slug beside it would look identical to staff and leave every order half
	 * fulfilled in core's own bookkeeping.
	 *
	 * The slugs are the same two the order-axis template uses. That is not a
	 * clash: the axes are independent columns, `Settings::sanitize()` validates
	 * them separately, and a shop that applies both templates gets the same
	 * word for the same step on both — which is the only sane outcome, and the
	 * one `Support\LabelTagger` can render without ambiguity.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function shippingSteps() {
		return array(
			array(
				'slug'        => 'in_production',
				'label'       => __( 'In production', 'ys-fluentcart-order-statuses' ),
				'color'       => '#b45309',
				'description' => __( 'Paid, and the goods are being made.', 'ys-fluentcart-order-statuses' ),
				'editable'    => true,
				'enabled'     => true,
				'sort_order'  => 10,
			),
			array(
				'slug'        => 'ship_scheduled',
				'label'       => __( 'Shipment scheduled', 'ys-fluentcart-order-statuses' ),
				'color'       => '#2563eb',
				'description' => __( 'Made, and booked onto a shipment.', 'ys-fluentcart-order-statuses' ),
				'editable'    => true,
				'enabled'     => true,
				'sort_order'  => 20,
			),
		);
	}

	/**
	 * Built-in labels the fulfilment template suggests.
	 *
	 * `unshipped` is the one that matters: it is where every physical order
	 * starts, so on this axis it means "paid, waiting to be made" rather than
	 * the bare fact that nothing has been posted yet. `paid` and `processing`
	 * are relabelled too, so the order header beside this workflow reads
	 * "Paid" rather than "Processing" while the workflow itself runs.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function shippingOverrides() {
		return array(
			'order'    => array(
				'processing' => array( 'label' => __( 'Paid', 'ys-fluentcart-order-statuses' ), 'color' => '#0f766e' ),
				'completed'  => array( 'label' => __( 'Completed', 'ys-fluentcart-order-statuses' ), 'color' => '' ),
			),
			'payment'  => array(
				'paid'    => array( 'label' => __( 'Paid', 'ys-fluentcart-order-statuses' ), 'color' => '' ),
				'pending' => array( 'label' => __( 'Awaiting payment', 'ys-fluentcart-order-statuses' ), 'color' => '' ),
			),
			'shipping' => array(
				'unshipped' => array( 'label' => __( 'Awaiting production', 'ys-fluentcart-order-statuses' ), 'color' => '#64748b' ),
				'shipped'   => array( 'label' => __( 'Shipped', 'ys-fluentcart-order-statuses' ), 'color' => '#15803d' ),
				'delivered' => array( 'label' => __( 'Delivered', 'ys-fluentcart-order-statuses' ), 'color' => '' ),
			),
		);
	}

	/**
	 * Merge the order-axis template into the stored settings.
	 *
	 * @param array $settings Normalised settings to merge into.
	 * @return array{settings:array,added:string[],skipped:string[],relabelled:string[]}
	 */
	public static function mergeInto( array $settings ) {
		return self::merge( $settings, 'order', self::orderSteps(), self::overrides() );
	}

	/**
	 * Merge the shipping-axis template into the stored settings.
	 *
	 * @param array $settings Normalised settings to merge into.
	 * @return array{settings:array,added:string[],skipped:string[],relabelled:string[]}
	 */
	public static function mergeShippingInto( array $settings ) {
		return self::merge( $settings, 'shipping', self::shippingSteps(), self::shippingOverrides() );
	}

	/**
	 * @param array  $settings  Normalised settings to merge into.
	 * @param string $axis      'order' or 'shipping'.
	 * @param array  $steps     Step definitions for that axis.
	 * @param array  $overrides Built-in label suggestions.
	 * @return array{settings:array,added:string[],skipped:string[],relabelled:string[]}
	 */
	private static function merge( array $settings, $axis, array $steps, array $overrides ) {
		$existing = array();

		foreach ( $settings[ $axis ] as $definition ) {
			$existing[] = $definition['slug'];
		}

		$added   = array();
		$skipped = array();

		// Appended rather than inserted: the operator may already have their own
		// steps, and pushing ours in front of them would silently reorder a
		// pipeline that is already in use.
		$nextSort = 0;

		foreach ( $settings[ $axis ] as $definition ) {
			$nextSort = max( $nextSort, (int) $definition['sort_order'] );
		}

		foreach ( $steps as $step ) {
			if ( in_array( $step['slug'], $existing, true ) ) {
				$skipped[] = $step['slug'];
				continue;
			}

			$nextSort         += 10;
			$step['sort_order'] = $nextSort;

			$settings[ $axis ][] = $step;
			$added[]             = $step['slug'];
		}

		$relabelled = array();

		foreach ( $overrides as $overrideAxis => $entries ) {
			foreach ( $entries as $slug => $override ) {
				if ( '' === $override['label'] ) {
					continue;
				}

				// Never overwrite a label the operator chose themselves.
				if ( ! empty( $settings['overrides'][ $overrideAxis ][ $slug ]['label'] ) ) {
					continue;
				}

				$settings['overrides'][ $overrideAxis ][ $slug ] = $override;
				$relabelled[]                                    = $overrideAxis . ':' . $slug;
			}
		}

		return array(
			'settings'   => Settings::sanitize( $settings ),
			'added'      => $added,
			'skipped'    => $skipped,
			'relabelled' => $relabelled,
		);
	}
}
