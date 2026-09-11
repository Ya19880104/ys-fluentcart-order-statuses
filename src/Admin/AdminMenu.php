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
							'color'               => Settings::DEFAULT_COLOR,
							'payment_requirement' => 'any',
							'on_payment'          => 'keep',
						),
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
				<a href="#tools" class="nav-tab" data-ys-tab="tools"><?php esc_html_e( 'Tools', 'ys-fluentcart-order-statuses' ); ?></a>
			</h2>

			<div class="ys-fct-status-notice" data-ys-notice role="status" aria-live="polite"></div>

			<section class="ys-fct-status-panel" data-ys-panel="order">
				<div class="notice notice-info inline ys-fct-status-hint">
					<p>
						<?php esc_html_e( 'FluentCart rewrites the order status to “Processing” the moment a payment is recorded — that array is hardcoded in core and has no filter. Statuses set to “Keep after payment” are written back by this plugin immediately afterwards, with a line in the order activity. Workflow steps that are really about fulfilment belong on the Shipping statuses tab, where nothing overwrites them.', 'ys-fluentcart-order-statuses' ); ?>
					</p>
				</div>

				<table class="widefat striped ys-fct-status-table" data-ys-table="order">
					<thead>
						<tr>
							<th class="ys-fct-col-order"><span class="screen-reader-text"><?php esc_html_e( 'Reorder', 'ys-fluentcart-order-statuses' ); ?></span></th>
							<th><?php esc_html_e( 'Label', 'ys-fluentcart-order-statuses' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'ys-fluentcart-order-statuses' ); ?></th>
							<th><?php esc_html_e( 'Colour', 'ys-fluentcart-order-statuses' ); ?></th>
							<th><?php esc_html_e( 'Available on', 'ys-fluentcart-order-statuses' ); ?></th>
							<th><?php esc_html_e( 'After payment', 'ys-fluentcart-order-statuses' ); ?></th>
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
						<?php esc_html_e( 'Nothing in FluentCart writes the shipping status automatically — it changes only when someone changes it. This is the safest place for a multi-step fulfilment workflow.', 'ys-fluentcart-order-statuses' ); ?>
					</p>
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

			<section class="ys-fct-status-panel" data-ys-panel="tools" hidden>
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
		);
	}
}
