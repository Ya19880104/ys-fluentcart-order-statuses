<?php
/**
 * Plugin bootstrap.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses;

use YangSheep\FluentCart\OrderStatuses\Admin\AdminMenu;
use YangSheep\FluentCart\OrderStatuses\Admin\ColorStyles;
use YangSheep\FluentCart\OrderStatuses\Front\FrontStyles;
use YangSheep\FluentCart\OrderStatuses\Payment\RequirementGuard;
use YangSheep\FluentCart\OrderStatuses\Payment\RestoreHandler;
use YangSheep\FluentCart\OrderStatuses\Rest\StatusController;
use YangSheep\FluentCart\OrderStatuses\Support\OrderContext;

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

		( new StatusRegistry() )->register();
		( new OrderContext() )->register();
		( new RequirementGuard() )->register();
		( new RestoreHandler() )->register();
		( new StatusController() )->register();
		( new AdminMenu() )->register();
		( new ColorStyles() )->register();
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
