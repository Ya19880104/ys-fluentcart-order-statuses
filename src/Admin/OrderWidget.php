<?php
/**
 * The "Status history" panel on a FluentCart order page.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Admin;

use YangSheep\FluentCart\OrderStatuses\History\HistoryRepository;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\Labels;
use YangSheep\FluentCart\OrderStatuses\Support\Permissions;
use YangSheep\FluentCart\OrderStatuses\Support\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `fluent_cart/widgets/single_order_page`, the one real slot in the order view.
 *
 * FluentCart's `DynamicTemplates` component fetches
 * `GET widgets?filter=single_order_page&data[order_id]=…` and renders whatever
 * comes back. A `type: html` widget's `content` is injected with `innerHTML`,
 * so every value below goes through `esc_html()` — a status label is operator
 * input, an actor name is a WordPress display name, and both end up in this
 * string.
 *
 * What it shows that the order's activity feed does not: the *duration* of each
 * stay, which is the number a production schedule is actually run on, and both
 * axes interleaved on one timeline.
 */
final class OrderWidget {

	/** Rows shown before the panel gives up and points at the report. */
	const MAX_ROWS = 40;

	/**
	 * @return void
	 */
	public function register() {
		add_filter( 'fluent_cart/widgets/single_order_page', array( $this, 'addWidget' ), 20, 2 );
	}

	/**
	 * @param mixed $widgets Widgets so far.
	 * @param mixed $data    `['order_id' => int, 'order' => Order]`.
	 * @return array
	 */
	public function addWidget( $widgets, $data = array() ) {
		$widgets = is_array( $widgets ) ? $widgets : array();

		// The route itself is gated on `customers/view` OR `orders/view`; this
		// panel is order history, so it needs the order half specifically.
		if ( ! Permissions::canViewOrders() ) {
			return $widgets;
		}

		$orderId = 0;

		if ( is_array( $data ) && ! empty( $data['order_id'] ) ) {
			$orderId = (int) $data['order_id'];
		} elseif ( is_array( $data ) && ! empty( $data['order'] ) && is_object( $data['order'] ) ) {
			$orderId = (int) $data['order']->id;
		}

		if ( $orderId <= 0 || ! Schema::tableExists() ) {
			return $widgets;
		}

		$rows = HistoryRepository::forOrder( $orderId, '', self::MAX_ROWS );

		if ( empty( $rows ) ) {
			return $widgets;
		}

		$widgets[] = array(
			'type'     => 'html',
			'title'    => __( 'Status history', 'ys-fluentcart-order-statuses' ),
			'subtitle' => __( 'Every order and shipping status this order has been through, and how long it stayed.', 'ys-fluentcart-order-statuses' ),
			'use_card' => true,
			'content'  => self::render( $rows ),
		);

		return $widgets;
	}

	/**
	 * @param array $rows History rows, oldest first.
	 * @return string Escaped HTML.
	 */
	public static function render( array $rows ) {
		$settings = StatusRegistry::settings();

		$labels = array(
			'order'    => Labels::resolved( 'order', $settings ),
			'shipping' => Labels::resolved( 'shipping', $settings ),
		);

		$colors = array(
			'order'    => Labels::colors( 'order', $settings ),
			'shipping' => Labels::colors( 'shipping', $settings ),
		);

		// The stay that ends a row is the gap to the next row *on the same
		// axis*: an order status and a shipping status run in parallel, and
		// measuring one against the other would produce nonsense.
		$nextOnAxis = self::nextTimestamps( $rows );

		$out = '<ul class="ys-fct-status-timeline" style="margin:0;padding:0;list-style:none">';

		foreach ( array_reverse( $rows ) as $row ) {
			$axis  = 'shipping' === $row['axis'] ? 'shipping' : 'order';
			$slug  = (string) $row['new_status'];
			$from  = (string) $row['old_status'];
			$color = isset( $colors[ $axis ][ $slug ] ) ? $colors[ $axis ][ $slug ] : '#64748b';

			$toLabel   = isset( $labels[ $axis ][ $slug ] ) ? $labels[ $axis ][ $slug ] : $slug;
			$fromLabel = '' === $from
				? __( 'new order', 'ys-fluentcart-order-statuses' )
				: ( isset( $labels[ $axis ][ $from ] ) ? $labels[ $axis ][ $from ] : $from );

			$stay = self::stay( (string) $row['changed_at'], isset( $nextOnAxis[ $row['id'] ] ) ? $nextOnAxis[ $row['id'] ] : '' );

			$out .= '<li style="display:flex;gap:10px;padding:8px 0;border-bottom:1px solid rgba(0,0,0,.06)">'
				. '<span aria-hidden="true" style="flex:0 0 8px;height:8px;margin-top:6px;border-radius:50%;background:'
				. esc_attr( $color ) . '"></span>'
				. '<span style="flex:1 1 auto;min-width:0">'
				. '<strong>' . esc_html( $fromLabel ) . ' → ' . esc_html( $toLabel ) . '</strong>'
				. '<br /><span style="opacity:.7;font-size:12px">'
				. esc_html( self::axisName( $axis ) )
				. ' · ' . esc_html( self::localTime( (string) $row['changed_at'] ) )
				. ( '' === $row['changed_by'] ? '' : ' · ' . esc_html( $row['changed_by'] ) )
				. ' · ' . esc_html( $stay )
				. '</span></span></li>';
		}

		$out .= '</ul>';

		return $out;
	}

	/**
	 * @param string $axis Axis key.
	 * @return string
	 */
	private static function axisName( $axis ) {
		return 'shipping' === $axis
			? __( 'Shipping status', 'ys-fluentcart-order-statuses' )
			: __( 'Order status', 'ys-fluentcart-order-statuses' );
	}

	/**
	 * For each row, the timestamp of the next row on the same axis.
	 *
	 * @param array $rows History rows, oldest first.
	 * @return array<int,string>
	 */
	private static function nextTimestamps( array $rows ) {
		$lastSeen = array();
		$next     = array();

		foreach ( $rows as $row ) {
			$axis = 'shipping' === $row['axis'] ? 'shipping' : 'order';

			if ( isset( $lastSeen[ $axis ] ) ) {
				$next[ $lastSeen[ $axis ] ] = (string) $row['changed_at'];
			}

			$lastSeen[ $axis ] = (int) $row['id'];
		}

		return $next;
	}

	/**
	 * @param string $from GMT timestamp the status was entered.
	 * @param string $to   GMT timestamp it was left, or '' when it is current.
	 * @return string
	 */
	private static function stay( $from, $to ) {
		$start = (int) strtotime( $from . ' UTC' );

		if ( $start <= 0 ) {
			return '';
		}

		$end = '' === $to ? time() : (int) strtotime( $to . ' UTC' );

		if ( $end <= 0 ) {
			$end = time();
		}

		// Two real timestamps, never a duration in the second argument:
		// `human_time_diff( $from, $to )` treats an empty `$to` as "now", so a
		// stay of exactly zero seconds — which every scripted status change
		// produces — came out as "57 years". It floors at one minute instead.
		$human = human_time_diff( min( $start, $end ), max( $start, $end ) );

		return '' === $to
			? sprintf(
				/* translators: %s: a duration such as "2 days" */
				__( 'here for %s', 'ys-fluentcart-order-statuses' ),
				$human
			)
			: sprintf(
				/* translators: %s: a duration such as "2 days" */
				__( 'stayed %s', 'ys-fluentcart-order-statuses' ),
				$human
			);
	}

	/**
	 * @param string $gmt GMT timestamp.
	 * @return string Site-local, in the site's date and time format.
	 */
	private static function localTime( $gmt ) {
		$timestamp = (int) strtotime( $gmt . ' UTC' );

		if ( $timestamp <= 0 ) {
			return $gmt;
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
