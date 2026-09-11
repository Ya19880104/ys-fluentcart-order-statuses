<?php
/**
 * Plugin bootstrap.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses;

use YangSheep\FluentCart\OrderStatuses\Admin\AdminMenu;
use YangSheep\FluentCart\OrderStatuses\Admin\ColorStyles;
use YangSheep\FluentCart\OrderStatuses\Admin\OrdersListMeta;
use YangSheep\FluentCart\OrderStatuses\Admin\OrderWidget;
use YangSheep\FluentCart\OrderStatuses\Admin\SavedViews;
use YangSheep\FluentCart\OrderStatuses\Front\FrontStyles;
use YangSheep\FluentCart\OrderStatuses\History\Recorder;
use YangSheep\FluentCart\OrderStatuses\Payment\RequirementGuard;
use YangSheep\FluentCart\OrderStatuses\Payment\RestoreHandler;
use YangSheep\FluentCart\OrderStatuses\Pipeline\LinkedShipping;
use YangSheep\FluentCart\OrderStatuses\Pipeline\StrictGuard;
use YangSheep\FluentCart\OrderStatuses\Reports\DailySummary;
use YangSheep\FluentCart\OrderStatuses\Rest\ReportController;
use YangSheep\FluentCart\OrderStatuses\Rest\StatusController;
use YangSheep\FluentCart\OrderStatuses\Support\OrderContext;
use YangSheep\FluentCart\OrderStatuses\Support\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires everything up once FluentCart is known to be present and new enough.
 */
final class Bootstrap {

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'onPluginsLoaded' ), 20 );
	}

	/**
	 * @return void
	 */
	public static function onPluginsLoaded() {
		load_plugin_textdomain(
			'ys-fluentcart-order-statuses',
			false,
			dirname( plugin_basename( YS_FCT_STATUS_FILE ) ) . '/languages'
		);

		if ( ! self::fluentCartIsUsable() ) {
			// Registering half of this against a missing host would mean status
			// filters that never fire and an admin page that 500s. Stand down
			// and say why instead.
			add_action( 'admin_notices', array( __CLASS__, 'renderDependencyNotice' ) );
			return;
		}

		// The history table is created on activation, but a plugin that was
		// updated in place (or copied over an older copy) never runs that hook.
		// This is one option read when the version already matches.
		Schema::maybeUpgrade();

		( new StatusRegistry() )->register();
		( new OrderContext() )->register();
		( new RequirementGuard() )->register();
		( new RestoreHandler() )->register();

		// Order matters between these three, and it is expressed as hook
		// priorities on `fluent_cart/order_status_changed`: RestoreHandler (5)
		// settles what the status actually is, LinkedShipping (20) reacts to
		// the settled value, Recorder (30) writes down what happened.
		( new LinkedShipping() )->register();
		( new StrictGuard() )->register();
		( new Recorder() )->register();

		( new StatusController() )->register();
		( new ReportController() )->register();
		( new AdminMenu() )->register();
		( new ColorStyles() )->register();
		( new SavedViews() )->register();
		( new OrdersListMeta() )->register();
		( new OrderWidget() )->register();
		( new DailySummary() )->register();
		( new FrontStyles() )->register();
	}

	/**
	 * @return bool Whether FluentCart is active and new enough.
	 */
	public static function fluentCartIsUsable() {
		if ( ! defined( 'FLUENTCART_VERSION' ) ) {
			return false;
		}

		return version_compare( FLUENTCART_VERSION, YS_FCT_STATUS_MIN_FLUENTCART, '>=' );
	}

	/**
	 * @return void
	 */
	public static function renderDependencyNotice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$message = defined( 'FLUENTCART_VERSION' )
			? sprintf(
				/* translators: 1: required FluentCart version, 2: installed FluentCart version */
				__( 'YS FluentCart Order Statuses needs FluentCart %1$s or newer. FluentCart %2$s is installed, so custom statuses are switched off. Orders already sitting on a custom status keep their value.', 'ys-fluentcart-order-statuses' ),
				YS_FCT_STATUS_MIN_FLUENTCART,
				FLUENTCART_VERSION
			)
			: sprintf(
				/* translators: %s: required FluentCart version */
				__( 'YS FluentCart Order Statuses needs FluentCart %s or newer to be active. Custom statuses are switched off until then.', 'ys-fluentcart-order-statuses' ),
				YS_FCT_STATUS_MIN_FLUENTCART
			);

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
