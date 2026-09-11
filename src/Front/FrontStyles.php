<?php
/**
 * Painting and relabelling status badges in the customer dashboard.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Front;

use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\LabelTagger;
use YangSheep\FluentCart\OrderStatuses\Support\StatusCss;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The storefront half of `Admin\ColorStyles`, and it has an easier job.
 * Measured against FluentCart 1.6.3, the customer dashboard renders a status as
 *
 *     <span class="fct-badge fct-sourcing fct-small"><!---->Sourcing</span>
 *
 * — a slug it does not recognise goes straight into a class, which is a CSS
 * hook the admin never gives us (built-in slugs become `fct-warning`,
 * `fct-danger` and so on). So the colours here work with no JavaScript at all;
 * the tagger is only needed for the text, which is still the humanised slug
 * rather than the configured label.
 *
 * Printed inline rather than as two extra files: this is a few hundred bytes
 * that only matters on pages showing an order, and two more requests on a
 * storefront page is the worse trade.
 */
final class FrontStyles {

	const HANDLE = 'ys-fct-status-front';

	/** The customer dashboard's badge, plus the receipt/admin spellings. */
	const SELECTOR = '.fct-badge, span.badge, .fct_order_status, .fct-order-status, .el-tag';

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 100 );
	}

	/**
	 * @return void
	 */
	public function enqueue() {
		if ( is_admin() ) {
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
}
