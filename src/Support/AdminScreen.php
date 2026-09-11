<?php
/**
 * "Are we on one of FluentCart's own admin screens?"
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One answer, two callers.
 *
 * `Admin\ColorStyles` needs it to decide where to paint badges;
 * `Admin\OrderWidget` needs it to decide where to load the script that drives
 * the status control. Both must say yes to FluentCart's SPA and no to this
 * plugin's own settings page, which renders its own colour swatches and its own
 * controls and must not have either rewritten underneath it.
 */
final class AdminScreen {

	/**
	 * @param string $hook Current admin page hook.
	 * @return bool
	 */
	public static function isFluentCart( $hook ) {
		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( 0 === strpos( $page, 'ys-fct-' ) ) {
			return false;
		}

		if ( 'fluent-cart' === $page || 0 === strpos( $page, 'fluent-cart' ) ) {
			return true;
		}

		return is_string( $hook ) && false !== strpos( $hook, 'fluent-cart' ) && false === strpos( $hook, 'ys-fct-' );
	}
}
