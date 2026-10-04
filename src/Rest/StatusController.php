<?php
/**
 * The settings screen's REST surface.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Rest;

use YangSheep\FluentCart\OrderStatuses\Email\ContentStore;
use YangSheep\FluentCart\OrderStatuses\Email\NotificationRegistry;
use YangSheep\FluentCart\OrderStatuses\History\HistoryRepository;
use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\ActivityLog;
use YangSheep\FluentCart\OrderStatuses\Support\Labels;
use YangSheep\FluentCart\OrderStatuses\Support\OrderRepository;
use YangSheep\FluentCart\OrderStatuses\Support\Permissions;
use YangSheep\FluentCart\OrderStatuses\Support\SettingsBackup;
use YangSheep\FluentCart\OrderStatuses\Support\SettingsChange;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `ys-fct-status/v1`.
 *
 * Seven routes, all administrator-only and all nonce-checked. The client posts
 * the whole settings document rather than patching individual statuses: the
 * document is small, the sanitiser is a whitelist that has to see all of it at
 * once to catch duplicate slugs, and "save" then means the same thing for a new
 * status, a reorder and an import.
 */
final class StatusController {

	const NAMESPACE_V1 = 'ys-fct-status/v1';

	/** Orders per UPDATE / history INSERT when moving orders in bulk. */
	const MOVE_BATCH = 500;

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

		register_rest_route(
			self::NAMESPACE_V1,
			'/import/undo',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'undoImport' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function getSettings() {
		return rest_ensure_response( $this->screenPayload( Settings::all() ) );
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
			return self::slugRefusal( $rejected );
		}

		$inUse = $this->inUseRefusal( Settings::all(), $incoming );

		if ( null !== $inUse ) {
			return $inUse;
		}

		$saved = Settings::save( $incoming );

		StatusRegistry::flushCache();
		NotificationRegistry::flushCache();

		// A status that no longer exists has no notification, so its stored
		// heading and message would be unreachable text in an option row and in
		// every export made from here on.
		ContentStore::pruneOrphans();

		return rest_ensure_response(
			$this->screenPayload( $saved, array( 'message' => __( 'Statuses saved.', 'ys-fluentcart-order-statuses' ) ) )
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
	 * Used to empty a status before removing it, and to rescue orders left on a
	 * status that no longer exists. Deliberately a route of its own, and
	 * deliberately narrow, because it writes the order rows directly: no
	 * FluentCart event fires, so no e-mail goes out, no stock moves and none of
	 * FluentCart's automations run. That is why the source may not be one of
	 * FluentCart's own statuses and the target has to be a place to wait
	 * (`Settings::moveTargets()`), and why every moved order gets a history row
	 * and an activity line here — nothing else will record it.
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

		$settings  = Settings::all();
		$fromLabel = $this->labelFor( $axis, $from, $settings );
		$toLabel   = $this->labelFor( $axis, $to, $settings );

		switch ( Settings::moveError( $axis, $from, $to, $settings ) ) {
			case 'from_builtin':
				return new \WP_Error(
					'ys_fct_status_builtin_source',
					sprintf(
						/* translators: %s: status label */
						__( 'Orders on “%s” cannot be moved in bulk: it is one of FluentCart’s own statuses. Change those orders one at a time on the order page.', 'ys-fluentcart-order-statuses' ),
						$fromLabel
					),
					array( 'status' => 400 )
				);

			case 'bad_target':
				return new \WP_Error(
					'ys_fct_status_bad_target',
					'shipping' === $axis
						? sprintf(
							/* translators: %s: label of the built-in Unshipped status */
							__( 'Orders can only be moved in bulk to “%s” or to one of your enabled shipping statuses.', 'ys-fluentcart-order-statuses' ),
							$this->labelFor( 'shipping', 'unshipped', $settings )
						)
						: sprintf(
							/* translators: 1: label of the built-in Processing status, 2: label of the built-in On Hold status */
							__( 'Orders can only be moved in bulk to “%1$s”, “%2$s” or one of your enabled order statuses.', 'ys-fluentcart-order-statuses' ),
							$this->labelFor( 'order', 'processing', $settings ),
							$this->labelFor( 'order', 'on-hold', $settings )
						),
					array( 'status' => 400 )
				);
		}

		$changed = $this->moveInBatches( $axis, $from, $to, $fromLabel, $toLabel );

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
				'exported_at'   => gmdate( 'c' ),
				'plugin'        => YS_FCT_STATUS_SLUG,
				'version'       => YS_FCT_STATUS_VERSION,
				'settings'      => $settings,
				// Beside `settings`, not inside it: this map is keyed by
				// FluentCart notification name rather than by status, and it is
				// written by FluentCart's own editor. Schema version 3.
				'email_content' => ContentStore::all(),
			)
		);
	}

	/**
	 * Replace the configuration with an exported file.
	 *
	 * Held to the same rules as Save — the same slug sentences, the same
	 * in-use refusal — plus one of its own: the file has to be an export.
	 * `dry_run` answers what the import would change without writing, which is
	 * what the settings screen puts in its confirm dialog. A real import keeps
	 * the configuration it replaces for one step of undo.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( $request ) {
		$payload = $request->get_param( 'payload' );

		if ( is_string( $payload ) ) {
			$payload = json_decode( $payload, true );
		}

		// Only a file this plugin exported. Until 0.6 any JSON object was taken
		// as the whole settings document, so `{}` emptied the configuration.
		// Every export since 0.1 carries this envelope, a 0.3 one included.
		if ( ! SettingsChange::isExportEnvelope( $payload ) ) {
			return new \WP_Error(
				'ys_fct_status_bad_json',
				__( 'That does not look like an exported status file.', 'ys-fluentcart-order-statuses' ),
				array( 'status' => 400 )
			);
		}

		$incoming = $payload['settings'];
		$rejected = $this->rejectedSlugs( $incoming );

		if ( ! empty( $rejected ) ) {
			return self::slugRefusal( $rejected );
		}

		$stored = Settings::all();
		$inUse  = $this->inUseRefusal( $stored, $incoming );

		if ( null !== $inUse ) {
			return $inUse;
		}

		// A 0.3 export has no `email_content` key at all, and that is not an
		// error: the statuses are imported and whatever e-mail text this site
		// already has is left alone. Only a document that carries the key
		// replaces it.
		$hasEmail = isset( $payload['email_content'] ) && is_array( $payload['email_content'] );

		if ( rest_sanitize_boolean( $request->get_param( 'dry_run' ) ) ) {
			return rest_ensure_response( $this->importPreview( $stored, Settings::sanitize( $incoming ), $hasEmail ? $payload['email_content'] : null ) );
		}

		SettingsBackup::store( $stored, ContentStore::all() );

		$saved = Settings::save( $incoming );

		StatusRegistry::flushCache();
		NotificationRegistry::flushCache();

		if ( $hasEmail ) {
			ContentStore::replaceAll( $payload['email_content'] );
		}

		ContentStore::pruneOrphans();

		return rest_ensure_response(
			$this->screenPayload(
				$saved,
				array(
					'message' => sprintf(
						/* translators: 1: custom order statuses, 2: custom shipping statuses */
						__( 'Imported %1$d order statuses and %2$d shipping statuses.', 'ys-fluentcart-order-statuses' ),
						count( $saved['order'] ),
						count( $saved['shipping'] )
					),
				)
			)
		);
	}

	/**
	 * Put back the configuration the last import replaced.
	 *
	 * The in-use rule applies here too: orders may have been moved onto a
	 * status the import added, and undoing would strand them.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function undoImport( $request ) {
		unset( $request );

		$backup = SettingsBackup::get();

		if ( null === $backup ) {
			return new \WP_Error(
				'ys_fct_status_no_backup',
				__( 'There is no import to undo.', 'ys-fluentcart-order-statuses' ),
				array( 'status' => 404 )
			);
		}

		$inUse = $this->inUseRefusal( Settings::all(), $backup['settings'] );

		if ( null !== $inUse ) {
			return $inUse;
		}

		$saved = Settings::save( $backup['settings'] );

		StatusRegistry::flushCache();
		NotificationRegistry::flushCache();

		ContentStore::replaceAll( $backup['email_content'] );
		ContentStore::pruneOrphans();
		SettingsBackup::clear();

		return rest_ensure_response(
			$this->screenPayload(
				$saved,
				array( 'message' => __( 'The last import was undone. The statuses, settings and e-mail text are back as they were before it.', 'ys-fluentcart-order-statuses' ) )
			)
		);
	}

	/**
	 * What an import would change, without changing it.
	 *
	 * @param array      $stored       Normalised stored settings.
	 * @param array      $incoming     Normalised incoming settings.
	 * @param array|null $emailContent The file's e-mail content, or null when it has none.
	 * @return array
	 */
	private function importPreview( array $stored, array $incoming, $emailContent ) {
		$diff  = SettingsChange::diff( $stored, $incoming );
		$lines = SettingsChange::summaryLines( $diff );

		// Loose on purpose: the same rows in a different key order are not a change.
		$emailChanges = is_array( $emailContent ) && ContentStore::sanitizeAll( $emailContent ) != ContentStore::all(); // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual

		if ( $emailChanges ) {
			$lines[] = __( 'The e-mail headings and messages are replaced with the ones in the file.', 'ys-fluentcart-order-statuses' );
		}

		$diff['email_content'] = $emailChanges;

		return array(
			'dry_run' => true,
			'changes' => $diff,
			'empty'   => SettingsChange::isEmpty( $diff ) && ! $emailChanges,
			'summary' => $lines,
		);
	}

	/**
	 * The document the settings screen renders from, after any write.
	 *
	 * @param array $settings Normalised settings.
	 * @param array $extra    Keys to add, such as `message`.
	 * @return array
	 */
	private function screenPayload( array $settings, array $extra = array() ) {
		return array_merge(
			array(
				'settings'     => $settings,
				'builtin'      => $this->builtinLabels(),
				'usage'        => $this->usageMap(),
				'emails'       => NotificationRegistry::stateMap(),
				'backup'       => SettingsBackup::meta(),
				'move_targets' => array(
					'order'    => Settings::moveTargets( 'order', $settings ),
					'shipping' => Settings::moveTargets( 'shipping', $settings ),
				),
			),
			$extra
		);
	}

	/**
	 * @param string[] $rejected `rejectedSlugs()`.
	 * @return \WP_Error
	 */
	private static function slugRefusal( array $rejected ) {
		return new \WP_Error(
			'ys_fct_status_invalid_slug',
			implode( ' ', $rejected ),
			array(
				'status'   => 422,
				'rejected' => $rejected,
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
	 * The name an operator knows a slug by: its definition (enabled or not),
	 * the renamed or original built-in name, or the slug itself.
	 *
	 * @param string $axis     'order' or 'shipping'.
	 * @param string $slug     Slug.
	 * @param array  $settings Normalised settings.
	 * @return string
	 */
	private function labelFor( $axis, $slug, array $settings ) {
		$definition = Settings::definition( $axis, $slug, $settings );

		if ( null !== $definition ) {
			return $definition['label'];
		}

		return Labels::forSlug( $axis, $slug, $settings );
	}

	/**
	 * Move orders in batches, recording each one where nothing else will.
	 *
	 * No cap: every order on the source status is moved, a batch of ids at a
	 * time so neither the UPDATE nor the history INSERT grows without bound.
	 *
	 * @param string $axis      'order' or 'shipping'.
	 * @param string $from      Source slug.
	 * @param string $to        Target slug.
	 * @param string $fromLabel Source label.
	 * @param string $toLabel   Target label.
	 * @return int Orders moved.
	 */
	private function moveInBatches( $axis, $from, $to, $fromLabel, $toLabel ) {
		$title = 'shipping' === $axis
			? __( 'Shipping status moved in bulk', 'ys-fluentcart-order-statuses' )
			: __( 'Order status moved in bulk', 'ys-fluentcart-order-statuses' );

		$note = sprintf(
			'shipping' === $axis
				/* translators: 1: old status label, 2: new status label */
				? __( 'Shipping status moved in bulk from “%1$s” to “%2$s”.', 'ys-fluentcart-order-statuses' )
				/* translators: 1: old status label, 2: new status label */
				: __( 'Order status moved in bulk from “%1$s” to “%2$s”.', 'ys-fluentcart-order-statuses' ),
			$fromLabel,
			$toLabel
		);

		$moved = 0;

		foreach ( array_chunk( OrderRepository::idsWithStatus( $axis, $from, 0 ), self::MOVE_BATCH ) as $batch ) {
			$done = OrderRepository::moveOrders( $axis, $batch, $from, $to );

			if ( empty( $done ) ) {
				continue;
			}

			HistoryRepository::recordMany( $done, $axis, $from, $to, 'migrate' );

			foreach ( $done as $orderId ) {
				ActivityLog::order( $orderId, $title, $note, 'info' );
			}

			$moved += count( $done );
		}

		return $moved;
	}

	/**
	 * FluentCart's own labels for the statuses it owns.
	 *
	 * Hardcoded rather than read back through `Status::getOrderStatuses()`:
	 * that method is filtered by this very plugin, so reading it here would
	 * show the operator their own overrides as if they were the defaults, and
	 * "reset to default" would then be a no-op. See `Support\Labels`.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function builtinLabels() {
		return Labels::builtin();
	}

	/**
	 * Refuse a document that would leave orders on a status it no longer has.
	 *
	 * The settings screen has always blocked Remove on a status in use, but only
	 * in the browser: the route itself accepted anything, so a stale tab, a
	 * script or a hand-written request could delete — or re-slug — a status with
	 * orders on it. Those orders then show a raw slug, are never restored after
	 * payment, and lose their e-mail text. Checked here, per axis, before a
	 * single byte is written.
	 *
	 * @param array $stored   Normalised stored settings.
	 * @param array $incoming Incoming document.
	 * @return \WP_Error|null
	 */
	private function inUseRefusal( array $stored, array $incoming ) {
		$removed = SettingsChange::removed( $stored, $incoming );
		$counts  = array();

		foreach ( $removed as $axis => $slugs ) {
			$counts[ $axis ] = empty( $slugs ) || ! OrderRepository::tableExists()
				? array()
				: OrderRepository::countByStatus( $axis, array_map( 'strval', array_keys( $slugs ) ) );
		}

		$messages = SettingsChange::inUseMessages( $removed, $counts );

		if ( empty( $messages ) ) {
			return null;
		}

		return new \WP_Error(
			'ys_fct_status_in_use',
			implode( ' ', $messages ),
			array(
				'status' => 422,
				'in_use' => $messages,
			)
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
