<?php
/**
 * Where the operator's heading and message actually live.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `ys_fct_status_email_content` — `{ <notification name>: { heading, message } }`.
 *
 * FluentCart's notification editor renders our two fields and posts them back
 * as `settings.extra`, but core stores none of it: `updateNotification()` keeps
 * a fixed list of keys (`active`, `subject`, `email_body`, `is_default_body`,
 * `attach_pdf_template`) and then fires `fluent_cart/email_notification_updated`
 * so the add-on that declared the fields can store them itself. That action is
 * the only write path here.
 *
 * One option row rather than a table: the payload is two short strings per
 * notification, it has to be exportable as JSON beside the status definitions,
 * and the plugin's rule is no new tables.
 */
final class ContentStore {

	const OPTION = 'ys_fct_status_email_content';

	/** A heading is one line in an e-mail, not an essay. */
	const MAX_HEADING = 200;

	/** Long enough for a real message, short enough that the option stays small. */
	const MAX_MESSAGE = 5000;

	/** @var array|null Per-request cache. */
	private static $cache = null;

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'fluent_cart/email_notification_updated', array( __CLASS__, 'onNotificationUpdated' ), 10, 2 );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flushCache' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'flushCache' ) );
	}

	/**
	 * @return void
	 */
	public static function flushCache() {
		self::$cache = null;
	}

	/**
	 * @return array<string,array{heading:string,message:string}>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );

		self::$cache = self::sanitizeAll( is_array( $stored ) ? $stored : array() );

		return self::$cache;
	}

	/**
	 * @param string $name Notification name.
	 * @return array{heading:string,message:string}|null Null when nothing is stored for it.
	 */
	public static function get( $name ) {
		$all = self::all();

		return isset( $all[ $name ] ) ? $all[ $name ] : null;
	}

	/**
	 * @param string $name    Notification name.
	 * @param string $heading Heading.
	 * @param string $message Message.
	 * @return void
	 */
	public static function put( $name, $heading, $message ) {
		$all = self::all();

		$all[ (string) $name ] = array(
			'heading' => self::sanitizeHeading( $heading ),
			'message' => self::sanitizeMessage( $message ),
		);

		self::replaceAll( $all );
	}

	/**
	 * @param array $rows Full map, unsanitised.
	 * @return array The map that was stored.
	 */
	public static function replaceAll( array $rows ) {
		$clean = self::sanitizeAll( $rows );

		update_option( self::OPTION, $clean );

		self::$cache = $clean;

		return $clean;
	}

	/**
	 * Whitelist sanitiser for a whole stored map.
	 *
	 * @param mixed $raw Raw map.
	 * @return array<string,array{heading:string,message:string}>
	 */
	public static function sanitizeAll( $raw ) {
		$raw   = is_array( $raw ) ? $raw : array();
		$clean = array();

		foreach ( $raw as $name => $row ) {
			if ( ! is_string( $name ) || ! NotificationRegistry::isOurName( $name ) || ! is_array( $row ) ) {
				continue;
			}

			$clean[ $name ] = array(
				'heading' => self::sanitizeHeading( isset( $row['heading'] ) ? $row['heading'] : '' ),
				'message' => self::sanitizeMessage( isset( $row['message'] ) ? $row['message'] : '' ),
			);
		}

		return $clean;
	}

	/**
	 * @param mixed $raw Raw heading.
	 * @return string
	 */
	public static function sanitizeHeading( $raw ) {
		$value = sanitize_text_field( (string) $raw );

		// mb_substr, not substr: a heading is operator prose and is routinely
		// not ASCII, and cutting a multi-byte character in half would put a
		// broken sequence into the mail.
		return function_exists( 'mb_substr' )
			? mb_substr( $value, 0, self::MAX_HEADING )
			: substr( $value, 0, self::MAX_HEADING );
	}

	/**
	 * @param mixed $raw Raw message.
	 * @return string
	 */
	public static function sanitizeMessage( $raw ) {
		// `sanitize_textarea_field`, not `sanitize_text_field`: the second one
		// collapses every run of `[\r\n\t ]` into a single space, which turns a
		// three-line message into one long line. Both strip tags.
		$value = sanitize_textarea_field( (string) $raw );

		// Normalise the line endings a browser sends (CRLF) so the template
		// splits on one thing and the stored value round-trips unchanged.
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );

		return function_exists( 'mb_substr' )
			? mb_substr( $value, 0, self::MAX_MESSAGE )
			: substr( $value, 0, self::MAX_MESSAGE );
	}

	/**
	 * Store what FluentCart's own editor just saved.
	 *
	 * `EmailNotificationController::update()` sanitises `settings.extra` with
	 * `map_deep(…, 'sanitize_text_field')` before we ever see it, which is
	 * exactly the call that flattens the message to one line — so the raw
	 * capture from `Email\RequestCapture` wins whenever it has something for
	 * this name and this request.
	 *
	 * @param string $name     Notification name.
	 * @param mixed  $settings The settings array core just stored.
	 * @return void
	 */
	public static function onNotificationUpdated( $name, $settings ) {
		if ( ! NotificationRegistry::isOurName( $name ) ) {
			return;
		}

		$flattened = array();

		if ( is_array( $settings ) && isset( $settings['extra'][ $name ][ NotificationRegistry::FORM_NAME ] ) && is_array( $settings['extra'][ $name ][ NotificationRegistry::FORM_NAME ] ) ) {
			$flattened = $settings['extra'][ $name ][ NotificationRegistry::FORM_NAME ];
		}

		$raw = RequestCapture::contentFor( $name );

		if ( array() === $flattened && null === $raw ) {
			// Nothing about our fields in this request — an on/off toggle or a
			// subject-only save. Leave whatever is stored alone.
			return;
		}

		$heading = null !== $raw && isset( $raw['heading'] )
			? $raw['heading']
			: ( isset( $flattened['heading'] ) ? $flattened['heading'] : '' );

		$message = null !== $raw && isset( $raw['message'] )
			? $raw['message']
			: ( isset( $flattened['message'] ) ? $flattened['message'] : '' );

		self::put( $name, $heading, $message );
	}

	/**
	 * Drop every row whose notification no longer exists.
	 *
	 * Called after a settings save. Not strictly required — an orphan row is
	 * never read, because the template and the editor both start from a
	 * registered notification — but leaving deleted statuses' text in an
	 * exported file would be confusing.
	 *
	 * @return void
	 */
	public static function pruneOrphans() {
		$all = self::all();

		if ( empty( $all ) ) {
			return;
		}

		$known = NotificationRegistry::entries();
		$kept  = array_intersect_key( $all, $known );

		if ( count( $kept ) !== count( $all ) ) {
			self::replaceAll( $kept );
		}
	}
}
