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
 * - **Order workflow** — where the order stands on both axes, which step of
 *   the workflow that is, and a one-click "next step". Since 0.7 any other
 *   status is chosen in FluentCart's own More Action menu: *Change Order
 *   Status*, which the script adds there because FluentCart's admin has no
 *   control for choosing an order status at all (see `Rest\ChangeController`
 *   for what was measured), and FluentCart's own *Change Shipping Status*;
 *   a line on each axis says which. The card still carries the order-status
 *   choice, hidden, as the fallback for a page where that menu entry cannot
 *   be used, and draws the shipping-status choice on screen on a canceled
 *   order, where FluentCart's menu has none; see `renderList()`.
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
	 * kilobytes and, until a widget with `[data-ys-changer]` appears in the DOM
	 * or the More Action menu is used, does nothing but notice that the DOM
	 * changed (one MutationObserver, at most one look every 100 ms).
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
					'working'          => __( 'Changing…', 'ys-fluentcart-order-statuses' ),
					'failed'           => __( 'The status could not be changed.', 'ys-fluentcart-order-statuses' ),
					'pick'             => __( 'Choose a status first.', 'ys-fluentcart-order-statuses' ),
					/* translators: %s: status label */
					'confirmCompleted' => __( 'Move this order to “%s”? This marks the order as finished.', 'ys-fluentcart-order-statuses' ),
					/* translators: %s: status label */
					'confirmCanceled'  => __( 'Move this order to “%s”? A canceled order cannot be changed afterwards: FluentCart refuses every later change to its order status.', 'ys-fluentcart-order-statuses' ),
					// v0.6 — the entry in FluentCart's own More Action menu and its dialog.
					'menuLabel'        => __( 'Change Order Status', 'ys-fluentcart-order-statuses' ),
					'dialogTitle'      => __( 'Update Order Status', 'ys-fluentcart-order-statuses' ),
					'fieldLabel'       => __( 'Order Status', 'ys-fluentcart-order-statuses' ),
					'update'           => __( 'Update', 'ys-fluentcart-order-statuses' ),
					'close'            => __( 'Close this dialog', 'ys-fluentcart-order-statuses' ),
					'loading'          => __( 'Loading…', 'ys-fluentcart-order-statuses' ),
					'loadFailed'       => __( 'The order could not be loaded. Use the status list now shown in the Order workflow card on this page instead.', 'ys-fluentcart-order-statuses' ),
					'notLoaded'        => __( 'The order could not be loaded.', 'ys-fluentcart-order-statuses' ),
					/* translators: %s: the server's reason, such as "Cookie check failed." */
					'reloadPage'       => __( '%s Reload the page and try again.', 'ys-fluentcart-order-statuses' ),
					'nowhere'          => __( 'There is nowhere for this order to move on this axis.', 'ys-fluentcart-order-statuses' ),
					'noStatus'         => __( 'no status', 'ys-fluentcart-order-statuses' ),
					'changed'          => __( 'The order status was changed.', 'ys-fluentcart-order-statuses' ),
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
					'subtitle' => __( 'Where this order stands in the workflow, and its next step. To choose any other status, use More Action → Change Order Status (or Change Shipping Status).', 'ys-fluentcart-order-statuses' ),
					'use_card' => true,
					'content'  => self::renderChanger( $state ),
				);
			}
		}

		if ( ! Schema::tableExists() ) {
			return $widgets;
		}

		// The newest rows, not the oldest: on a long-lived order the change
		// somebody is looking for is the recent one.
		$rows = HistoryRepository::latestForOrder( $orderId, self::MAX_ROWS );

		if ( empty( $rows ) ) {
			return $widgets;
		}

		$total = HistoryRepository::countForOrder( $orderId );

		$widgets[] = array(
			'type'     => 'html',
			'title'    => __( 'Status history', 'ys-fluentcart-order-statuses' ),
			'subtitle' => __( 'Every order and shipping status this order has been through, and how long it stayed.', 'ys-fluentcart-order-statuses' ),
			'use_card' => true,
			'content'  => self::render( $rows, max( 0, $total - count( $rows ) ) ),
		);

		return $widgets;
	}

	/**
	 * The Order workflow card: per axis, the status, its step and the next one.
	 *
	 * @param array $state `Pipeline\Changer::state()`.
	 * @return string Escaped HTML.
	 */
	public static function renderChanger( array $state ) {
		$orderId = (int) $state['order_id'];

		// FluentCart 1.6.0 and 1.6.3 draw *Change Shipping Status* in More
		// Action only while the order is not canceled, yet the shipping axis
		// stays open on a canceled order, as it does in core. There the card is
		// the one place left to choose a shipping status.
		$canceled = Changer::CANCELED === (string) $state['axes']['order']['current'];

		$out = '<div class="ys-fct-changer" data-ys-changer data-ys-order="' . esc_attr( (string) $orderId ) . '">';

		foreach ( array( 'order', 'shipping' ) as $axis ) {
			$out .= self::renderAxis( $orderId, $axis, $state['axes'][ $axis ], 'shipping' === $axis && $canceled );
		}

		$out .= '<p class="ys-fct-changer-message" data-ys-changer-message role="status" aria-live="polite"></p>';
		$out .= '</div>';

		return $out;
	}

	/**
	 * @param int    $orderId   Order id.
	 * @param string $axis      'order' or 'shipping'.
	 * @param array  $axisState One entry of `Changer::state()['axes']`.
	 * @param bool   $onCard    Whether More Action offers no way to change
	 *                          this axis, so the card lists its moves on screen.
	 * @return string
	 */
	private static function renderAxis( $orderId, $axis, array $axisState, $onCard = false ) {
		$color = '' === $axisState['color'] ? '#64748b' : $axisState['color'];

		$out = '<div class="ys-fct-changer-axis" data-ys-axis="' . esc_attr( $axis ) . '">';

		$out .= '<p class="ys-fct-changer-head">'
			. '<span class="ys-fct-changer-axis-name">' . esc_html( self::axisName( $axis ) ) . '</span>'
			. '<span class="ys-fct-changer-badge" style="background:' . esc_attr( $color ) . '">'
			. esc_html( '' === $axisState['label'] ? self::emptyLabel( $axis ) : $axisState['label'] )
			. '</span>';

		$standing = isset( $axisState['standing'] ) ? $axisState['standing'] : '';

		if ( 'undefined' === $standing ) {
			$out .= '<span class="ys-fct-changer-note">' . esc_html__( 'not defined — this status was removed', 'ys-fluentcart-order-statuses' ) . '</span>';
		} elseif ( 'disabled' === $standing ) {
			$out .= '<span class="ys-fct-changer-note">' . esc_html__( 'disabled', 'ys-fluentcart-order-statuses' ) . '</span>';
		}

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

		if ( is_array( $axisState['next'] ) ) {
			$out .= '<p class="ys-fct-changer-row">'
				. '<button type="button" class="button button-primary ys-fct-changer-next"'
				. ' data-ys-changer-next="' . esc_attr( $axis ) . '"'
				. ' data-ys-changer-status="' . esc_attr( $axisState['next']['slug'] ) . '"'
				. ' data-ys-changer-label="' . esc_attr( $axisState['next']['label'] ) . '"'
				. ( empty( $axisState['next']['confirm'] ) ? '' : ' data-ys-confirm="' . esc_attr( $axisState['next']['slug'] ) . '"' )
				. '>'
				. esc_html(
					sprintf(
						/* translators: %s: the next workflow step's label */
						__( 'Next step: %s →', 'ys-fluentcart-order-statuses' ),
						$axisState['next']['label']
					)
				)
				. '</button></p>';
		}

		// Any other move is made from More Action, and the card says where on
		// screen: FluentCart does not display a widget's subtitle. The order
		// axis also keeps its list, hidden, as the fallback for the entry the
		// script adds. A canceled order has no Change Shipping Status in that
		// menu, so there the shipping list is drawn on screen instead.
		if ( $onCard ) {
			$out .= self::renderList( $orderId, $axis, $axisState['targets'] );
		} else {
			$out .= '<p class="ys-fct-changer-hint" data-ys-changer-hint="' . esc_attr( $axis ) . '">'
				. esc_html(
					'shipping' === $axis
						? __( 'Other statuses: More Action → Change Shipping Status', 'ys-fluentcart-order-statuses' )
						: __( 'Other statuses: More Action → Change Order Status', 'ys-fluentcart-order-statuses' )
				)
				. '</p>';

			if ( 'order' === $axis ) {
				$out .= self::renderList( $orderId, $axis, $axisState['targets'] );
			}
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * A "Move to…" list and its Change button, for the two places More Action
	 * cannot be relied on.
	 *
	 * Until 0.7 the card showed one on both axes. Two remain:
	 *
	 * - **The order axis's, hidden.** The order status is chosen from
	 *   FluentCart's More Action menu, whose *Change Order Status* entry
	 *   `assets/admin/order-changer.js` adds on the fly; that entry rests on
	 *   FluentCart markup this plugin does not own, so the list stays here as
	 *   the fallback. The script removes `hidden` only when the entry cannot be
	 *   used: no More Action trigger a few seconds after the card appeared, a
	 *   menu opened that the entry could not be added to, or a dialog that
	 *   could not load the order for a reason other than the page's session.
	 * - **The shipping axis's, on screen, on a canceled order only.** FluentCart
	 *   offers no *Change Shipping Status* there, while core still accepts the
	 *   change.
	 *
	 * Both post through the same `changeStatus()` as the dialog and the Next
	 * step button. `hidden` alone is not enough inside FluentCart's page; the
	 * stylesheet holds it at `display: none` (`.ys-fct-changer [hidden]`).
	 *
	 * @param int    $orderId Order id.
	 * @param string $axis    'order' (the hidden fallback) or 'shipping' (on screen).
	 * @param array  $targets `Changer::targets()` for that axis, not empty.
	 * @return string
	 */
	private static function renderList( $orderId, $axis, array $targets ) {
		$axis    = 'shipping' === $axis ? 'shipping' : 'order';
		$fieldId = 'ys-fct-changer-' . $axis . '-' . (int) $orderId;

		$out = 'order' === $axis
			? '<div class="ys-fct-changer-fallback" data-ys-changer-fallback="order" hidden>'
				. '<p class="ys-fct-changer-fallback-note">'
				. esc_html__( 'Change Order Status could not be used from the More Action menu on this page, so the order status can be changed here instead.', 'ys-fluentcart-order-statuses' )
				. '</p>'
			: '<div class="ys-fct-changer-direct" data-ys-changer-direct="shipping">'
				. '<p class="ys-fct-changer-direct-note">'
				. esc_html__( 'FluentCart’s More Action menu has no Change Shipping Status on a canceled order, so the shipping status is changed here.', 'ys-fluentcart-order-statuses' )
				. '</p>';

		$out .= '<p class="ys-fct-changer-row">'
			. '<label class="screen-reader-text" for="' . esc_attr( $fieldId ) . '">'
			. esc_html(
				'shipping' === $axis
					? __( 'Move the shipping status to', 'ys-fluentcart-order-statuses' )
					: __( 'Move the order status to', 'ys-fluentcart-order-statuses' )
			)
			. '</label>'
			. '<select id="' . esc_attr( $fieldId ) . '" class="ys-fct-changer-select" data-ys-changer-select="' . esc_attr( $axis ) . '">'
			. '<option value="">' . esc_html__( 'Move to…', 'ys-fluentcart-order-statuses' ) . '</option>';

		foreach ( $targets as $target ) {
			$out .= '<option value="' . esc_attr( $target['slug'] ) . '"'
				. ( empty( $target['confirm'] ) ? '' : ' data-ys-confirm="' . esc_attr( $target['slug'] ) . '"' )
				. '>' . esc_html( $target['label'] ) . '</option>';
		}

		$out .= '</select>'
			. '<button type="button" class="button ys-fct-changer-go" data-ys-changer-go="' . esc_attr( $axis ) . '">'
			. esc_html__( 'Change', 'ys-fluentcart-order-statuses' )
			. '</button>'
			. '</p></div>';

		return $out;
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
	 * @param array $rows   History rows, oldest first.
	 * @param int   $hidden Older rows that were left out.
	 * @return string Escaped HTML.
	 */
	public static function render( array $rows, $hidden = 0 ) {
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

		// Newest first, so the rows that did not fit are below the last one.
		if ( (int) $hidden > 0 ) {
			$out .= '<p class="ys-fct-status-older" style="margin:8px 0 0;opacity:.7;font-size:12px">' . esc_html(
				sprintf(
					/* translators: %d: number of older status changes not listed */
					_n( '%d older change not shown.', '%d older changes not shown.', (int) $hidden, 'ys-fluentcart-order-statuses' ),
					(int) $hidden
				)
			) . '</p>';
		}

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
