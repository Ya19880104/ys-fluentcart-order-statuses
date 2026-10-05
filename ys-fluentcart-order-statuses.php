<?php
/**
 * Plugin Name: YS FluentCart Order Statuses
 * Plugin URI: https://yangsheep.com.tw
 * Description: Custom order and shipping statuses for FluentCart — add your own workflow states (with colours, payment conditions and a "keep this status after payment" guard), and rename the built-in ones. Nothing in FluentCart core is patched.
 * Version: 0.7.0
 * Author: YANGSHEEP DESIGN
 * Author URI: https://yangsheep.com.tw
 * Text Domain: ys-fluentcart-order-statuses
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Plugin constants ─────────────────────────────────────────────────────────

define( 'YS_FCT_STATUS_VERSION', '0.7.0' );
define( 'YS_FCT_STATUS_FILE', __FILE__ );
define( 'YS_FCT_STATUS_DIR', plugin_dir_path( __FILE__ ) );
define( 'YS_FCT_STATUS_URL', plugin_dir_url( __FILE__ ) );
define( 'YS_FCT_STATUS_SLUG', 'ys-fluentcart-order-statuses' );

// The oldest FluentCart whose status filters this add-on relies on. 1.6.0 is
// still common in production; 1.6.3 is what it is tested against.
define( 'YS_FCT_STATUS_MIN_FLUENTCART', '1.6.0' );

// ── Vendor autoload (YS Plugin Hub Client — enables auto-updates) ─────────────
// The Hub client is self-contained (its own PSR-4 autoloader + duplicate-load
// guard) and does not depend on FluentCart.

if ( file_exists( YS_FCT_STATUS_DIR . 'vendor/autoload.php' ) ) {
	require_once YS_FCT_STATUS_DIR . 'vendor/autoload.php';
}

// ── PSR-4 autoloader ─────────────────────────────────────────────────────────
// YangSheep\FluentCart\OrderStatuses\ maps to src/.

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'YangSheep\\FluentCart\\OrderStatuses\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = YS_FCT_STATUS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// ── Activation ───────────────────────────────────────────────────────────────
// Seed the option so `wp option get ys_fct_status_settings` answers straight
// after activation. `add_option` never overwrites, so deactivate/reactivate
// keeps whatever the operator configured — which matters here more than usual,
// because orders in the database may be sitting on those custom slugs.

register_activation_hook(
	YS_FCT_STATUS_FILE,
	static function () {
		add_option(
			\YangSheep\FluentCart\OrderStatuses\Settings::OPTION,
			\YangSheep\FluentCart\OrderStatuses\Settings::defaults()
		);

		\YangSheep\FluentCart\OrderStatuses\Support\Schema::install();
	}
);

// ── Deactivation ─────────────────────────────────────────────────────────────
// Only the cron event. The settings, the history table and — above all — the
// order rows sitting on custom slugs are left exactly as they are: deactivating
// is not uninstalling, and a shop that switches this off for ten minutes must
// get its workflow history back when it switches it on again.

register_deactivation_hook(
	YS_FCT_STATUS_FILE,
	static function () {
		\YangSheep\FluentCart\OrderStatuses\Reports\DailySummary::clearSchedule();
	}
);

// ── Bootstrap ────────────────────────────────────────────────────────────────

\YangSheep\FluentCart\OrderStatuses\Bootstrap::init();

// ── YS Plugin Hub Client registration (priority 5, before the main bootstrap) ─
// Registers this plugin with the YS Plugin Hub so it can receive auto-updates.

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( '\YangSheep\PluginHubClient\YSPluginHubClient' ) ) {
			\YangSheep\PluginHubClient\YSPluginHubClient::register(
				array(
					'slug'        => YS_FCT_STATUS_SLUG,
					'version'     => YS_FCT_STATUS_VERSION,
					'plugin_file' => YS_FCT_STATUS_FILE,
					'name'        => 'YS FluentCart Order Statuses',
				)
			);
		}
	},
	5
);
