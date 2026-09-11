<?php
/**
 * The handful of WordPress functions the unit tests need.
 *
 * Everything under test here is pure or reads one option, so this is a
 * deliberately small surface. Anything that needs a database or FluentCart is
 * not unit-tested — `tests/status-scenarios.php` exercises that against the
 * real site instead.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

declare( strict_types=1 );

$GLOBALS['ys_status_filters'] = array();
$GLOBALS['ys_status_options'] = array();

if ( ! defined( 'YS_FCT_STATUS_TESTING' ) ) {
	define( 'YS_FCT_STATUS_TESTING', true );
}

// Every source file starts with the standard direct-access guard; the tests are
// a legitimate "inside WordPress" caller as far as that guard is concerned.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! defined( 'YS_FCT_STATUS_VERSION' ) ) {
	define( 'YS_FCT_STATUS_VERSION', '0.1.0' );
}

if ( ! defined( 'YS_FCT_STATUS_MIN_FLUENTCART' ) ) {
	define( 'YS_FCT_STATUS_MIN_FLUENTCART', '1.6.0' );
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param string $hook     Hook name.
	 * @param mixed  $callback Callback.
	 * @param int    $priority Priority.
	 * @param int    $args     Accepted args.
	 * @return bool
	 */
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['ys_status_filters'][ $hook ][] = $callback;
		return true;
	}

	/**
	 * @param string $hook     Hook name.
	 * @param mixed  $callback Callback.
	 * @param int    $priority Priority.
	 * @param int    $args     Accepted args.
	 * @return bool
	 */
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		return add_filter( $hook, $callback, $priority, $args );
	}

	/**
	 * Runs whatever the tests registered, in registration order.
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		$extra = array_slice( func_get_args(), 2 );

		foreach ( isset( $GLOBALS['ys_status_filters'][ $hook ] ) ? $GLOBALS['ys_status_filters'][ $hook ] : array() as $callback ) {
			$value = call_user_func_array( $callback, array_merge( array( $value ), $extra ) );
		}

		return $value;
	}

	/**
	 * @param string $str Raw string.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		$str = (string) $str;
		$str = strip_tags( $str );
		$str = preg_replace( '/[\r\n\t]+/', ' ', $str );

		return trim( (string) $str );
	}

	/**
	 * @param string $option  Option name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['ys_status_options'] )
			? $GLOBALS['ys_status_options'][ $option ]
			: $default;
	}

	/**
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return bool
	 */
	function update_option( $option, $value ) {
		$GLOBALS['ys_status_options'][ $option ] = $value;
		return true;
	}

	/**
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return bool
	 */
	function add_option( $option, $value = '' ) {
		if ( array_key_exists( $option, $GLOBALS['ys_status_options'] ) ) {
			return false;
		}

		$GLOBALS['ys_status_options'][ $option ] = $value;
		return true;
	}

	/**
	 * @param mixed $data Data.
	 * @param int   $flags Flags.
	 * @return string
	 */
	function wp_json_encode( $data, $flags = 0 ) {
		return (string) json_encode( $data, (int) $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}

	/**
	 * @param mixed $value Raw value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}
