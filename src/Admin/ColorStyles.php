<?php
/**
 * Painting and relabelling status badges in the FluentCart admin.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Admin;

use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\LabelTagger;
use YangSheep\FluentCart\OrderStatuses\Support\StatusCss;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads `Support\LabelTagger` on FluentCart's own admin pages.
 *
 * See that class for what was measured and why a DOM pass is the only option
 * short of shipping a Vue build.
 */
final class ColorStyles {

	const HANDLE = 'ys-fct-status-colors';

	/** What FluentCart 1.6.3 renders a status into, in the list and the detail view. */
	const SELECTOR = 'span.badge, .el-tag';

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 100 );
	}

	/**
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ! $this->isFluentCartScreen( $hook ) ) {
			return;
		}

		$settings = StatusRegistry::settings();

		if ( ! LabelTagger::hasAnything( $settings ) ) {
			return;
		}

		$css = StatusCss::build( StatusRegistry::colorMap() );

		if ( '' !== $css ) {
			wp_register_style( self::HANDLE, false, array(), YS_FCT_STATUS_VERSION );
			wp_enqueue_style( self::HANDLE );
			wp_add_inline_style( self::HANDLE, $css );
		}

		$map = LabelTagger::map( $settings );

		if ( empty( $map ) ) {
			return;
		}

		wp_register_script( self::HANDLE, false, array(), YS_FCT_STATUS_VERSION, true );
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script( self::HANDLE, LabelTagger::script( $map, self::SELECTOR ) );
	}

	/**
	 * Only FluentCart's own screens — this plugin's settings page renders its
	 * own colour swatches and must not have its markup rewritten.
	 *
	 * @param string $hook Current admin page hook.
	 * @return bool
	 */
	private function isFluentCartScreen( $hook ) {
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
