<?php
/**
 * The "Order Statuses" screen under the FluentCart menu.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Admin;

use YangSheep\FluentCart\OrderStatuses\Rest\StatusController;
use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\Support\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A plain WordPress admin page: the markup below is a shell, every row is
 * rendered by `assets/admin/admin.js` from this plugin's own REST routes. No
 * build step, no CDN, same shape as the two sibling add-ons.
 */
final class AdminMenu {

	const PAGE_SLUG = 'ys-fct-order-statuses';

	const HANDLE = 'ys-fct-status-admin';

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'addMenu' ), 100 );
	}

	/**
	 * @return void
	 */
	public function addMenu() {
		if ( ! Permissions::canManage() ) {
			return;
		}

		$hook = add_submenu_page(
			'fluent-cart',
			__( 'Order Statuses', 'ys-fluentcart-order-statuses' ),
			__( 'Order Statuses', 'ys-fluentcart-order-statuses' ),
			Permissions::FALLBACK_CAP,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'enqueue' ) );
		}
	}

	/**
	 * @return void
	 */
	public function enqueue() {
		add_action(
			'admin_enqueue_scripts',
			function () {
				wp_enqueue_style(
					self::HANDLE,
					YS_FCT_STATUS_URL . 'assets/admin/admin.css',
					array(),
					YS_FCT_STATUS_VERSION
				);

				wp_enqueue_script(
					self::HANDLE,
					YS_FCT_STATUS_URL . 'assets/admin/admin.js',
					array(),
					YS_FCT_STATUS_VERSION,
					true
				);

				wp_localize_script(
					self::HANDLE,
					'ysFctStatusAdmin',
					array(
						'restUrl'    => esc_url_raw( rest_url( StatusController::NAMESPACE_V1 . '/' ) ),
						'restNonce'  => wp_create_nonce( 'wp_rest' ),
						'maxSlug'    => Settings::MAX_SLUG_LENGTH,
						'defaults'   => array(
							'color'                  => Settings::DEFAULT_COLOR,
							'payment_requirement'    => 'any',
							'on_payment'             => 'keep',
							'linked_shipping_status' => '',
						),
						'ordersUrl'  => esc_url_raw( admin_url( 'admin.php?page=fluent-cart#/orders' ) ),
						// FluentCart's own notification screen. This plugin adds
						// no on/off switch of its own — everything about the
						// mail is edited there, and this is the way in.
						'emailsUrl'  => esc_url_raw( admin_url( 'admin.php?page=fluent-cart#/settings/email_notifications' ) ),
						'entrySlug'  => Settings::PIPELINE_ENTRY,
						'shipEntry'  => Settings::SHIPPING_PIPELINE_ENTRY,
						'shipExit'   => Settings::SHIPPING_PIPELINE_EXIT,
						'i18n'       => $this->strings(),
					)
				);
			}
		);
	}

	/**
	 * @return void
	 */
	public function render() {
		if ( ! Permissions::canManage() ) {
			wp_die( esc_html__( 'You are not allowed to manage order statuses.', 'ys-fluentcart-order-statuses' ) );
		}

		?>
		<div class="wrap ys-fct-status-admin" id="ys-fct-status-admin">
			<h1><?php esc_html_e( 'Order Statuses', 'ys-fluentcart-order-statuses' ); ?></h1>

			<p class="ys-fct-status-intro">
				<?php esc_html_e( 'FluentCart tracks three independent things: the order status, the payment status and the shipping status. You can add your own order and shipping statuses here, and rename the built-in ones.', 'ys-fluentcart-order-statuses' ); ?>
			</p>

			<h2 class="nav-tab-wrapper ys-fct-status-tabs">
				<a href="#order" class="nav-tab nav-tab-active" data-ys-tab="order"><?php esc_html_e( 'Order statuses', 'ys-fluentcart-order-statuses' ); ?></a>
				<a href="#shipping" class="nav-tab" data-ys-tab="shipping"><?php esc_html_e( 'Shipping statuses', 'ys-fluentcart-order-statuses' ); ?></a>
				<a href="#builtin" class="nav-tab" data-ys-tab="builtin"><?php esc_html_e( 'Built-in labels', 'ys-fluentcart-order-statuses' ); ?></a>
				<a href="#reports" class="nav-tab" data-ys-tab="reports"><?php esc_html_e( 'Order Status Report', 'ys-fluentcart-order-statuses' ); ?></a>
				<a href="#tools" class="nav-tab" data-ys-tab="tools"><?php esc_html_e( 'Tools', 'ys-fluentcart-order-statuses' ); ?></a>
			</h2>

			<div class="ys-fct-status-notice" data-ys-notice role="status" aria-live="polite"></div>

			<section class="ys-fct-status-panel" data-ys-panel="order">
				<div class="notice notice-info inline ys-fct-status-hint">
					<p>
						<?php esc_html_e( 'Every status you add here is offered on every order, paid or not. FluentCart rewrites the order status to “Processing” the moment a payment is recorded — that is hardcoded in core and has no filter — so this plugin writes your status straight back, with a line in the order activity. Both behaviours can be changed per status under Advanced. Workflow steps that are really about fulfilment fit better on the Shipping statuses tab, where nothing overwrites them.', 'ys-fluentcart-order-statuses' ); ?>
					</p>
				</div>

				<div class="ys-fct-status-pipeline" data-ys-pipeline-preview>
					<h3><?php esc_html_e( 'The workflow, in order', 'ys-fluentcart-order-statuses' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Step 1 is the built-in “Processing” status, which FluentCart writes as soon as a payment is recorded. The steps after it are the custom statuses below, in the order they are listed here. That order is how the Order workflow control on each order lists them, and what strict mode enforces.', 'ys-fluentcart-order-statuses' ); ?>
					</p>
					<ol class="ys-fct-status-steps" data-ys-steps></ol>
					<p>
						<button type="button" class="button" data-ys-template><?php esc_html_e( 'Create the standard workflow', 'ys-fluentcart-order-statuses' ); ?></button>
						<span class="description"><?php esc_html_e( 'Adds “In production”, “Shipment scheduled” and “Shipped” (the last one also sets the shipping status), each offered on every order. FluentCart’s built-in statuses keep their own names, and nothing you have already set up is overwritten.', 'ys-fluentcart-order-statuses' ); ?></span>
					</p>
				</div>

				<table class="widefat striped ys-fct-status-table" data-ys-table="order">
					<thead>
						<tr>
							<th class="ys-fct-col-order"><?php esc_html_e( 'Step', 'ys-fluentcart-order-statuses' ); ?></th>
							<th><?php esc_html_e( 'Label', 'ys-fluentcart-order-statuses' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'ys-fluentcart-order-statuses' ); ?></th>
							<th><?php esc_html_e( 'Colour', 'ys-fluentcart-order-statuses' ); ?></th>
							<th><?php esc_html_e( 'Also set shipping to', 'ys-fluentcart-order-statuses' ); ?></th>
							<th><?php esc_html_e( 'Options', 'ys-fluentcart-order-statuses' ); ?></th>
							<th><?php esc_html_e( 'Orders', 'ys-fluentcart-order-statuses' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'ys-fluentcart-order-statuses' ); ?></th>
						</tr>
					</thead>
					<tbody data-ys-rows="order"></tbody>
				</table>

				<p>
					<button type="button" class="button" data-ys-add="order"><?php esc_html_e( 'Add order status', 'ys-fluentcart-order-statuses' ); ?></button>
					<button type="button" class="button button-primary" data-ys-save><?php esc_html_e( 'Save changes', 'ys-fluentcart-order-statuses' ); ?></button>
				</p>
			</section>

			<section class="ys-fct-status-panel" data-ys-panel="shipping" hidden>
				<div class="notice notice-success inline ys-fct-status-hint">
					<p>
						<?php esc_html_e( 'Nothing in FluentCart writes the shipping status automatically — it changes only when someone changes it — and FluentCart’s own “Change Shipping Status” dialog lists the statuses you add here directly. This is the safest place for a multi-step fulfilment workflow, and the Tools tab will build one for you.', 'ys-fluentcart-order-statuses' ); ?>
					</p>
				</div>

				<div class="ys-fct-status-pipeline" data-ys-shipping-preview>
					<h3><?php esc_html_e( 'The fulfilment workflow, in order', 'ys-fluentcart-order-statuses' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Step 1 is the built-in “Unshipped”, which every physical order starts on, and the last step is the built-in “Shipped”, which is the value FluentCart checks when it marks each item fulfilled. The steps between them are the custom statuses below, in the order they are listed here — and that is the order FluentCart’s own shipping dialog offers them in.', 'ys-fluentcart-order-statuses' ); ?>
					</p>
					<ol class="ys-fct-status-steps" data-ys-shipping-steps></ol>
				</div>

				<table class="widefat striped ys-fct-status-table" data-ys-table="shipping">
					<thead>
						<tr>
							<th class="ys-fct-col-order"><span class="screen-reader-text"><?php esc_html_e( 'Reorder', 'ys-fluentcart-order-statuses' ); ?></span></th>
							<th><?php esc_html_e( 'Label', 'ys-fluentcart-order-statuses' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'ys-fluentcart-order-statuses' ); ?></th>
							<th><?php esc_html_e( 'Colour', 'ys-fluentcart-order-statuses' ); ?></th>
							<th><?php esc_html_e( 'Options', 'ys-fluentcart-order-statuses' ); ?></th>
							<th><?php esc_html_e( 'Orders', 'ys-fluentcart-order-statuses' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'ys-fluentcart-order-statuses' ); ?></th>
						</tr>
					</thead>
					<tbody data-ys-rows="shipping"></tbody>
				</table>

				<p>
					<button type="button" class="button" data-ys-add="shipping"><?php esc_html_e( 'Add shipping status', 'ys-fluentcart-order-statuses' ); ?></button>
					<button type="button" class="button button-primary" data-ys-save><?php esc_html_e( 'Save changes', 'ys-fluentcart-order-statuses' ); ?></button>
				</p>
			</section>

			<section class="ys-fct-status-panel" data-ys-panel="builtin" hidden>
				<p class="description">
					<?php esc_html_e( 'Rename or recolour the statuses FluentCart ships with. The stored slug never changes, so nothing else in FluentCart is affected — leave a field blank to keep the original.', 'ys-fluentcart-order-statuses' ); ?>
				</p>

				<h3><?php esc_html_e( 'Order statuses', 'ys-fluentcart-order-statuses' ); ?></h3>
				<table class="widefat striped ys-fct-status-table"><tbody data-ys-overrides="order"></tbody></table>

				<h3><?php esc_html_e( 'Payment statuses', 'ys-fluentcart-order-statuses' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Payment statuses can be renamed but not added to: FluentCart decides download access, refunds, customer lifetime value and every revenue report from this column using hardcoded lists, so a new value would go quietly missing from all of them.', 'ys-fluentcart-order-statuses' ); ?>
				</p>
				<table class="widefat striped ys-fct-status-table"><tbody data-ys-overrides="payment"></tbody></table>

				<h3><?php esc_html_e( 'Shipping statuses', 'ys-fluentcart-order-statuses' ); ?></h3>
				<table class="widefat striped ys-fct-status-table"><tbody data-ys-overrides="shipping"></tbody></table>

				<p><button type="button" class="button button-primary" data-ys-save><?php esc_html_e( 'Save changes', 'ys-fluentcart-order-statuses' ); ?></button></p>
			</section>

			<section class="ys-fct-status-panel" data-ys-panel="reports" hidden>
				<div class="notice notice-info inline ys-fct-status-hint">
					<p>
						<?php esc_html_e( 'This report lives here rather than under FluentCart → Reports because FluentCart 1.6 has no way to register a report page: its report screens are compiled Vue components and the only report filters it exposes belong to the traffic-source report. Everything below is read straight from the orders table, so the numbers match what you see when you filter the Orders list by the same status.', 'ys-fluentcart-order-statuses' ); ?>
					</p>
				</div>

				<p class="ys-fct-status-axis">
					<span class="ys-fct-status-axis-label"><?php esc_html_e( 'Workflow to report on:', 'ys-fluentcart-order-statuses' ); ?></span>
					<label for="ys-fct-report-axis-order">
						<input type="radio" id="ys-fct-report-axis-order" name="ys_fct_report_axis" value="order" data-ys-report-axis checked="checked" />
						<?php esc_html_e( 'Order status', 'ys-fluentcart-order-statuses' ); ?>
					</label>
					<label for="ys-fct-report-axis-shipping">
						<input type="radio" id="ys-fct-report-axis-shipping" name="ys_fct_report_axis" value="shipping" data-ys-report-axis />
						<?php esc_html_e( 'Shipping status', 'ys-fluentcart-order-statuses' ); ?>
					</label>
					<span class="description"><?php esc_html_e( 'Chooses which workflow the funnel, the timings and the stuck list describe. Both status tables below are always shown.', 'ys-fluentcart-order-statuses' ); ?></span>
				</p>

				<p class="ys-fct-status-range">
					<label for="ys-fct-report-since"><?php esc_html_e( 'Orders placed from', 'ys-fluentcart-order-statuses' ); ?></label>
					<input type="date" id="ys-fct-report-since" name="ys_fct_report_since" data-ys-range="since" />
					<label for="ys-fct-report-until"><?php esc_html_e( 'to', 'ys-fluentcart-order-statuses' ); ?></label>
					<input type="date" id="ys-fct-report-until" name="ys_fct_report_until" data-ys-range="until" />
					<button type="button" class="button" data-ys-report-refresh><?php esc_html_e( 'Refresh', 'ys-fluentcart-order-statuses' ); ?></button>
					<button type="button" class="button button-link" data-ys-report-clear><?php esc_html_e( 'All time', 'ys-fluentcart-order-statuses' ); ?></button>
				</p>

				<div data-ys-report-note class="ys-fct-status-report-note"></div>

				<h3><?php esc_html_e( 'Workflow funnel', 'ys-fluentcart-order-statuses' ); ?> <span class="ys-fct-status-axis-chip" data-ys-axis-chip></span></h3>
				<p class="description"><?php esc_html_e( 'How many orders are sitting on each step right now, whatever date range is selected above. Click a number to open the Orders list.', 'ys-fluentcart-order-statuses' ); ?></p>
				<div data-ys-funnel class="ys-fct-status-funnel"></div>

				<h3><?php esc_html_e( 'Order statuses', 'ys-fluentcart-order-statuses' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Orders and money per status, split by whether the money actually arrived. “Paid” means the payment status is paid, partially paid or partially refunded.', 'ys-fluentcart-order-statuses' ); ?></p>
				<div data-ys-distribution="order"></div>
				<p><button type="button" class="button" data-ys-export-report="distribution"><?php esc_html_e( 'Download CSV', 'ys-fluentcart-order-statuses' ); ?></button></p>

				<h3><?php esc_html_e( 'Shipping statuses', 'ys-fluentcart-order-statuses' ); ?></h3>
				<div data-ys-distribution="shipping"></div>
				<p><button type="button" class="button" data-ys-export-report="shipping"><?php esc_html_e( 'Download CSV', 'ys-fluentcart-order-statuses' ); ?></button></p>

				<h3><?php esc_html_e( 'Time in each status', 'ys-fluentcart-order-statuses' ); ?> <span class="ys-fct-status-axis-chip" data-ys-axis-chip></span></h3>
				<p class="description"><?php esc_html_e( 'Measured from this plugin\'s own status history, and counting only stays that have ended — an order still sitting on a step has not finished its stay, so it is listed below instead.', 'ys-fluentcart-order-statuses' ); ?></p>
				<div data-ys-dwell></div>
				<p><button type="button" class="button" data-ys-export-report="dwell"><?php esc_html_e( 'Download CSV', 'ys-fluentcart-order-statuses' ); ?></button></p>

				<h3><?php esc_html_e( 'Stuck orders', 'ys-fluentcart-order-statuses' ); ?> <span class="ys-fct-status-axis-chip" data-ys-axis-chip></span></h3>
				<div data-ys-stalled></div>
				<p><button type="button" class="button" data-ys-export-report="stalled"><?php esc_html_e( 'Download CSV', 'ys-fluentcart-order-statuses' ); ?></button></p>
			</section>

			<section class="ys-fct-status-panel" data-ys-panel="tools" hidden>
				<h3><?php esc_html_e( 'Which axis should carry your workflow?', 'ys-fluentcart-order-statuses' ); ?></h3>
				<div class="notice notice-success inline ys-fct-status-hint">
					<p>
						<?php esc_html_e( 'If your workflow is about getting the goods out — made, packed, booked, shipped — put it on the shipping status. FluentCart never writes that column by itself, and its own “Change Shipping Status” dialog lists your custom steps directly. The order status is the other way round: FluentCart rewrites it to “Processing” whenever a payment lands, and its admin has no control for choosing an order status at all — only fixed buttons such as Mark As Complete and Cancel Order — so this plugin supplies both the guard and the control.', 'ys-fluentcart-order-statuses' ); ?>
					</p>
				</div>
				<p>
					<button type="button" class="button button-primary" data-ys-template-shipping><?php esc_html_e( 'Create the fulfilment workflow (shipping axis)', 'ys-fluentcart-order-statuses' ); ?></button>
					<span class="description"><?php esc_html_e( 'Adds the shipping statuses “In production” and “Shipment scheduled” between the built-in Unshipped and Shipped. FluentCart’s built-in statuses keep their own names, and nothing you have already set up is overwritten.', 'ys-fluentcart-order-statuses' ); ?></span>
				</p>
				<p class="description">
					<?php esc_html_e( 'The order-axis version of the same workflow is on the Order statuses tab. Use it when the states you need really are states of the order rather than of the delivery — “awaiting artwork approval”, say — or when you want them on the status your customers already see.', 'ys-fluentcart-order-statuses' ); ?>
				</p>

				<h3><?php esc_html_e( 'Order workflow', 'ys-fluentcart-order-statuses' ); ?></h3>
				<p>
					<label for="ys-fct-status-strict">
						<input type="checkbox" id="ys-fct-status-strict" name="ys_fct_status_strict" data-ys-strict-toggle />
						<?php esc_html_e( 'Strict workflow — an order may only move to the next or previous step', 'ys-fluentcart-order-statuses' ); ?>
					</label>
				</p>
				<p class="description">
					<?php esc_html_e( 'Skipping a step is refused with a message naming the step that was missed. Moving an order out of the workflow entirely — completing, cancelling or putting it on hold — is always allowed, and so is moving an order into the workflow from outside it.', 'ys-fluentcart-order-statuses' ); ?>
				</p>

				<p>
					<label for="ys-fct-status-stall-days">
						<?php esc_html_e( 'Report an order as stuck after', 'ys-fluentcart-order-statuses' ); ?>
						<input type="number" id="ys-fct-status-stall-days" name="ys_fct_status_stall_days" min="1" max="365" step="1" class="small-text" data-ys-stall-days />
						<?php esc_html_e( 'day(s) on the same step', 'ys-fluentcart-order-statuses' ); ?>
					</label>
				</p>

				<h3><?php esc_html_e( 'Status history', 'ys-fluentcart-order-statuses' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Every status change is recorded from now on. Orders that changed status before this plugin was installed can be recovered from FluentCart\'s own activity log — it stores each change as a sentence, so this is best effort and lines it cannot read are skipped and counted.', 'ys-fluentcart-order-statuses' ); ?>
				</p>
				<p>
					<button type="button" class="button" data-ys-backfill><?php esc_html_e( 'Import history from the activity log', 'ys-fluentcart-order-statuses' ); ?></button>
					<span data-ys-history-count class="description"></span>
				</p>

				<h3><?php esc_html_e( 'Daily summary e-mail', 'ys-fluentcart-order-statuses' ); ?></h3>
				<p>
					<label for="ys-fct-status-summary">
						<input type="checkbox" id="ys-fct-status-summary" name="ys_fct_status_summary" data-ys-summary-toggle />
						<?php esc_html_e( 'Send a daily summary of the workflow and the stuck orders', 'ys-fluentcart-order-statuses' ); ?>
					</label>
				</p>
				<p>
					<label for="ys-fct-status-summary-email"><?php esc_html_e( 'Send it to', 'ys-fluentcart-order-statuses' ); ?></label>
					<input type="email" id="ys-fct-status-summary-email" name="ys_fct_status_summary_email" class="regular-text" data-ys-summary-email placeholder="name@example.com" />
				</p>
				<p class="description">
					<?php esc_html_e( 'Sent once a day by WP-Cron, with a catch-up on the next admin page load if cron did not fire — which on a quiet shop it often does not. Save your changes before sending a test.', 'ys-fluentcart-order-statuses' ); ?>
				</p>
				<p>
					<button type="button" class="button" data-ys-summary-test><?php esc_html_e( 'Send one now', 'ys-fluentcart-order-statuses' ); ?></button>
				</p>

				<h3><?php esc_html_e( 'Payment behaviour', 'ys-fluentcart-order-statuses' ); ?></h3>
				<p>
					<label for="ys-fct-status-restore">
						<input type="checkbox" id="ys-fct-status-restore" name="ys_fct_status_restore" data-ys-restore-toggle />
						<?php esc_html_e( 'Restore custom order statuses that FluentCart overwrites when a payment is recorded', 'ys-fluentcart-order-statuses' ); ?>
					</label>
				</p>
				<p class="description">
					<?php esc_html_e( 'Applies per status, to the ones set to “Keep after payment”. Switch this off to let FluentCart move every paid order to Processing as it normally would.', 'ys-fluentcart-order-statuses' ); ?>
				</p>

				<h3><?php esc_html_e( 'Before you deactivate', 'ys-fluentcart-order-statuses' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Deactivating this plugin does not change a single order row — orders sitting on a custom status keep it, and FluentCart simply shows the raw slug. To clear them out first, move each custom status\'s orders to a built-in one with the Move button on its row.', 'ys-fluentcart-order-statuses' ); ?>
				</p>
				<div data-ys-usage-summary></div>

				<h3><?php esc_html_e( 'Export / import', 'ys-fluentcart-order-statuses' ); ?></h3>
				<p>
					<button type="button" class="button" data-ys-export><?php esc_html_e( 'Download JSON', 'ys-fluentcart-order-statuses' ); ?></button>
				</p>
				<p>
					<label for="ys-fct-status-import"><?php esc_html_e( 'Paste an exported file to replace every status below:', 'ys-fluentcart-order-statuses' ); ?></label><br />
					<textarea id="ys-fct-status-import" data-ys-import-json rows="8" class="large-text code" spellcheck="false"></textarea>
				</p>
				<p>
					<button type="button" class="button" data-ys-import><?php esc_html_e( 'Import', 'ys-fluentcart-order-statuses' ); ?></button>
				</p>
				<p class="description">
					<?php esc_html_e( 'Before anything is written you are shown what the file would change. An import that would remove a status orders are still on is refused, and the configuration it replaces is kept so the import can be undone.', 'ys-fluentcart-order-statuses' ); ?>
				</p>
				<p data-ys-undo-wrap hidden>
					<button type="button" class="button" data-ys-undo><?php esc_html_e( 'Undo the last import', 'ys-fluentcart-order-statuses' ); ?></button>
					<span class="description" data-ys-undo-note></span>
				</p>
			</section>

			<template data-ys-template="move">
				<div class="ys-fct-status-move">
					<label>
						<span data-ys-move-label></span>
						<select data-ys-move-target></select>
					</label>
					<button type="button" class="button button-small" data-ys-move-go><?php esc_html_e( 'Move orders', 'ys-fluentcart-order-statuses' ); ?></button>
					<button type="button" class="button button-small button-link" data-ys-move-cancel><?php esc_html_e( 'Cancel', 'ys-fluentcart-order-statuses' ); ?></button>
				</div>
			</template>
		</div>
		<?php
	}

	/**
	 * @return array<string,string>
	 */
	private function strings() {
		return array(
			'saving'            => __( 'Saving…', 'ys-fluentcart-order-statuses' ),
			'saved'             => __( 'Statuses saved.', 'ys-fluentcart-order-statuses' ),
			'loadFailed'        => __( 'Could not load the statuses.', 'ys-fluentcart-order-statuses' ),
			'saveFailed'        => __( 'Could not save.', 'ys-fluentcart-order-statuses' ),
			'newLabel'          => __( 'New status', 'ys-fluentcart-order-statuses' ),
			'remove'            => __( 'Remove', 'ys-fluentcart-order-statuses' ),
			'move'              => __( 'Move orders', 'ys-fluentcart-order-statuses' ),
			'up'                => __( 'Move up', 'ys-fluentcart-order-statuses' ),
			'down'              => __( 'Move down', 'ys-fluentcart-order-statuses' ),
			'editable'          => __( 'Selectable in the admin', 'ys-fluentcart-order-statuses' ),
			'slug'              => __( 'Slug', 'ys-fluentcart-order-statuses' ),
			'colourHex'         => __( 'Colour hex code', 'ys-fluentcart-order-statuses' ),
			'colourPicker'      => __( 'Colour picker', 'ys-fluentcart-order-statuses' ),
			'availableOn'       => __( 'Available on', 'ys-fluentcart-order-statuses' ),
			'afterPayment'      => __( 'After payment', 'ys-fluentcart-order-statuses' ),
			'enabled'           => __( 'Enabled', 'ys-fluentcart-order-statuses' ),
			'description'       => __( 'Description', 'ys-fluentcart-order-statuses' ),
			'reqAny'            => __( 'Any order', 'ys-fluentcart-order-statuses' ),
			'reqPaid'           => __( 'Paid orders only', 'ys-fluentcart-order-statuses' ),
			'reqUnpaid'         => __( 'Unpaid orders only', 'ys-fluentcart-order-statuses' ),
			'keep'              => __( 'Keep this status', 'ys-fluentcart-order-statuses' ),
			'letCore'           => __( 'Let FluentCart set Processing', 'ys-fluentcart-order-statuses' ),
			// v0.5 — the two payment settings live under a collapsed Advanced line.
			'advanced'          => __( 'Advanced', 'ys-fluentcart-order-statuses' ),
			'advancedHint'      => __( 'The defaults suit almost every shop: the status is offered on every order, and it stays put when a payment lands.', 'ys-fluentcart-order-statuses' ),
			'emailLabel'        => __( 'E-mail', 'ys-fluentcart-order-statuses' ),
			'emailOff'          => __( 'off', 'ys-fluentcart-order-statuses' ),
			'emailCustomer'     => __( 'customer', 'ys-fluentcart-order-statuses' ),
			'emailAdmin'        => __( 'admin', 'ys-fluentcart-order-statuses' ),
			'emailEdit'         => __( 'edit in Email Notifications', 'ys-fluentcart-order-statuses' ),
			'emailUnsaved'      => __( 'save this status first', 'ys-fluentcart-order-statuses' ),
			/* translators: %d: number of orders currently using this status */
			'inUseRemove'       => __( 'This status is still on %d order(s). Move them to another status before removing it.', 'ys-fluentcart-order-statuses' ),
			/* translators: %s: status label */
			'confirmRemove'     => __( 'Remove “%s”? Nothing is written until you press Save changes.', 'ys-fluentcart-order-statuses' ),
			/* translators: 1: number of orders, 2: status label */
			'moveTo'            => __( 'Move the %1$d order(s) on “%2$s” to:', 'ys-fluentcart-order-statuses' ),
			'moving'            => __( 'Moving orders…', 'ys-fluentcart-order-statuses' ),
			'importConfirm'     => __( 'Replace every status with the contents of this file?', 'ys-fluentcart-order-statuses' ),
			'importFailed'      => __( 'Could not import that file.', 'ys-fluentcart-order-statuses' ),
			'noCustom'          => __( 'No custom statuses yet.', 'ys-fluentcart-order-statuses' ),
			'usageNone'         => __( 'No orders are currently using a custom status.', 'ys-fluentcart-order-statuses' ),
			'usageSome'         => __( 'Orders currently using a custom status:', 'ys-fluentcart-order-statuses' ),
			'labelPlaceholder'  => __( 'Shown to staff and customers', 'ys-fluentcart-order-statuses' ),
			'slugPlaceholder'   => __( 'stored_value', 'ys-fluentcart-order-statuses' ),
			'unsaved'           => __( 'You have unsaved changes.', 'ys-fluentcart-order-statuses' ),

			// v0.6 — guard rails.
			'slugLocked'        => __( 'A saved status keeps its slug, because that is the value its orders carry. To use a different slug, add a new status and move the orders to it.', 'ys-fluentcart-order-statuses' ),
			'importChecking'    => __( 'Reading the file…', 'ys-fluentcart-order-statuses' ),
			'importWorking'     => __( 'Importing…', 'ys-fluentcart-order-statuses' ),
			'importNothing'     => __( 'The file matches the current settings, so nothing would change. Import it anyway?', 'ys-fluentcart-order-statuses' ),
			'undoConfirm'       => __( 'Undo the last import? The statuses, settings and e-mail text go back to what they were before it, including anything changed on this screen since.', 'ys-fluentcart-order-statuses' ),
			'undoWorking'       => __( 'Undoing the import…', 'ys-fluentcart-order-statuses' ),
			/* translators: 1: number of orders, 2: status label, 3: target status label */
			'moveConfirm'       => __( 'Move %1$d order(s) from “%2$s” to “%3$s”? This writes straight to the orders: no e-mail is sent and FluentCart’s own automations do not run.', 'ys-fluentcart-order-statuses' ),

			// v0.2 — pipeline and reports.
			'linkedNone'        => __( 'Leave the shipping status alone', 'ys-fluentcart-order-statuses' ),
			'linkedShipping'    => __( 'Also set the shipping status to', 'ys-fluentcart-order-statuses' ),
			'stepEntry'         => __( 'Set by FluentCart when a payment is recorded', 'ys-fluentcart-order-statuses' ),
			'templateConfirm'   => __( 'Add the standard workflow steps? Anything you have already set up is left alone.', 'ys-fluentcart-order-statuses' ),
			'templateWorking'   => __( 'Creating the workflow…', 'ys-fluentcart-order-statuses' ),
			'backfillConfirm'   => __( 'Read FluentCart\'s activity log and rebuild the imported history? Changes recorded since the plugin was installed are kept as they are.', 'ys-fluentcart-order-statuses' ),
			'backfillWorking'   => __( 'Reading the activity log…', 'ys-fluentcart-order-statuses' ),
			/* translators: %d: number of rows in the status history table */
			'historyCount'      => __( '%d status change(s) recorded.', 'ys-fluentcart-order-statuses' ),
			'summaryWorking'    => __( 'Sending…', 'ys-fluentcart-order-statuses' ),
			'reportLoading'     => __( 'Loading the report…', 'ys-fluentcart-order-statuses' ),
			'reportFailed'      => __( 'Could not load the report.', 'ys-fluentcart-order-statuses' ),
			'colStatus'         => __( 'Status', 'ys-fluentcart-order-statuses' ),
			'colPaid'           => __( 'Paid', 'ys-fluentcart-order-statuses' ),
			'colUnpaid'         => __( 'Unpaid', 'ys-fluentcart-order-statuses' ),
			'colTotal'          => __( 'Total', 'ys-fluentcart-order-statuses' ),
			'colOrders'         => __( 'Orders', 'ys-fluentcart-order-statuses' ),
			'colAmount'         => __( 'Amount', 'ys-fluentcart-order-statuses' ),
			'colStays'          => __( 'Completed stays', 'ys-fluentcart-order-statuses' ),
			'colAverage'        => __( 'Average', 'ys-fluentcart-order-statuses' ),
			'colLongest'        => __( 'Longest', 'ys-fluentcart-order-statuses' ),
			'colOrder'          => __( 'Order', 'ys-fluentcart-order-statuses' ),
			'colSince'          => __( 'On this step since', 'ys-fluentcart-order-statuses' ),
			'colDays'           => __( 'Days', 'ys-fluentcart-order-statuses' ),
			'noData'            => __( 'No orders in this range.', 'ys-fluentcart-order-statuses' ),
			'noDwell'           => __( 'No completed stays yet — a status needs to have been left at least once before it can be timed.', 'ys-fluentcart-order-statuses' ),
			/* translators: %d: threshold in days */
			'noStalled'         => __( 'Nothing has been on the same step for more than %d day(s).', 'ys-fluentcart-order-statuses' ),
			'days'              => __( 'days', 'ys-fluentcart-order-statuses' ),
			'hours'             => __( 'hours', 'ys-fluentcart-order-statuses' ),
			'minutes'           => __( 'min', 'ys-fluentcart-order-statuses' ),
			/* translators: %s: comma-separated currency codes */
			'mixedCurrency'     => __( 'This store has orders in more than one currency (%s). The amounts below are a plain sum of the stored values and are not converted.', 'ys-fluentcart-order-statuses' ),
			'noHistoryYet'      => __( 'No status history has been recorded yet, so the timings below are empty. Import the history from the activity log on the Tools tab, or wait for the next status change.', 'ys-fluentcart-order-statuses' ),

			// v0.3 — the shipping-axis workflow and the report's axis switch.
			'shippingConfirm'   => __( 'Add the fulfilment workflow to the shipping status? Anything you have already set up is left alone.', 'ys-fluentcart-order-statuses' ),
			'stepShipEntry'     => __( 'Unshipped — where every physical order starts', 'ys-fluentcart-order-statuses' ),
			'stepShipExit'      => __( 'Shipped — FluentCart marks the items fulfilled here', 'ys-fluentcart-order-statuses' ),
			'axisOrder'         => __( 'order status', 'ys-fluentcart-order-statuses' ),
			'axisShipping'      => __( 'shipping status', 'ys-fluentcart-order-statuses' ),
		);
	}
}
