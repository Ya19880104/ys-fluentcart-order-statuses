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

	/** Schema version of the stored row / exported JSON. */
	const SCHEMA_VERSION = 1;

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
	 * The shipped defaults: no custom statuses, no overrides, restore switched on.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'version'          => self::SCHEMA_VERSION,
			'restore_on_payment' => 'yes',
			'order'            => array(),
			'shipping'         => array(),
			'overrides'        => array(
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

		foreach ( self::AXES as $axis ) {
			$definitions = isset( $raw[ $axis ] ) && is_array( $raw[ $axis ] ) ? $raw[ $axis ] : array();
			$out[ $axis ] = self::sanitizeDefinitions( $definitions, $axis );
		}

		$overrides = isset( $raw['overrides'] ) && is_array( $raw['overrides'] ) ? $raw['overrides'] : array();

		$out['overrides'] = array(
			'order'    => self::sanitizeOverrides( $overrides, 'order', self::OVERRIDABLE_ORDER ),
			'payment'  => self::sanitizeOverrides( $overrides, 'payment', self::BUILTIN_PAYMENT ),
			'shipping' => self::sanitizeOverrides( $overrides, 'shipping', self::BUILTIN_SHIPPING ),
		);

		return $out;
	}

	/**
	 * @param array  $definitions List of raw definitions.
	 * @param string $axis        'order' or 'shipping'.
	 * @return array Re-indexed list, sorted by sort_order then label.
	 */
	private static function sanitizeDefinitions( array $definitions, $axis ) {
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
	 * @return bool Whether the payment-overwrite restore is switched on.
	 */
	public static function restoreOnPaymentEnabled() {
		$all = self::all();

		return 'yes' === $all['restore_on_payment'];
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
