<?php
/**
 * What replacing one settings document with another would do.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Support;

use YangSheep\FluentCart\OrderStatuses\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure comparisons between the stored settings and an incoming document.
 *
 * Save, Import and Undo all replace the whole document, so they share one
 * question — which statuses disappear, and are any orders still on them? — and
 * Import adds a second, for the confirm dialog: what would change at all? None
 * of it touches the database; the caller supplies the order counts.
 */
final class SettingsChange {

	/** The `plugin` value every export carries, since 0.1. */
	const EXPORT_PLUGIN = 'ys-fluentcart-order-statuses';

	/** The fields of a definition that count as a change. `sort_order` is position, not content. */
	const COMPARED = array( 'label', 'color', 'description', 'editable', 'enabled', 'payment_requirement', 'on_payment', 'linked_shipping_status' );

	/**
	 * Whether a decoded file is an export written by this plugin.
	 *
	 * Deliberately strict. Until 0.6 any JSON object was taken as a whole
	 * settings document, so `{}` replaced every status with nothing.
	 *
	 * @param mixed $payload Decoded file.
	 * @return bool
	 */
	public static function isExportEnvelope( $payload ) {
		return is_array( $payload )
			&& isset( $payload['plugin'] ) && self::EXPORT_PLUGIN === $payload['plugin']
			&& isset( $payload['settings'] ) && is_array( $payload['settings'] )
			&& isset( $payload['settings']['order'] ) && is_array( $payload['settings']['order'] )
			&& isset( $payload['settings']['shipping'] ) && is_array( $payload['settings']['shipping'] );
	}

	/**
	 * Stored statuses the incoming document no longer has.
	 *
	 * A re-slugged status is one of these too: its stored slug is gone, and the
	 * orders sitting on that slug would be left behind exactly as if it had
	 * been removed.
	 *
	 * @param array $stored   Normalised stored settings.
	 * @param array $incoming Raw or normalised incoming document.
	 * @return array<string,array<string,string>> axis => slug => stored label.
	 */
	public static function removed( array $stored, array $incoming ) {
		$out = array(
			'order'    => array(),
			'shipping' => array(),
		);

		foreach ( Settings::AXES as $axis ) {
			$kept        = array();
			$definitions = isset( $incoming[ $axis ] ) && is_array( $incoming[ $axis ] ) ? $incoming[ $axis ] : array();

			foreach ( $definitions as $definition ) {
				if ( is_array( $definition ) && isset( $definition['slug'] ) ) {
					$kept[] = Settings::sanitizeSlug( $definition['slug'] );
				}
			}

			foreach ( isset( $stored[ $axis ] ) && is_array( $stored[ $axis ] ) ? $stored[ $axis ] : array() as $definition ) {
				if ( ! in_array( $definition['slug'], $kept, true ) ) {
					$out[ $axis ][ $definition['slug'] ] = (string) $definition['label'];
				}
			}
		}

		return $out;
	}

	/**
	 * One sentence per removed status that still has orders on it.
	 *
	 * @param array $removed `removed()`.
	 * @param array $counts  axis => slug => order count.
	 * @return string[]
	 */
	public static function inUseMessages( array $removed, array $counts ) {
		$messages = array();

		foreach ( $removed as $axis => $slugs ) {
			foreach ( $slugs as $slug => $label ) {
				$count = isset( $counts[ $axis ][ $slug ] ) ? (int) $counts[ $axis ][ $slug ] : 0;

				if ( $count <= 0 ) {
					continue;
				}

				$messages[] = sprintf(
					/* translators: 1: status label, 2: number of orders */
					_n(
						'“%1$s” is still on %2$d order. Move it to another status first.',
						'“%1$s” is still on %2$d orders. Move them to another status first.',
						$count,
						'ys-fluentcart-order-statuses'
					),
					'' === $label ? (string) $slug : $label,
					$count
				);
			}
		}

		return $messages;
	}

	/**
	 * Everything an import would change.
	 *
	 * @param array $stored   Normalised stored settings.
	 * @param array $incoming Normalised incoming settings.
	 * @return array{order:array,shipping:array,toggles:array,overrides:bool}
	 */
	public static function diff( array $stored, array $incoming ) {
		$out = array();

		foreach ( Settings::AXES as $axis ) {
			$before = self::bySlug( isset( $stored[ $axis ] ) ? $stored[ $axis ] : array() );
			$after  = self::bySlug( isset( $incoming[ $axis ] ) ? $incoming[ $axis ] : array() );
			$axisOut = array(
				'added'   => array(),
				'removed' => array(),
				'changed' => array(),
			);

			foreach ( $after as $slug => $definition ) {
				if ( ! isset( $before[ $slug ] ) ) {
					$axisOut['added'][ $slug ] = $definition['label'];
					continue;
				}

				foreach ( self::COMPARED as $field ) {
					$old = isset( $before[ $slug ][ $field ] ) ? $before[ $slug ][ $field ] : null;
					$new = isset( $definition[ $field ] ) ? $definition[ $field ] : null;

					if ( $old !== $new ) {
						$axisOut['changed'][ $slug ] = $definition['label'];
						break;
					}
				}
			}

			foreach ( $before as $slug => $definition ) {
				if ( ! isset( $after[ $slug ] ) ) {
					$axisOut['removed'][ $slug ] = $definition['label'];
				}
			}

			$out[ $axis ] = $axisOut;
		}

		$out['toggles'] = array();
		$from           = self::toggles( $stored );
		$to             = self::toggles( $incoming );

		foreach ( $from as $key => $value ) {
			if ( $value !== $to[ $key ] ) {
				$out['toggles'][ $key ] = array(
					'from' => $value,
					'to'   => $to[ $key ],
				);
			}
		}

		$out['overrides'] = ( isset( $stored['overrides'] ) ? $stored['overrides'] : array() ) != ( isset( $incoming['overrides'] ) ? $incoming['overrides'] : array() ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- key order is not a change.

		return $out;
	}

	/**
	 * @param array $diff `diff()`.
	 * @return bool Whether nothing would change.
	 */
	public static function isEmpty( array $diff ) {
		foreach ( Settings::AXES as $axis ) {
			if ( ! empty( $diff[ $axis ]['added'] ) || ! empty( $diff[ $axis ]['removed'] ) || ! empty( $diff[ $axis ]['changed'] ) ) {
				return false;
			}
		}

		return empty( $diff['toggles'] ) && empty( $diff['overrides'] );
	}

	/**
	 * The diff as sentences for a confirm dialog.
	 *
	 * @param array $diff `diff()`.
	 * @return string[]
	 */
	public static function summaryLines( array $diff ) {
		$lines = array();

		foreach ( Settings::AXES as $axis ) {
			$name  = 'shipping' === $axis
				? __( 'Shipping statuses', 'ys-fluentcart-order-statuses' )
				: __( 'Order statuses', 'ys-fluentcart-order-statuses' );
			$parts = array();

			if ( ! empty( $diff[ $axis ]['added'] ) ) {
				/* translators: %s: comma-separated status labels */
				$parts[] = sprintf( __( 'added %s', 'ys-fluentcart-order-statuses' ), self::names( $diff[ $axis ]['added'] ) );
			}

			if ( ! empty( $diff[ $axis ]['removed'] ) ) {
				/* translators: %s: comma-separated status labels */
				$parts[] = sprintf( __( 'removed %s', 'ys-fluentcart-order-statuses' ), self::names( $diff[ $axis ]['removed'] ) );
			}

			if ( ! empty( $diff[ $axis ]['changed'] ) ) {
				/* translators: %s: comma-separated status labels */
				$parts[] = sprintf( __( 'changed %s', 'ys-fluentcart-order-statuses' ), self::names( $diff[ $axis ]['changed'] ) );
			}

			$lines[] = sprintf(
				/* translators: 1: "Order statuses" or "Shipping statuses", 2: what changes */
				__( '%1$s: %2$s.', 'ys-fluentcart-order-statuses' ),
				$name,
				empty( $parts ) ? __( 'no change', 'ys-fluentcart-order-statuses' ) : implode( '; ', $parts )
			);
		}

		if ( ! empty( $diff['toggles'] ) ) {
			$items = array();

			foreach ( $diff['toggles'] as $key => $change ) {
				$items[] = sprintf(
					'%1$s (%2$s → %3$s)',
					self::toggleName( $key ),
					self::toggleValue( $change['from'] ),
					self::toggleValue( $change['to'] )
				);
			}

			/* translators: %s: comma-separated list of settings with their old and new values */
			$lines[] = sprintf( __( 'Settings that change: %s.', 'ys-fluentcart-order-statuses' ), implode( ', ', $items ) );
		}

		if ( ! empty( $diff['overrides'] ) ) {
			$lines[] = __( 'The names or colours of FluentCart’s built-in statuses change.', 'ys-fluentcart-order-statuses' );
		}

		return $lines;
	}

	/**
	 * @param array $definitions Normalised definitions.
	 * @return array<string,array>
	 */
	private static function bySlug( array $definitions ) {
		$out = array();

		foreach ( $definitions as $definition ) {
			if ( is_array( $definition ) && isset( $definition['slug'] ) ) {
				$out[ (string) $definition['slug'] ] = $definition;
			}
		}

		return $out;
	}

	/**
	 * @param array $settings Normalised settings.
	 * @return array<string,string>
	 */
	private static function toggles( array $settings ) {
		$summary = isset( $settings['daily_summary'] ) && is_array( $settings['daily_summary'] ) ? $settings['daily_summary'] : array();

		return array(
			'restore_on_payment'    => isset( $settings['restore_on_payment'] ) ? (string) $settings['restore_on_payment'] : '',
			'pipeline_strict'       => isset( $settings['pipeline_strict'] ) ? (string) $settings['pipeline_strict'] : '',
			'stall_days'            => isset( $settings['stall_days'] ) ? (string) $settings['stall_days'] : '',
			'daily_summary_enabled' => isset( $summary['enabled'] ) ? (string) $summary['enabled'] : '',
			'daily_summary_email'   => isset( $summary['email'] ) ? (string) $summary['email'] : '',
		);
	}

	/**
	 * @param string $key Toggle key.
	 * @return string
	 */
	private static function toggleName( $key ) {
		switch ( $key ) {
			case 'restore_on_payment':
				return __( 'Restore custom order statuses after payment', 'ys-fluentcart-order-statuses' );
			case 'pipeline_strict':
				return __( 'Strict workflow', 'ys-fluentcart-order-statuses' );
			case 'stall_days':
				return __( 'Days before an order counts as stuck', 'ys-fluentcart-order-statuses' );
			case 'daily_summary_enabled':
				return __( 'Daily summary e-mail', 'ys-fluentcart-order-statuses' );
			default:
				return __( 'Daily summary address', 'ys-fluentcart-order-statuses' );
		}
	}

	/**
	 * @param string $value Stored value.
	 * @return string
	 */
	private static function toggleValue( $value ) {
		if ( 'yes' === $value ) {
			return __( 'on', 'ys-fluentcart-order-statuses' );
		}

		if ( 'no' === $value ) {
			return __( 'off', 'ys-fluentcart-order-statuses' );
		}

		return '' === $value ? '—' : $value;
	}

	/**
	 * @param array<string,string> $labels slug => label.
	 * @return string
	 */
	private static function names( array $labels ) {
		$out = array();

		foreach ( $labels as $slug => $label ) {
			$out[] = '“' . ( '' === (string) $label ? (string) $slug : $label ) . '”';
		}

		return implode( ', ', $out );
	}
}
