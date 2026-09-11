<?php
/**
 * Turning a slug => colour map into a stylesheet.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One generator for both the admin and the storefront, so a status is the same
 * colour wherever it is shown.
 *
 * Two hooks, because the two surfaces render differently (measured against
 * FluentCart 1.6.3):
 *
 * - `[data-ys-status="<slug>"]` — the attribute `Support\LabelTagger` stamps on.
 *   The only option in the admin, whose badge is `<span class="badge info">`
 *   with no trace of the slug.
 * - `.fct-badge.fct-<slug>` — the storefront customer dashboard renders
 *   `<span class="fct-badge fct-sourcing fct-small">`, putting an unrecognised
 *   slug straight into a class. That one works with no JavaScript at all, so
 *   the storefront keeps its colours even if the tagger never runs.
 *
 * The colour is used twice: full strength for text and border, and at 14% alpha
 * for the background — hence splitting the hex into RGB components here.
 */
final class StatusCss {

	/**
	 * @param array<string,string> $colors Slug => `#rrggbb`.
	 * @return string CSS. Empty when there is nothing to paint.
	 */
	public static function build( array $colors ) {
		$rules = array();

		foreach ( $colors as $slug => $color ) {
			$slug = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $slug ) );

			if ( '' === $slug || ! preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $color ) ) {
				continue;
			}

			$rgb = self::rgb( $color );

			$rules[] = sprintf(
				'[data-ys-status="%1$s"],.fct-badge.fct-%1$s{--ys-status-color:%2$s;color:%2$s !important;'
				. 'background-color:rgba(%3$s,.14) !important;border-color:rgba(%3$s,.35) !important;}',
				$slug,
				$color,
				$rgb
			);

			// Themes that print a leading dot rather than a filled pill.
			$rules[] = sprintf(
				'[data-ys-status="%1$s"]::before,.fct-badge.fct-%1$s::before{background-color:%2$s;}',
				$slug,
				$color
			);
		}

		if ( empty( $rules ) ) {
			return '';
		}

		return implode( "\n", $rules );
	}

	/**
	 * @param string $hex `#rrggbb`.
	 * @return string `r,g,b`.
	 */
	private static function rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );

		return implode(
			',',
			array(
				hexdec( substr( $hex, 0, 2 ) ),
				hexdec( substr( $hex, 2, 2 ) ),
				hexdec( substr( $hex, 4, 2 ) ),
			)
		);
	}
}
