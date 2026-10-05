<?php
/**
 * Turning a stored slug into something a human recognises.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Support;

use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two answers to two different questions, and mixing them up breaks the UI.
 *
 * `builtin()` is FluentCart's own vocabulary, hardcoded. It has to be
 * hardcoded: the obvious source, `Status::getOrderStatuses()`, is filtered by
 * this very plugin, so reading it would show the operator their own overrides
 * as though they were the defaults — and "clear this override" would then
 * appear to do nothing.
 *
 * `resolved()` is what should actually be printed: the built-in name, the
 * operator's override if there is one, and the custom statuses on top.
 */
final class Labels {

	/**
	 * FluentCart's own labels for the statuses it owns.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function builtin() {
		return array(
			'order'    => array(
				'processing' => __( 'Processing', 'ys-fluentcart-order-statuses' ),
				'completed'  => __( 'Completed', 'ys-fluentcart-order-statuses' ),
				'on-hold'    => __( 'On Hold', 'ys-fluentcart-order-statuses' ),
				'canceled'   => __( 'Canceled', 'ys-fluentcart-order-statuses' ),
				'failed'     => __( 'Failed', 'ys-fluentcart-order-statuses' ),
			),
			'payment'  => array(
				'pending'            => __( 'Pending', 'ys-fluentcart-order-statuses' ),
				'paid'               => __( 'Paid', 'ys-fluentcart-order-statuses' ),
				'partially_paid'     => __( 'Partially Paid', 'ys-fluentcart-order-statuses' ),
				'failed'             => __( 'Failed', 'ys-fluentcart-order-statuses' ),
				'refunded'           => __( 'Refunded', 'ys-fluentcart-order-statuses' ),
				'partially_refunded' => __( 'Partially Refunded', 'ys-fluentcart-order-statuses' ),
				'authorized'         => __( 'Authorized', 'ys-fluentcart-order-statuses' ),
				'payment_scheduled'  => __( 'Payment Scheduled', 'ys-fluentcart-order-statuses' ),
			),
			'shipping' => array(
				'unshipped'   => __( 'Unshipped', 'ys-fluentcart-order-statuses' ),
				'shipped'     => __( 'Shipped', 'ys-fluentcart-order-statuses' ),
				'delivered'   => __( 'Delivered', 'ys-fluentcart-order-statuses' ),
				'unshippable' => __( 'Unshippable', 'ys-fluentcart-order-statuses' ),
			),
		);
	}

	/**
	 * Every slug on one axis, with the name that should be shown for it.
	 *
	 * @param string $axis     'order', 'shipping' or 'payment'.
	 * @param array  $settings Optional pre-read settings.
	 * @return array<string,string>
	 */
	public static function resolved( $axis, ?array $settings = null ) {
		$settings = null === $settings ? StatusRegistry::settings() : $settings;
		$builtin  = self::builtin();

		$labels = isset( $builtin[ $axis ] ) ? $builtin[ $axis ] : array();

		if ( isset( $settings['overrides'][ $axis ] ) ) {
			foreach ( $settings['overrides'][ $axis ] as $slug => $override ) {
				if ( '' !== $override['label'] ) {
					$labels[ $slug ] = $override['label'];
				}
			}
		}

		if ( in_array( $axis, Settings::AXES, true ) ) {
			foreach ( Settings::customStatuses( $axis, $settings ) as $slug => $definition ) {
				$labels[ $slug ] = $definition['label'];
			}
		}

		return $labels;
	}

	/**
	 * @param string $axis     Axis.
	 * @param string $slug     Slug.
	 * @param array  $settings Optional pre-read settings.
	 * @return string The label, or the slug when nothing knows it.
	 */
	public static function forSlug( $axis, $slug, ?array $settings = null ) {
		$labels = self::resolved( $axis, $settings );

		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : (string) $slug;
	}

	/**
	 * @param string $axis     Axis.
	 * @param array  $settings Optional pre-read settings.
	 * @return array<string,string> slug => `#rrggbb`, only where one is set.
	 */
	public static function colors( $axis, ?array $settings = null ) {
		$settings = null === $settings ? StatusRegistry::settings() : $settings;
		$colors   = array();

		if ( isset( $settings['overrides'][ $axis ] ) ) {
			foreach ( $settings['overrides'][ $axis ] as $slug => $override ) {
				if ( '' !== $override['color'] ) {
					$colors[ $slug ] = $override['color'];
				}
			}
		}

		if ( in_array( $axis, Settings::AXES, true ) ) {
			foreach ( Settings::customStatuses( $axis, $settings ) as $slug => $definition ) {
				$colors[ $slug ] = $definition['color'];
			}
		}

		return $colors;
	}
}
