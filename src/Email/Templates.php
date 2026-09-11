<?php
/**
 * Pointing FluentCart's view renderer at a file inside this plugin.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `fluent_cart/email/template_view_path`.
 *
 * `TemplateService::getTemplateByPathName($name, $viewData)` prefixes core
 * template paths with `emails.` and then applies this filter with the raw
 * `template_path` in the context array. The framework's `View::make()` tries
 * `$path . '.php'` with `file_exists()` **first**, so returning an absolute
 * file path is enough — no view namespace to register, nothing added to
 * FluentCart's own view directory.
 *
 * The filter is also where the plugin learns *which* status is being rendered.
 * The view data is the event payload (`order`, `old_status`, `new_status`, …)
 * for a real send, but the preview endpoint renders the same template with a
 * sample order and no `new_status` at all — so the status has to be derived
 * from the template path, which is present in both cases. A static set here and
 * read by the view is the narrowest way to carry it: the render happens on the
 * very next line, inside the same call.
 */
final class Templates {

	/** @var string The `ys-status.…` path being rendered right now, or ''. */
	private static $current = '';

	/**
	 * @return void
	 */
	public function register() {
		add_filter( 'fluent_cart/email/template_view_path', array( __CLASS__, 'resolve' ), 10, 2 );
	}

	/**
	 * @param mixed $viewPath The path core resolved.
	 * @param mixed $context  `['template_path' => string, 'view_data' => array]`.
	 * @return mixed
	 */
	public static function resolve( $viewPath, $context = array() ) {
		$path = is_array( $context ) && isset( $context['template_path'] ) ? (string) $context['template_path'] : '';

		if ( null === NotificationRegistry::parseTemplatePath( $path ) ) {
			return $viewPath;
		}

		self::$current = $path;

		return YS_FCT_STATUS_DIR . 'templates/email/status-changed.php';
	}

	/**
	 * @return string
	 */
	public static function currentPath() {
		return self::$current;
	}

	/**
	 * Test seam.
	 *
	 * @param string $path Template path.
	 * @return void
	 */
	public static function setCurrentPath( $path ) {
		self::$current = (string) $path;
	}
}
