<?php
/**
 * The settings screen's REST surface.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Rest;

use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\ActivityLog;
use YangSheep\FluentCart\OrderStatuses\Support\OrderRepository;
use YangSheep\FluentCart\OrderStatuses\Support\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `ys-fct-status/v1`.
 *
 * Six routes, all administrator-only and all nonce-checked. The client posts
 * the whole settings document rather than patching individual statuses: the
 * document is small, the sanitiser is a whitelist that has to see all of it at
 * once to catch duplicate slugs, and "save" then means the same thing for a new
 * status, a reorder and an import.
 */
final class StatusController {

	const NAMESPACE_V1 = 'ys-fct-status/v1';

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * @return void
	 */
	public function registerRoutes() {
		$permission = array( Permissions::class, 'restCanManage' );

		register_rest_route(
			self::NAMESPACE_V1,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'getSettings' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'saveSettings' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/usage',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'getUsage' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/migrate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'migrate' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function getSettings() {
		return rest_ensure_response(
			array(
				'settings' => Settings::all(),
				'builtin'  => $this->builtinLabels(),
				'usage'    => $this->usageMap(),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function saveSettings( $request ) {
		$incoming = $request->get_param( 'settings' );

		if ( ! is_array( $incoming ) ) {
			return new \WP_Error(
				'ys_fct_status_bad_payload',
				__( 'No settings were supplied.', 'ys-fluentcart-order-statuses' ),
				array( 'status' => 400 )
			);
		}

		$rejected = $this->rejectedSlugs( $incoming );

		// The sanitiser silently drops an invalid definition, which is the right
		// behaviour for a stored row but a terrible one for a form: the operator
		// would press Save and watch their status vanish without a word.
		if ( ! empty( $rejected ) ) {
			return new \WP_Error(
				'ys_fct_status_invalid_slug',
				implode( ' ', $rejected ),
				array(
					'status'   => 422,
					'rejected' => $rejected,
				)
			);
		}

		$saved = Settings::save( $incoming );

		StatusRegistry::flushCache();

		return rest_ensure_response(
			array(
				'settings' => $saved,
				'builtin'  => $this->builtinLabels(),
				'usage'    => $this->usageMap(),
				'message'  => __( 'Statuses saved.', 'ys-fluentcart-order-statuses' ),
			)
		);
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function getUsage() {
		return rest_ensure_response( array( 'usage' => $this->usageMap() ) );
	}

	/**
	 * Move every order off one status onto another.
	 *
	 * Used when deleting a status that is still in use — but deliberately a
	 * route of its own, because it rewrites order rows and should be auditable
	 * separately from "the operator saved the settings form".
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function migrate( $request ) {
		$axis = (string) $request->get_param( 'axis' );
		$from = Settings::sanitizeSlug( $request->get_param( 'from' ) );
		$to   = Settings::sanitizeSlug( $request->get_param( 'to' ) );

		if ( ! in_array( $axis, Settings::AXES, true ) ) {
			return new \WP_Error( 'ys_fct_status_bad_axis', __( 'Unknown status axis.', 'ys-fluentcart-order-statuses' ), array( 'status' => 400 ) );
		}

		if ( '' === $from || '' === $to || $from === $to ) {
			return new \WP_Error( 'ys_fct_status_bad_migration', __( 'Pick a different status to move these orders to.', 'ys-fluentcart-order-statuses' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $to, $this->knownSlugs( $axis ), true ) ) {
			return new \WP_Error( 'ys_fct_status_unknown_target', __( 'That target status does not exist.', 'ys-fluentcart-order-statuses' ), array( 'status' => 400 ) );
		}

		$ids     = OrderRepository::idsWithStatus( $axis, $from, 5000 );
		$changed = OrderRepository::migrateStatus( $axis, $from, $to );

		$labels = $this->allLabels( $axis );
		$fromLabel = isset( $labels[ $from ] ) ? $labels[ $from ] : $from;
		$toLabel   = isset( $labels[ $to ] ) ? $labels[ $to ] : $to;

		foreach ( $ids as $orderId ) {
			ActivityLog::order(
				$orderId,
				'shipping' === $axis
					? __( 'Shipping status migrated', 'ys-fluentcart-order-statuses' )
					: __( 'Order status migrated', 'ys-fluentcart-order-statuses' ),
				sprintf(
					/* translators: 1: old status label, 2: new status label */
					__( 'The custom status %1$s was removed. This order was moved to %2$s.', 'ys-fluentcart-order-statuses' ),
					$fromLabel,
					$toLabel
				),
				'warning'
			);
		}

		return rest_ensure_response(
			array(
				'moved'   => $changed,
				'usage'   => $this->usageMap(),
				'message' => sprintf(
					/* translators: 1: number of orders, 2: target status label */
					_n( '%1$d order moved to %2$s.', '%1$d orders moved to %2$s.', $changed, 'ys-fluentcart-order-statuses' ),
					$changed,
					$toLabel
				),
			)
		);
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function export() {
		$settings = Settings::all();

		return rest_ensure_response(
			array(
				'exported_at' => gmdate( 'c' ),
				'plugin'      => YS_FCT_STATUS_SLUG,
				'version'     => YS_FCT_STATUS_VERSION,
				'settings'    => $settings,
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( $request ) {
		$payload = $request->get_param( 'payload' );

		if ( is_string( $payload ) ) {
			$payload = json_decode( $payload, true );
		}

		if ( ! is_array( $payload ) ) {
			return new \WP_Error(
				'ys_fct_status_bad_json',
				__( 'That does not look like an exported status file.', 'ys-fluentcart-order-statuses' ),
				array( 'status' => 400 )
			);
		}

		// Accept both the wrapped export and a bare settings document; the
		// sanitiser is the same whitelist either way, so nothing from the file
		// reaches the option unfiltered.
		$settings = isset( $payload['settings'] ) && is_array( $payload['settings'] ) ? $payload['settings'] : $payload;

		$saved = Settings::save( $settings );

		StatusRegistry::flushCache();

		return rest_ensure_response(
			array(
				'settings' => $saved,
				'builtin'  => $this->builtinLabels(),
				'usage'    => $this->usageMap(),
				'message'  => sprintf(
					/* translators: 1: custom order statuses, 2: custom shipping statuses */
					__( 'Imported %1$d order statuses and %2$d shipping statuses.', 'ys-fluentcart-order-statuses' ),
					count( $saved['order'] ),
					count( $saved['shipping'] )
				),
			)
		);
	}

	/**
	 * Slug => count for every custom and built-in slug on both axes.
	 *
	 * @return array<string,array<string,int>>
	 */
	private function usageMap() {
		if ( ! OrderRepository::tableExists() ) {
			return array(
				'order'    => array(),
				'shipping' => array(),
			);
		}

		return array(
			'order'    => OrderRepository::countByStatus( 'order', $this->knownSlugs( 'order' ) ),
			'shipping' => OrderRepository::countByStatus( 'shipping', $this->knownSlugs( 'shipping' ) ),
		);
	}

	/**
	 * @param string $axis 'order' or 'shipping'.
	 * @return string[] Built-in plus configured custom slugs.
	 */
	private function knownSlugs( $axis ) {
		$settings = Settings::all();
		$builtin  = 'shipping' === $axis ? Settings::BUILTIN_SHIPPING : Settings::BUILTIN_ORDER;

		$custom = array();

		foreach ( $settings[ $axis ] as $definition ) {
			$custom[] = $definition['slug'];
		}

		return array_values( array_unique( array_merge( $builtin, $custom ) ) );
	}

	/**
	 * @param string $axis 'order' or 'shipping'.
	 * @return array<string,string> slug => label, built-ins and customs.
	 */
	private function allLabels( $axis ) {
		$labels   = $this->builtinLabels();
		$out      = isset( $labels[ $axis ] ) ? $labels[ $axis ] : array();
		$settings = Settings::all();

		foreach ( $settings[ $axis ] as $definition ) {
			$out[ $definition['slug'] ] = $definition['label'];
		}

		return $out;
	}

	/**
	 * FluentCart's own labels for the statuses it owns.
	 *
	 * Hardcoded rather than read back through `Status::getOrderStatuses()`:
	 * that method is filtered by this very plugin, so reading it here would
	 * show the operator their own overrides as if they were the defaults, and
	 * "reset to default" would then be a no-op.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function builtinLabels() {
		return array(
			'order'    => array(
				'processing' => __( 'Processing', 'ys-fluentcart-order-statuses' ),
				'completed'  => __( 'Completed', 'ys-fluentcart-order-statuses' ),
				'on-hold'    => __( 'On Hold', 'ys-fluentcart-order-statuses' ),
				'canceled'   => __( 'Canceled', 'ys-fluentcart-order-statuses' ),
				'failed'     => __( 'Failed', 'ys-fluentcart-order-statuses' ),
			),
			'payment'  => array(
				'pending'            => __( 'Pending', 'ys-fluentcart-order-statuses' ),
				'paid'               => __( 'Paid', 'ys-fluentcart-order-statuses' ),
				'partially_paid'     => __( 'Partially Paid', 'ys-fluentcart-order-statuses' ),
				'failed'             => __( 'Failed', 'ys-fluentcart-order-statuses' ),
				'refunded'           => __( 'Refunded', 'ys-fluentcart-order-statuses' ),
				'partially_refunded' => __( 'Partially Refunded', 'ys-fluentcart-order-statuses' ),
				'authorized'         => __( 'Authorized', 'ys-fluentcart-order-statuses' ),
				'payment_scheduled'  => __( 'Payment Scheduled', 'ys-fluentcart-order-statuses' ),
			),
			'shipping' => array(
				'unshipped'   => __( 'Unshipped', 'ys-fluentcart-order-statuses' ),
				'shipped'     => __( 'Shipped', 'ys-fluentcart-order-statuses' ),
				'delivered'   => __( 'Delivered', 'ys-fluentcart-order-statuses' ),
				'unshippable' => __( 'Unshippable', 'ys-fluentcart-order-statuses' ),
			),
		);
	}

	/**
	 * Human-readable complaints about every slug the sanitiser would drop.
	 *
	 * @param array $incoming Raw settings document.
	 * @return string[]
	 */
	private function rejectedSlugs( array $incoming ) {
		$messages = array();

		foreach ( Settings::AXES as $axis ) {
			$definitions = isset( $incoming[ $axis ] ) && is_array( $incoming[ $axis ] ) ? $incoming[ $axis ] : array();
			$seen        = array();

			foreach ( $definitions as $definition ) {
				if ( ! is_array( $definition ) ) {
					continue;
				}

				$raw  = isset( $definition['slug'] ) ? (string) $definition['slug'] : '';
				$slug = Settings::sanitizeSlug( $raw );
				$code = Settings::slugError( $slug, $axis, $seen );

				if ( null === $code ) {
					$seen[] = $slug;
					continue;
				}

				$messages[] = self::slugErrorMessage( $code, '' === $raw ? $slug : $raw );
			}
		}

		return $messages;
	}

	/**
	 * @param string $code Reason code from Settings::slugError().
	 * @param string $slug The offending slug, as typed.
	 * @return string
	 */
	public static function slugErrorMessage( $code, $slug ) {
		switch ( $code ) {
			case 'too_long':
				return sprintf(
					/* translators: 1: slug, 2: maximum length */
					__( '“%1$s” is too long — a status slug is stored in a VARCHAR(%2$d) column, so it must be %2$d characters or fewer.', 'ys-fluentcart-order-statuses' ),
					$slug,
					Settings::MAX_SLUG_LENGTH
				);

			case 'reserved':
				return sprintf(
					/* translators: %s: slug */
					__( '“%s” is one of FluentCart\'s own statuses. Rename it on the Built-in labels tab instead of redefining it.', 'ys-fluentcart-order-statuses' ),
					$slug
				);

			case 'duplicate':
				return sprintf(
					/* translators: %s: slug */
					__( '“%s” is used twice. Each slug must be unique.', 'ys-fluentcart-order-statuses' ),
					$slug
				);

			case 'invalid_characters':
				return sprintf(
					/* translators: %s: slug */
					__( '“%s” is not a valid slug — use lowercase letters, digits, underscores and dashes, starting with a letter.', 'ys-fluentcart-order-statuses' ),
					$slug
				);

			case 'empty':
			default:
				return __( 'Every status needs a slug.', 'ys-fluentcart-order-statuses' );
		}
	}
}
