<?php
/**
 * Who may manage custom statuses.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defers to FluentCart's permission map so a shop manager who can already
 * change an order's status can also define the statuses, and falls back to
 * `manage_options` when that service is unavailable.
 */
final class Permissions {

	/** Capability used to register the WP submenu. */
	const FALLBACK_CAP = 'manage_options';

	/** FluentCart permission this screen is the settings side of. */
	const FCT_PERMISSION = 'orders/manage';

	/** What it takes to read an order's history. */
	const FCT_VIEW_PERMISSION = 'orders/view';

	/**
	 * @return bool
	 */
	public static function canManage() {
		if ( current_user_can( self::FALLBACK_CAP ) ) {
			return true;
		}

		$manager = '\\FluentCart\\App\\Services\\Permission\\PermissionManager';

		if ( class_exists( $manager ) && method_exists( $manager, 'hasPermission' ) ) {
			try {
				return (bool) $manager::hasPermission( array( self::FCT_PERMISSION ) );
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Who may read an order's status history.
	 *
	 * Lower bar than `canManage()` on purpose: the order-page widget is
	 * read-only history about an order the viewer already has open, so a role
	 * that can look at orders can look at how they got there. It is still a
	 * bar — FluentCart's widgets route admits `customers/view` alone, and a
	 * customer-only role has no business reading order workflow.
	 *
	 * @return bool
	 */
	public static function canViewOrders() {
		if ( current_user_can( self::FALLBACK_CAP ) ) {
			return true;
		}

		$manager = '\\FluentCart\\App\\Services\\Permission\\PermissionManager';

		if ( class_exists( $manager ) && method_exists( $manager, 'hasPermission' ) ) {
			try {
				return (bool) $manager::hasPermission( array( self::FCT_VIEW_PERMISSION ) );
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * REST permission callback: capability plus a valid REST nonce.
	 *
	 * The nonce matters even though every route is capability-gated — without
	 * it, a logged-in administrator visiting a hostile page could be made to
	 * rewrite every custom status, and one of these routes rewrites order rows.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return true|\WP_Error
	 */
	public static function restCanManage( $request ) {
		if ( ! self::canManage() ) {
			return new \WP_Error(
				'ys_fct_status_forbidden',
				__( 'You are not allowed to manage order statuses.', 'ys-fluentcart-order-statuses' ),
				array( 'status' => 403 )
			);
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'ys_fct_status_bad_nonce',
				__( 'Your session expired. Please reload the page and try again.', 'ys-fluentcart-order-statuses' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
