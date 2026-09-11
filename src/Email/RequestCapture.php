<?php
/**
 * The unflattened copy of the two content fields, taken before core sees them.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A sibling of `Support\OrderContext`, and deliberately not part of it.
 *
 * The problem: `EmailNotificationRequest::sanitize()` runs
 * `map_deep($value, 'sanitize_text_field')` over `settings.extra`, and
 * `sanitize_text_field()` replaces every run of `[\r\n\t ]` with one space. By
 * the time `fluent_cart/email_notification_updated` fires — the only hook core
 * offers for storing add-on fields — a three-line message has become one line,
 * and there is no way to get the newlines back.
 *
 * The fix that does not touch core: read the body on `rest_pre_dispatch`, which
 * runs before the route callback and therefore before the request guard, keep
 * our two fields for the duration of the request, and let `ContentStore` prefer
 * that copy over the flattened one. Everything captured here is sanitised by
 * `ContentStore` before it is stored — this class holds raw input and nothing
 * else reads it.
 *
 * It is a separate class from `OrderContext` because the two capture different
 * things from different routes for different consumers, and folding a mail
 * concern into the class that answers "which order is this request about?"
 * would make both harder to reason about.
 */
final class RequestCapture {

	/** `PUT /<namespace>/v<n>/email-notification/<name>`. */
	const ROUTE_PATTERN = '#^/[a-z0-9\-]+/v\d+/email-notification/([^/]+)/?$#i';

	/** @var array<string,array<string,string>> Raw fields of the request in flight, keyed by notification name. */
	private static $content = array();

	/**
	 * @return void
	 */
	public function register() {
		// Priority 1, like OrderContext: before anything that might short-circuit
		// the request, and long before the route callback.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'capture' ), 1, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'release' ), 99, 3 );
	}

	/**
	 * @param mixed $result  Short-circuit value.
	 * @param mixed $server  REST server.
	 * @param mixed $request REST request.
	 * @return mixed Untouched.
	 */
	public static function capture( $result, $server, $request ) {
		self::$content = array();

		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $result;
		}

		if ( 'PUT' !== strtoupper( (string) $request->get_method() ) ) {
			return $result;
		}

		if ( ! preg_match( self::ROUTE_PATTERN, (string) $request->get_route(), $matches ) ) {
			return $result;
		}

		$name = rawurldecode( $matches[1] );

		if ( ! NotificationRegistry::isOurName( $name ) ) {
			return $result;
		}

		$extra = self::rawExtra( $request );

		if ( ! isset( $extra[ $name ][ NotificationRegistry::FORM_NAME ] ) || ! is_array( $extra[ $name ][ NotificationRegistry::FORM_NAME ] ) ) {
			return $result;
		}

		$fields = $extra[ $name ][ NotificationRegistry::FORM_NAME ];
		$kept   = array();

		foreach ( array( 'heading', 'message' ) as $field ) {
			if ( isset( $fields[ $field ] ) && is_scalar( $fields[ $field ] ) ) {
				$kept[ $field ] = (string) $fields[ $field ];
			}
		}

		if ( ! empty( $kept ) ) {
			self::$content[ $name ] = $kept;
		}

		return $result;
	}

	/**
	 * `settings.extra` as it arrived, before any sanitiser.
	 *
	 * The SPA sends JSON, `wp eval-file` scenarios set body params, and a form
	 * post would use the third. Whichever carries `settings.extra` as an array
	 * is the one that matters.
	 *
	 * @param mixed $request REST request.
	 * @return array
	 */
	private static function rawExtra( $request ) {
		$sources = array();

		if ( method_exists( $request, 'get_json_params' ) ) {
			$sources[] = $request->get_json_params();
		}

		if ( method_exists( $request, 'get_body_params' ) ) {
			$sources[] = $request->get_body_params();
		}

		if ( method_exists( $request, 'get_params' ) ) {
			$sources[] = $request->get_params();
		}

		foreach ( $sources as $source ) {
			if ( is_array( $source ) && isset( $source['settings']['extra'] ) && is_array( $source['settings']['extra'] ) ) {
				return $source['settings']['extra'];
			}
		}

		return array();
	}

	/**
	 * @param mixed $response Response.
	 * @param mixed $server   REST server.
	 * @param mixed $request  REST request.
	 * @return mixed Untouched.
	 */
	public static function release( $response, $server, $request ) {
		self::$content = array();

		return $response;
	}

	/**
	 * @param string $name Notification name.
	 * @return array<string,string>|null The raw fields, or null when this request carried none.
	 */
	public static function contentFor( $name ) {
		return isset( self::$content[ $name ] ) ? self::$content[ $name ] : null;
	}

	/**
	 * Test seam / programmatic override.
	 *
	 * @param string $name   Notification name.
	 * @param array  $fields Raw fields.
	 * @return void
	 */
	public static function set( $name, array $fields ) {
		self::$content[ (string) $name ] = $fields;
	}

	/**
	 * @return void
	 */
	public static function reset() {
		self::$content = array();
	}
}
