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
 * A starting point, not a schema.
 *
 * Nothing here is written on activation. The operator presses a button, gets
 * these four steps, and is then free to rename, recolour, reorder, extend or
 * delete any of them — after which this class has no further say. That matters
 * because the slugs end up in `wp_fct_orders.status`: a template that rewrote
 * itself on every update would be rewriting order history.
 *
 * Applying it twice is a no-op. Steps whose slug already exists are left
 * exactly as they are (the operator's colour and label win), and a built-in
 * label the operator has already overridden is never re-overridden.
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
	 * Merge the template into the stored settings.
	 *
	 * @param array $settings Normalised settings to merge into.
	 * @return array{settings:array,added:string[],skipped:string[],relabelled:string[]}
	 */
	public static function mergeInto( array $settings ) {
		$existing = array();

		foreach ( $settings['order'] as $definition ) {
			$existing[] = $definition['slug'];
		}

		$added   = array();
		$skipped = array();

		// Appended rather than inserted: the operator may already have their own
		// steps, and pushing our three in front of them would silently reorder a
		// pipeline that is already in use.
		$nextSort = 0;

		foreach ( $settings['order'] as $definition ) {
			$nextSort = max( $nextSort, (int) $definition['sort_order'] );
		}

		foreach ( self::orderSteps() as $step ) {
			if ( in_array( $step['slug'], $existing, true ) ) {
				$skipped[] = $step['slug'];
				continue;
			}

			$nextSort         += 10;
			$step['sort_order'] = $nextSort;

			$settings['order'][] = $step;
			$added[]             = $step['slug'];
		}

		$relabelled = array();

		foreach ( self::overrides() as $axis => $overrides ) {
			foreach ( $overrides as $slug => $override ) {
				if ( '' === $override['label'] ) {
					continue;
				}

				// Never overwrite a label the operator chose themselves.
				if ( ! empty( $settings['overrides'][ $axis ][ $slug ]['label'] ) ) {
					continue;
				}

				$settings['overrides'][ $axis ][ $slug ] = $override;
				$relabelled[]                            = $axis . ':' . $slug;
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
