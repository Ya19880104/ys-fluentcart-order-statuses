<?php
/**
 * Writing to FluentCart's order activity feed.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper over `fluent_cart_add_log()`.
 *
 * Everything this add-on does behind the operator's back — restoring a status
 * after payment, migrating orders off a deleted status — leaves a line in the
 * order's own activity timeline, so the history explains itself without anyone
 * needing to know the plugin exists.
 */
final class ActivityLog {

	/**
	 * @param int    $orderId Order id.
	 * @param string $title   Short title.
	 * @param string $content Body.
	 * @param string $status  'success', 'info', 'warning' or 'error'.
	 * @return void
	 */
	public static function order( $orderId, $title, $content, $status = 'success' ) {
		if ( ! function_exists( 'fluent_cart_add_log' ) ) {
			return;
		}

		$orderId = (int) $orderId;

		if ( $orderId <= 0 ) {
			return;
		}

		fluent_cart_add_log(
			$title,
			$content,
			$status,
			array(
				'module_type' => 'FluentCart\\App\\Models\\Order',
				'module_id'   => $orderId,
				'module_name' => 'order',
			)
		);
	}
}
