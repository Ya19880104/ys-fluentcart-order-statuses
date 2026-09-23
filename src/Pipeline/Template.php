<?php
/**
 * The one-click "in production → shipment scheduled → shipped" workflows.
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
 * are left exactly as they are (the operator's colour and label win).
 *
 * **What the templates do not do, since 0.5.** They do not restrict any step to
 * paid or unpaid orders — every step is offered on every order, exactly like a
 * status added by hand — and they do not rename FluentCart's built-in statuses.
 * Both used to happen, and together they made the workflow read as if it were
 * split into a "paid" list and an "unpaid" list. A shop that does want a payment
 * condition still has one, under *Advanced* on each status, and the built-in
 * labels can still be renamed on the *Built-in labels* tab.
 *
 * **Which one to press.** Both spell the same workflow — in production,
 * shipment scheduled, shipped — and they differ in which column carries it:
 *
 * - `orderSteps()` puts it on `status`. That column is rewritten to
 *   `processing` by the payment code every time money arrives, which is what
 *   `Payment\RestoreHandler` exists to undo. And FluentCart's admin has no
 *   control for choosing an order status at all — measured on 1.6.0 and 1.6.3:
 *   only fixed buttons such as *Mark As Complete* and *Cancel Order* — which is
 *   what this plugin's own order-page control exists to supply.
 * - `shippingSteps()` puts it on `shipping_status`. Nothing in FluentCart ever
 *   writes that column by itself, and FluentCart's own "Change Shipping Status"
 *   dialog lists the custom steps directly, with no help from us.
 *
 * For a workflow that begins *after* payment — which is what a production
 * schedule is — the shipping axis is the one to use, and the README says so.
 */
final class Template {

	/**
	 * The three custom order statuses, in pipeline order.
	 *
	 * Step 0 is `processing`, which FluentCart already owns — see
	 * `Settings::PIPELINE_ENTRY`. The template leaves it, and its name, alone.
	 *
	 * Every step is available on every order (`payment_requirement` = `any`),
	 * the same default a status added by hand gets. `on_payment` stays `keep`:
	 * that is not a restriction but the guard that stops FluentCart overwriting
	 * the step with `processing` when a payment lands, and it is invisible in
	 * daily use.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function orderSteps() {
		return array(
			array(
				'slug'                   => 'in_production',
				'label'                  => __( 'In production', 'ys-fluentcart-order-statuses' ),
				'color'                  => '#b45309',
				'description'            => __( 'The goods are being made.', 'ys-fluentcart-order-statuses' ),
				'editable'               => true,
				'enabled'                => true,
				'payment_requirement'    => 'any',
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
				'payment_requirement'    => 'any',
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
				'payment_requirement'    => 'any',
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
	 * Built-in labels the order template suggests: none, since 0.5.
	 *
	 * It used to rename `processing` to "Paid" and `on-hold` to "Awaiting
	 * payment", which made the order workflow read like a paid list beside an
	 * unpaid one. FluentCart's own names are now left exactly as they are; a
	 * shop that wants other words renames them on the *Built-in labels* tab.
	 * Kept as an empty map so `merge()` and anything else that calls this keep
	 * working unchanged.
	 *
	 * @return array<string,array<string,array<string,string>>>
	 */
	public static function overrides() {
		return array(
			'order'    => array(),
			'payment'  => array(),
			'shipping' => array(),
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
				'description' => __( 'The goods are being made.', 'ys-fluentcart-order-statuses' ),
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
	 * Built-in labels the fulfilment template suggests: none, since 0.5.
	 *
	 * It used to rename `unshipped` to "Awaiting production" and the payment
	 * and order statuses around it to "Paid". Same reasoning as `overrides()`:
	 * FluentCart's built-in names are left alone, and the *Built-in labels* tab
	 * is where a shop renames them if it wants to.
	 *
	 * @return array<string,array<string,array<string,string>>>
	 */
	public static function shippingOverrides() {
		return array(
			'order'    => array(),
			'payment'  => array(),
			'shipping' => array(),
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
