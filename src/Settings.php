<?php
/**
 * The single option row that holds every custom status and every label override.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `ys_fct_status_settings`, stored as an array (WordPress serialises it).
 *
 * Status definitions are small, hand-curated and have to be exportable as JSON,
 * so they live in one option rather than a table. Everything that reads them
 * goes through `all()`, which normalises an arbitrary stored array into the
 * documented shape — so a half-written row, a hand-edited row or a row from a
 * future version never reaches the FluentCart filters unvalidated.
 *
 * Nothing in this class calls into FluentCart: the built-in slug lists below
 * are duplicated deliberately, because the only place they could otherwise come
 * from is `Status::getOrderStatuses()`, and reading that from inside our own
 * filter on it would recurse.
 */
final class Settings {

	const OPTION = 'ys_fct_status_settings';

	/**
	 * Schema version of the stored row / exported JSON.
	 *
	 * 1 → 2 added `linked_shipping_status` to an order definition and the
	 * pipeline/report switches below. Both are additive and `sanitize()` fills
	 * in the defaults, so a version-1 row (or a version-1 export) is read
	 * without a migration step.
	 *
	 * 2 → 3 added the e-mail content option (`Email\ContentStore`). It is a
	 * second option rather than a key in this one — it is keyed by notification
	 * name, not by status, and it is written by FluentCart's own editor rather
	 * than by this plugin's settings screen — so the export document carries it
	 * beside `settings` rather than inside it, and an export written by 0.3 (no
	 * such key) imports unchanged.
	 */
	const SCHEMA_VERSION = 3;

	/** `wp_fct_orders.status`, `.shipping_status` and `.payment_status` are all VARCHAR(20). */
	const MAX_SLUG_LENGTH = 20;

	/**
	 * Order-status slugs that FluentCart owns.
	 *
	 * Wider than `Status::getOrderStatuses()`: `draft` and `pending` are written
	 * by the checkout before an order is placed, `refunded` and `archived`
	 * appear in core's own maps, and `cancelled` (two Ls) is what
	 * `Helper::getOrderStatuses()` still uses. A custom status may not collide
	 * with any of them.
	 */
	const BUILTIN_ORDER = array( 'draft', 'pending', 'processing', 'completed', 'on-hold', 'canceled', 'cancelled', 'failed', 'refunded', 'archived' );

	/** Order-status slugs FluentCart shows and lets an admin pick — the ones worth relabelling. */
	const OVERRIDABLE_ORDER = array( 'processing', 'completed', 'on-hold', 'canceled', 'failed' );

	/** Shipping-status slugs FluentCart owns. */
	const BUILTIN_SHIPPING = array( 'unshipped', 'shipped', 'delivered', 'unshippable' );

	/** Payment-status slugs FluentCart owns. v1 relabels these but never adds to them. */
	const BUILTIN_PAYMENT = array( 'pending', 'paid', 'partially_paid', 'failed', 'refunded', 'partially_refunded', 'authorized', 'payment_scheduled' );

	/** Which orders a custom order status may be applied to. */
	const PAYMENT_REQUIREMENTS = array( 'any', 'unpaid_only', 'paid_only' );

	/** What happens to a custom order status when payment lands. */
	const ON_PAYMENT = array( 'keep', 'let_core_decide' );

	/** The two axes that accept custom statuses. */
	const AXES = array( 'order', 'shipping' );

	/** Fallback colour for a definition saved without one. */
	const DEFAULT_COLOR = '#64748b';

	/**
	 * The built-in order status the pipeline starts from.
	 *
	 * Not a custom status: it is what core writes the moment payment lands, so
	 * it is step 0 of every pipeline whether the operator asked for it or not.
	 * The template leaves it, and its name, alone rather than adding a status
	 * beside it.
	 */
	const PIPELINE_ENTRY = 'processing';

	/**
	 * The built-in shipping status a fulfilment workflow starts from.
	 *
	 * Every physical order is created `unshipped`, so it is step 0 of a
	 * shipping-axis workflow in exactly the way `processing` is step 0 of an
	 * order-axis one — except that nothing in FluentCart ever moves it, which
	 * is the whole reason §3's restore machinery has no shipping-axis twin.
	 */
	const SHIPPING_PIPELINE_ENTRY = 'unshipped';

	/**
	 * The built-in shipping status a fulfilment workflow ends at.
	 *
	 * `delivered` and `unshippable` are outcomes rather than steps — one is
	 * what happens after the shop is finished, the other means the order was
	 * never going to ship at all — so they are reported in the distribution
	 * table and deliberately left out of the funnel.
	 */
	const SHIPPING_PIPELINE_EXIT = 'shipped';

	/** Default "this order has been sitting here too long" threshold, in days. */
	const DEFAULT_STALL_DAYS = 3;

	/**
	 * Built-in order statuses Move orders may put orders on.
	 *
	 * Move orders writes the column directly — no event, no stock, no e-mail,
	 * none of FluentCart's automations — so only statuses that are a place to
	 * wait qualify. `canceled`, `completed` and `failed` all mean something
	 * happened, and writing them without the thing happening is a lie in the
	 * order row.
	 */
	const MOVE_TARGETS_ORDER = array( 'processing', 'on-hold' );

	/** The shipping-axis twin: `shipped`, `delivered` and `unshippable` are outcomes, not places. */
	const MOVE_TARGETS_SHIPPING = array( 'unshipped' );

	/**
	 * The shipped defaults: no custom statuses, no overrides, restore switched on.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'version'            => self::SCHEMA_VERSION,
			'restore_on_payment' => 'yes',
			// Off by design: a shop that has just installed the plugin has no
			// pipeline yet, and a strict mode with nothing to be strict about
			// would only refuse status changes that used to work.
			'pipeline_strict'    => 'no',
			'stall_days'         => self::DEFAULT_STALL_DAYS,
			'daily_summary'      => array(
				'enabled' => 'no',
				'email'   => '',
			),
			'order'              => array(),
			'shipping'           => array(),
			'overrides'          => array(
				'order'    => array(),
				'payment'  => array(),
				'shipping' => array(),
			),
		);
	}

	/**
	 * The stored option, normalised.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Write a normalised row.
	 *
	 * @param array $raw Untrusted input (REST body, imported JSON).
	 * @return array The normalised row that was stored.
	 */
	public static function save( array $raw ) {
		$clean = self::sanitize( $raw );

		update_option( self::OPTION, $clean );

		return $clean;
	}

	/**
	 * Whitelist sanitiser. Pure apart from `sanitize_text_field`.
	 *
	 * Unknown keys are dropped, unknown enum values fall back to the default,
	 * invalid slugs drop the whole definition, and duplicates lose to the first
	 * one seen. Anything that survives is safe to hand to FluentCart and safe to
	 * echo (callers still escape, but the stored value carries no markup).
	 *
	 * @param mixed $raw Raw input.
	 * @return array
	 */
	public static function sanitize( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();

		$out = self::defaults();

		$out['restore_on_payment'] = ( isset( $raw['restore_on_payment'] ) && 'no' === $raw['restore_on_payment'] ) ? 'no' : 'yes';
		$out['pipeline_strict']    = ( isset( $raw['pipeline_strict'] ) && 'yes' === $raw['pipeline_strict'] ) ? 'yes' : 'no';

		$out['stall_days'] = isset( $raw['stall_days'] )
			? max( 1, min( 365, (int) $raw['stall_days'] ) )
			: self::DEFAULT_STALL_DAYS;

		$summary = isset( $raw['daily_summary'] ) && is_array( $raw['daily_summary'] ) ? $raw['daily_summary'] : array();
		$email   = isset( $summary['email'] ) ? sanitize_email( (string) $summary['email'] ) : '';

		$out['daily_summary'] = array(
			// An enabled summary with nowhere to send it is a cron job that
			// fails silently every night, so the address decides.
			'enabled' => ( isset( $summary['enabled'] ) && 'yes' === $summary['enabled'] && '' !== $email ) ? 'yes' : 'no',
			'email'   => $email,
		);

		// Shipping first: an order status may point at a custom shipping status,
		// and the pointer can only be validated against a list that has already
		// been through this sanitiser.
		$out['shipping'] = self::sanitizeDefinitions(
			isset( $raw['shipping'] ) && is_array( $raw['shipping'] ) ? $raw['shipping'] : array(),
			'shipping'
		);

		$shippingSlugs = array_merge(
			self::BUILTIN_SHIPPING,
			array_column( $out['shipping'], 'slug' )
		);

		$out['order'] = self::sanitizeDefinitions(
			isset( $raw['order'] ) && is_array( $raw['order'] ) ? $raw['order'] : array(),
			'order',
			$shippingSlugs
		);

		$overrides = isset( $raw['overrides'] ) && is_array( $raw['overrides'] ) ? $raw['overrides'] : array();

		$out['overrides'] = array(
			'order'    => self::sanitizeOverrides( $overrides, 'order', self::OVERRIDABLE_ORDER ),
			'payment'  => self::sanitizeOverrides( $overrides, 'payment', self::BUILTIN_PAYMENT ),
			'shipping' => self::sanitizeOverrides( $overrides, 'shipping', self::BUILTIN_SHIPPING ),
		);

		return $out;
	}

	/**
	 * @param array    $definitions   List of raw definitions.
	 * @param string   $axis          'order' or 'shipping'.
	 * @param string[] $shippingSlugs Shipping slugs `linked_shipping_status` may point at.
	 * @return array Re-indexed list, sorted by sort_order then label.
	 */
	private static function sanitizeDefinitions( array $definitions, $axis, array $shippingSlugs = array() ) {
		$clean = array();
		$seen  = array();

		foreach ( $definitions as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$slug = self::sanitizeSlug( isset( $definition['slug'] ) ? $definition['slug'] : '' );

			if ( '' === $slug || null !== self::slugError( $slug, $axis, $seen ) ) {
				continue;
			}

			$seen[] = $slug;

			$label = isset( $definition['label'] ) ? trim( sanitize_text_field( (string) $definition['label'] ) ) : '';

			$row = array(
				'slug'        => $slug,
				// A status with no label would render as an empty badge; the slug
				// is a worse label than a real one but better than nothing.
				'label'       => '' === $label ? $slug : $label,
				'color'       => self::sanitizeColor( isset( $definition['color'] ) ? $definition['color'] : '' ),
				'description' => isset( $definition['description'] ) ? trim( sanitize_text_field( (string) $definition['description'] ) ) : '',
				'editable'    => self::boolish( $definition, 'editable', true ),
				'enabled'     => self::boolish( $definition, 'enabled', true ),
				'sort_order'  => isset( $definition['sort_order'] ) ? max( 0, min( 9999, (int) $definition['sort_order'] ) ) : 0,
			);

			if ( 'order' === $axis ) {
				$row['payment_requirement'] = self::enum( $definition, 'payment_requirement', self::PAYMENT_REQUIREMENTS, 'any' );
				$row['on_payment']          = self::enum( $definition, 'on_payment', self::ON_PAYMENT, 'keep' );

				$linked = isset( $definition['linked_shipping_status'] )
					? self::sanitizeSlug( $definition['linked_shipping_status'] )
					: '';

				// A pointer at a shipping status that does not exist would make
				// every transition into this status log a failure, so it is
				// dropped here rather than discovered at run time.
				$row['linked_shipping_status'] = in_array( $linked, $shippingSlugs, true ) ? $linked : '';
			}

			$clean[] = $row;
		}

		usort(
			$clean,
			static function ( $a, $b ) {
				if ( $a['sort_order'] === $b['sort_order'] ) {
					return strcmp( $a['label'], $b['label'] );
				}

				return $a['sort_order'] < $b['sort_order'] ? -1 : 1;
			}
		);

		return $clean;
	}

	/**
	 * @param array    $overrides Raw overrides map.
	 * @param string   $axis      Override axis key.
	 * @param string[] $allowed   Slugs that may be overridden.
	 * @return array<string,array{label:string,color:string}>
	 */
	private static function sanitizeOverrides( array $overrides, $axis, array $allowed ) {
		$raw   = isset( $overrides[ $axis ] ) && is_array( $overrides[ $axis ] ) ? $overrides[ $axis ] : array();
		$clean = array();

		foreach ( $allowed as $slug ) {
			if ( ! isset( $raw[ $slug ] ) || ! is_array( $raw[ $slug ] ) ) {
				continue;
			}

			$label = isset( $raw[ $slug ]['label'] ) ? trim( sanitize_text_field( (string) $raw[ $slug ]['label'] ) ) : '';
			$color = isset( $raw[ $slug ]['color'] ) ? trim( (string) $raw[ $slug ]['color'] ) : '';
			$color = '' === $color ? '' : self::sanitizeColor( $color );

			// An override that changes nothing is not stored — it would only
			// make the exported JSON noisier and the "is this overridden?"
			// question harder to answer.
			if ( '' === $label && '' === $color ) {
				continue;
			}

			$clean[ $slug ] = array(
				'label' => $label,
				'color' => $color,
			);
		}

		return $clean;
	}

	/**
	 * Normalise a slug candidate: lowercase, trimmed, spaces to underscores.
	 *
	 * Does not validate — `slugError()` does that, so the admin UI can show the
	 * cleaned-up slug and the reason it was rejected at the same time.
	 *
	 * @param mixed $raw Raw slug.
	 * @return string
	 */
	public static function sanitizeSlug( $raw ) {
		$slug = strtolower( trim( (string) $raw ) );
		$slug = preg_replace( '/\s+/', '_', $slug );
		$slug = preg_replace( '/[^a-z0-9_-]/', '', (string) $slug );

		return (string) $slug;
	}

	/**
	 * Why a slug may not be used, or null when it may.
	 *
	 * @param string   $slug  Already sanitised slug.
	 * @param string   $axis  'order' or 'shipping'.
	 * @param string[] $taken Slugs already used on this axis.
	 * @return string|null Machine-readable reason code.
	 */
	public static function slugError( $slug, $axis, array $taken = array() ) {
		if ( '' === $slug ) {
			return 'empty';
		}

		// strlen, not mb_strlen: the column is VARCHAR(20) and the slug is
		// ASCII by construction, so bytes and characters agree.
		if ( strlen( $slug ) > self::MAX_SLUG_LENGTH ) {
			return 'too_long';
		}

		if ( ! preg_match( '/^[a-z][a-z0-9_-]*$/', $slug ) ) {
			return 'invalid_characters';
		}

		$builtin = 'shipping' === $axis ? self::BUILTIN_SHIPPING : self::BUILTIN_ORDER;

		if ( in_array( $slug, $builtin, true ) ) {
			return 'reserved';
		}

		if ( in_array( $slug, $taken, true ) ) {
			return 'duplicate';
		}

		return null;
	}

	/**
	 * Whether a slug is one of FluentCart's own on another axis.
	 *
	 * Not invalid in itself — the columns are separate — but FluentCart's badge
	 * classes and this plugin's label tagging are keyed by slug alone, so an
	 * order status slugged `shipped` or `paid` would rename and recolour
	 * FluentCart's own shipping or payment badge. Refused for new statuses
	 * only: a slug already stored has orders on it, and is left alone.
	 *
	 * @param string $slug Already sanitised slug.
	 * @param string $axis 'order' or 'shipping' — the axis the slug is for.
	 * @return bool
	 */
	public static function reservedElsewhere( $slug, $axis ) {
		$elsewhere = 'shipping' === $axis
			? array_merge( self::BUILTIN_ORDER, self::BUILTIN_PAYMENT )
			: array_merge( self::BUILTIN_SHIPPING, self::BUILTIN_PAYMENT );

		return in_array( (string) $slug, $elsewhere, true );
	}

	/**
	 * A short fingerprint of the stored settings.
	 *
	 * Handed to the settings screen with every read and sent back with every
	 * write, so a tab that was left open while somebody else saved is told so
	 * instead of silently undoing their change.
	 *
	 * @param array $settings Optional pre-read normalised settings.
	 * @return string
	 */
	public static function revision( array $settings = null ) {
		$settings = null === $settings ? self::all() : $settings;

		return substr( md5( (string) wp_json_encode( $settings ) ), 0, 12 );
	}

	/**
	 * @param mixed $raw Raw colour.
	 * @return string A `#rrggbb` string.
	 */
	public static function sanitizeColor( $raw ) {
		$color = strtolower( trim( (string) $raw ) );

		if ( preg_match( '/^#([0-9a-f]{3})$/', $color, $m ) ) {
			// Expand #abc to #aabbcc so everything downstream sees one shape.
			$color = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}

		return preg_match( '/^#[0-9a-f]{6}$/', $color ) ? $color : self::DEFAULT_COLOR;
	}

	/**
	 * Enabled custom statuses for one axis, keyed by slug.
	 *
	 * @param string $axis     'order' or 'shipping'.
	 * @param array  $settings Optional pre-read settings.
	 * @return array<string,array>
	 */
	public static function customStatuses( $axis, array $settings = null ) {
		$settings = null === $settings ? self::all() : $settings;
		$out      = array();

		if ( ! isset( $settings[ $axis ] ) || ! is_array( $settings[ $axis ] ) ) {
			return $out;
		}

		foreach ( $settings[ $axis ] as $definition ) {
			if ( empty( $definition['enabled'] ) ) {
				continue;
			}

			$out[ $definition['slug'] ] = $definition;
		}

		return $out;
	}

	/**
	 * One stored definition, enabled or not.
	 *
	 * `customStatuses()` answers "what may be used"; this answers "what is this
	 * slug", which a disabled status still has an answer to.
	 *
	 * @param string $axis     'order' or 'shipping'.
	 * @param string $slug     Slug.
	 * @param array  $settings Optional pre-read settings.
	 * @return array|null
	 */
	public static function definition( $axis, $slug, array $settings = null ) {
		$settings = null === $settings ? self::all() : $settings;

		if ( ! isset( $settings[ $axis ] ) || ! is_array( $settings[ $axis ] ) ) {
			return null;
		}

		foreach ( $settings[ $axis ] as $definition ) {
			if ( (string) $slug === $definition['slug'] ) {
				return $definition;
			}
		}

		return null;
	}

	/**
	 * Where Move orders may put orders on one axis.
	 *
	 * @param string $axis     'order' or 'shipping'.
	 * @param array  $settings Optional pre-read settings.
	 * @return string[] Slugs: the waiting built-ins, then every enabled custom status.
	 */
	public static function moveTargets( $axis, array $settings = null ) {
		$settings = null === $settings ? self::all() : $settings;
		$targets  = 'shipping' === $axis ? self::MOVE_TARGETS_SHIPPING : self::MOVE_TARGETS_ORDER;

		foreach ( self::customStatuses( 'shipping' === $axis ? 'shipping' : 'order', $settings ) as $slug => $definition ) {
			$targets[] = (string) $slug;
		}

		return array_values( array_unique( $targets ) );
	}

	/**
	 * Why a bulk move is refused, or null when it is allowed.
	 *
	 * The source may be a custom status (defined or not — orders left on a
	 * status that was deleted are exactly what this is for) but never one of
	 * FluentCart's own: emptying `canceled` would un-cancel orders without any
	 * of the stock or payment consequences of doing so.
	 *
	 * @param string $axis     'order' or 'shipping'.
	 * @param string $from     Source slug.
	 * @param string $to       Target slug.
	 * @param array  $settings Optional pre-read settings.
	 * @return string|null 'from_builtin', 'bad_target' or null.
	 */
	public static function moveError( $axis, $from, $to, array $settings = null ) {
		$builtin = 'shipping' === $axis ? self::BUILTIN_SHIPPING : self::BUILTIN_ORDER;

		if ( in_array( (string) $from, $builtin, true ) ) {
			return 'from_builtin';
		}

		if ( ! in_array( (string) $to, self::moveTargets( $axis, $settings ), true ) ) {
			return 'bad_target';
		}

		return null;
	}

	/**
	 * The counts of order values that nothing knows how to name.
	 *
	 * A slug is an orphan when it is neither one of FluentCart's own nor
	 * defined here, enabled or not: a status that was removed while orders were
	 * still on it, or one written by something else entirely. FluentCart shows
	 * those orders with the raw slug, and nothing offers to move them.
	 *
	 * @param string            $axis     'order' or 'shipping'.
	 * @param array<string,int> $counts   Every value in the column => order count.
	 * @param array             $settings Optional pre-read settings.
	 * @return array<string,int> slug => count, sorted by slug.
	 */
	public static function orphanCounts( $axis, array $counts, array $settings = null ) {
		$settings = null === $settings ? self::all() : $settings;
		$axis     = 'shipping' === $axis ? 'shipping' : 'order';
		$builtin  = 'shipping' === $axis ? self::BUILTIN_SHIPPING : self::BUILTIN_ORDER;
		$defined  = isset( $settings[ $axis ] ) && is_array( $settings[ $axis ] ) ? array_column( $settings[ $axis ], 'slug' ) : array();
		$out      = array();

		foreach ( $counts as $slug => $count ) {
			$slug = (string) $slug;

			// '' is a digital order's shipping column: no status at all, not a lost one.
			if ( '' === $slug || (int) $count <= 0 || in_array( $slug, $builtin, true ) || in_array( $slug, $defined, true ) ) {
				continue;
			}

			$out[ $slug ] = (int) $count;
		}

		ksort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * @return bool Whether the payment-overwrite restore is switched on.
	 */
	public static function restoreOnPaymentEnabled() {
		$all = self::all();

		return 'yes' === $all['restore_on_payment'];
	}

	/**
	 * The order-status pipeline, in the order the operator arranged it.
	 *
	 * `processing` is step 0 and is not configurable: FluentCart writes it the
	 * moment a payment is recorded (see `Payment\RestoreHandler`), so every
	 * paid order passes through it on the way to the first custom step. The
	 * remaining steps are the enabled custom order statuses in list order —
	 * the sort order on the settings screen *is* the pipeline order, which is
	 * why the screen shows the step number beside each row.
	 *
	 * @param array $settings Optional pre-read settings.
	 * @return string[] Slugs, index 0 first.
	 */
	public static function pipeline( array $settings = null ) {
		return self::pipelineFor( 'order', $settings );
	}

	/**
	 * The workflow on either axis.
	 *
	 * The two axes are shaped differently, and the difference is not cosmetic:
	 *
	 * - **order** — `processing` and then the custom steps. There is no closing
	 *   built-in, because "finished" on the order axis is `completed`, which is
	 *   a destination rather than a step and can be reached from anywhere.
	 * - **shipping** — `unshipped`, the custom steps, and then the built-in
	 *   `shipped`. A fulfilment workflow really does end at a built-in status,
	 *   and that status is the one FluentCart's own "Change Shipping Status"
	 *   dialog and `fulfilled_quantity` bookkeeping already understand.
	 *
	 * @param string $axis     'order' or 'shipping'.
	 * @param array  $settings Optional pre-read settings.
	 * @return string[] Slugs, index 0 first.
	 */
	public static function pipelineFor( $axis, array $settings = null ) {
		$settings = null === $settings ? self::all() : $settings;
		$axis     = 'shipping' === $axis ? 'shipping' : 'order';

		$steps = array( 'shipping' === $axis ? self::SHIPPING_PIPELINE_ENTRY : self::PIPELINE_ENTRY );

		foreach ( self::customStatuses( $axis, $settings ) as $slug => $definition ) {
			$steps[] = $slug;
		}

		if ( 'shipping' === $axis ) {
			$steps[] = self::SHIPPING_PIPELINE_EXIT;
		}

		return $steps;
	}

	/**
	 * @param string $slug     Order status slug.
	 * @param array  $settings Optional pre-read settings.
	 * @return int Zero-based pipeline position, or -1 when the slug is not in it.
	 */
	public static function pipelinePosition( $slug, array $settings = null ) {
		return self::pipelinePositionFor( 'order', $slug, $settings );
	}

	/**
	 * @param string $axis     'order' or 'shipping'.
	 * @param string $slug     Status slug.
	 * @param array  $settings Optional pre-read settings.
	 * @return int Zero-based pipeline position, or -1 when the slug is not in it.
	 */
	public static function pipelinePositionFor( $axis, $slug, array $settings = null ) {
		$position = array_search( (string) $slug, self::pipelineFor( $axis, $settings ), true );

		return false === $position ? -1 : (int) $position;
	}

	/**
	 * @param array $settings Optional pre-read settings.
	 * @return bool Whether only single steps along the pipeline are allowed.
	 */
	public static function pipelineStrict( array $settings = null ) {
		$settings = null === $settings ? self::all() : $settings;

		return 'yes' === $settings['pipeline_strict'];
	}

	/**
	 * @param array $settings Optional pre-read settings.
	 * @return int Days after which an order is reported as stuck.
	 */
	public static function stallDays( array $settings = null ) {
		$settings = null === $settings ? self::all() : $settings;

		return (int) $settings['stall_days'];
	}

	/**
	 * @param array  $source  Source array.
	 * @param string $key     Key.
	 * @param bool   $default Fallback.
	 * @return bool
	 */
	private static function boolish( array $source, $key, $default ) {
		if ( ! array_key_exists( $key, $source ) ) {
			return $default;
		}

		$value = $source[ $key ];

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'yes', 'true', 'on' ), true );
		}

		return (bool) $value;
	}

	/**
	 * @param array    $source  Source array.
	 * @param string   $key     Key.
	 * @param string[] $allowed Allowed values.
	 * @param string   $default Fallback.
	 * @return string
	 */
	private static function enum( array $source, $key, array $allowed, $default ) {
		$value = isset( $source[ $key ] ) ? (string) $source[ $key ] : '';

		return in_array( $value, $allowed, true ) ? $value : $default;
	}
}
