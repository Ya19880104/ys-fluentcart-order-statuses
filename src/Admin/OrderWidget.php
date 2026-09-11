<?php
/**
 * The "Order workflow" control and the "Status history" panel on an order page.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Admin;

use YangSheep\FluentCart\OrderStatuses\History\HistoryRepository;
use YangSheep\FluentCart\OrderStatuses\Pipeline\Changer;
use YangSheep\FluentCart\OrderStatuses\Rest\StatusController;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\AdminScreen;
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
 * so two things follow and both matter:
 *
 * 1. Every value below goes through `esc_html()` / `esc_attr()`. A status label
 *    is operator input, an actor name is a WordPress display name, and both end
 *    up in this string.
 * 2. **A `<script>` in that string never runs.** `innerHTML` does not execute
 *    script nodes, so the control below is inert markup and the behaviour lives
 *    in `assets/admin/order-changer.js`, enqueued on FluentCart's own screens
 *    and listening through one delegated handler on `document`. That also means
 *    the control keeps working when the SPA re-renders the widget, which it
 *    does on every navigation back to the order.
 *
 * Two entries are contributed, in this order:
 *
 * - **Order workflow** — the control. Where the order stands on both axes, the
 *   moves it may make, and a one-click "next step". It exists because FluentCart
 *   1.6.3 offers no order-status control at all on a *paid* order; see
 *   `Rest\ChangeController` for what was measured.
 * - **Status history** — 0.2's timeline, unchanged, and still only when there
 *   is something to show.
 */
final class OrderWidget {

	/** Rows shown before the panel gives up and points at the report. */
	const MAX_ROWS = 40;

	const HANDLE = 'ys-fct-status-changer';

	/**
	 * @return void
	 */
	public function register() {
		add_filter( 'fluent_cart/widgets/single_order_page', array( $this, 'addWidget' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 100 );
	}

	/**
	 * Load the control's script and styles on FluentCart's own admin screens.
	 *
	 * Not only on the order page: the admin is a single-page app, so there is
	 * no page load when the operator opens an order. The script is a few
	 * kilobytes and does nothing at all until a widget with `[data-ys-changer]`
	 * appears in the DOM.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ! AdminScreen::isFluentCart( $hook ) || ! Permissions::canManage() ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			YS_FCT_STATUS_URL . 'assets/admin/order-changer.css',
			array(),
			YS_FCT_STATUS_VERSION
		);

		wp_enqueue_script(
			self::HANDLE,
			YS_FCT_STATUS_URL . 'assets/admin/order-changer.js',
			array(),
			YS_FCT_STATUS_VERSION,
			true
		);

		wp_localize_script(
			self::HANDLE,
			'ysFctStatusChanger',
			array(
				'restUrl'   => esc_url_raw( rest_url( StatusController::NAMESPACE_V1 . '/' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'working' => __( 'Changing…', 'ys-fluentcart-order-statuses' ),
					'failed'  => __( 'The status could not be changed.', 'ys-fluentcart-order-statuses' ),
					'pick'    => __( 'Choose a status first.', 'ys-fluentcart-order-statuses' ),
				),
			)
		);
	}

	/**
	 * @param mixed $widgets Widgets so far.
	 * @param mixed $data    `['order_id' => int, 'order' => Order]`.
	 * @return array
	 */
	public function addWidget( $widgets, $data = array() ) {
		$widgets = is_array( $widgets ) ? $widgets : array();

		// The route itself is gated on `customers/view` OR `orders/view`; these
		// panels are order history and order workflow, so they need the order
		// half specifically.
		if ( ! Permissions::canViewOrders() ) {
			return $widgets;
		}

		$orderId = 0;

		if ( is_array( $data ) && ! empty( $data['order_id'] ) ) {
			$orderId = (int) $data['order_id'];
		} elseif ( is_array( $data ) && ! empty( $data['order'] ) && is_object( $data['order'] ) ) {
			$orderId = (int) $data['order']->id;
		}

		if ( $orderId <= 0 ) {
			return $widgets;
		}

		// Reading history is one bar; writing a status is a higher one, and the
		// control is not rendered at all for a role that could not use it.
		if ( Permissions::canManage() ) {
			$state = Changer::state( $orderId );

			if ( null !== $state ) {
				$widgets[] = array(
					'type'     => 'html',
					'title'    => __( 'Order workflow', 'ys-fluentcart-order-statuses' ),
					'subtitle' => __( 'Move this order along the workflow. The list offers only the moves this order is allowed to make.', 'ys-fluentcart-order-statuses' ),
					'use_card' => true,
					'content'  => self::renderChanger( $state ),
				);
			}
		}

		if ( ! Schema::tableExists() ) {
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
	 * The status control.
	 *
	 * @param array $state `Pipeline\Changer::state()`.
	 * @return string Escaped HTML.
	 */
	public static function renderChanger( array $state ) {
		$orderId = (int) $state['order_id'];

		$out = '<div class="ys-fct-changer" data-ys-changer data-ys-order="' . esc_attr( (string) $orderId ) . '">';

		foreach ( array( 'order', 'shipping' ) as $axis ) {
			$out .= self::renderAxis( $orderId, $axis, $state['axes'][ $axis ] );
		}

		$out .= '<p class="ys-fct-changer-message" data-ys-changer-message role="status" aria-live="polite"></p>';
		$out .= '</div>';

		return $out;
	}

	/**
	 * @param int    $orderId Order id.
	 * @param string $axis    'order' or 'shipping'.
	 * @param array  $axisState One entry of `Changer::state()['axes']`.
	 * @return string
	 */
	private static function renderAxis( $orderId, $axis, array $axisState ) {
		$fieldId = 'ys-fct-changer-' . $axis . '-' . (int) $orderId;
		$color   = '' === $axisState['color'] ? '#64748b' : $axisState['color'];

		$out = '<div class="ys-fct-changer-axis" data-ys-axis="' . esc_attr( $axis ) . '">';

		$out .= '<p class="ys-fct-changer-head">'
			. '<span class="ys-fct-changer-axis-name">' . esc_html( self::axisName( $axis ) ) . '</span>'
			. '<span class="ys-fct-changer-badge" style="background:' . esc_attr( $color ) . '">'
			. esc_html( '' === $axisState['label'] ? self::emptyLabel( $axis ) : $axisState['label'] )
			. '</span>';

		if ( $axisState['step'] >= 0 ) {
			$out .= '<span class="ys-fct-changer-step">' . esc_html(
				sprintf(
					/* translators: 1: step number, 2: how many steps the workflow has */
					__( 'step %1$d of %2$d', 'ys-fluentcart-order-statuses' ),
					(int) $axisState['step'] + 1,
					(int) $axisState['steps']
				)
			) . '</span>';
		}

		$out .= '</p>';

		if ( null !== $axisState['locked'] ) {
			$out .= '<p class="ys-fct-changer-locked">' . esc_html( $axisState['locked'] ) . '</p></div>';

			return $out;
		}

		if ( empty( $axisState['targets'] ) ) {
			$out .= '<p class="ys-fct-changer-locked">' . esc_html__( 'There is nowhere for this order to move on this axis.', 'ys-fluentcart-order-statuses' ) . '</p></div>';

			return $out;
		}

		$out .= '<p class="ys-fct-changer-row">'
			. '<label class="screen-reader-text" for="' . esc_attr( $fieldId ) . '">'
			. esc_html( self::moveLabel( $axis ) )
			. '</label>'
			. '<select id="' . esc_attr( $fieldId ) . '" class="ys-fct-changer-select" data-ys-changer-select="' . esc_attr( $axis ) . '">'
			. '<option value="">' . esc_html__( 'Move to…', 'ys-fluentcart-order-statuses' ) . '</option>';

		foreach ( $axisState['targets'] as $target ) {
			$out .= '<option value="' . esc_attr( $target['slug'] ) . '">' . esc_html( $target['label'] ) . '</option>';
		}

		$out .= '</select>'
			. '<button type="button" class="button ys-fct-changer-go" data-ys-changer-go="' . esc_attr( $axis ) . '">'
			. esc_html__( 'Change', 'ys-fluentcart-order-statuses' )
			. '</button>';

		if ( is_array( $axisState['next'] ) ) {
			$out .= '<button type="button" class="button button-primary ys-fct-changer-next"'
				. ' data-ys-changer-next="' . esc_attr( $axis ) . '"'
				. ' data-ys-changer-status="' . esc_attr( $axisState['next']['slug'] ) . '">'
				. esc_html(
					sprintf(
						/* translators: %s: the next workflow step's label */
						__( 'Next step: %s →', 'ys-fluentcart-order-statuses' ),
						$axisState['next']['label']
					)
				)
				. '</button>';
		}

		$out .= '</p></div>';

		return $out;
	}

	/**
	 * @param string $axis Axis key.
	 * @return string
	 */
	private static function moveLabel( $axis ) {
		return 'shipping' === $axis
			? __( 'Move the shipping status to', 'ys-fluentcart-order-statuses' )
			: __( 'Move the order status to', 'ys-fluentcart-order-statuses' );
	}

	/**
	 * @param string $axis Axis key.
	 * @return string
	 */
	private static function emptyLabel( $axis ) {
		return 'shipping' === $axis
			? __( 'nothing to ship', 'ys-fluentcart-order-statuses' )
			: __( 'no status', 'ys-fluentcart-order-statuses' );
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
