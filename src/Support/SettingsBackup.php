<?php
/**
 * The configuration as it was before the last import.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Support;

use YangSheep\FluentCart\OrderStatuses\History\HistoryRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `ys_fct_status_settings_backup` — one step of undo for Import.
 *
 * An import replaces every status definition, every switch and every e-mail
 * heading and message in one request. Keeping the previous document and the
 * previous e-mail text costs one option row, and turns a wrong file from an
 * evening of re-typing into one button. Not autoloaded: it is read only by the
 * settings screen.
 */
final class SettingsBackup {

	const OPTION = 'ys_fct_status_settings_backup';

	/**
	 * @param array $settings     The normalised settings being replaced.
	 * @param array $emailContent The e-mail content map being replaced.
	 * @return void
	 */
	public static function store( array $settings, array $emailContent ) {
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;

		$value = array(
			'settings'      => $settings,
			'email_content' => $emailContent,
			'saved_at'      => gmdate( 'Y-m-d H:i:s' ),
			'user_id'       => $user && ! empty( $user->ID ) ? (int) $user->ID : 0,
			'user_name'     => HistoryRepository::currentActor(),
		);

		// Delete and add rather than update: `update_option()` only changes the
		// autoload flag of an existing row when the value changes too.
		delete_option( self::OPTION );
		add_option( self::OPTION, $value, '', false );
	}

	/**
	 * @return array|null The stored backup, or null when there is none.
	 */
	public static function get() {
		$value = get_option( self::OPTION, null );

		if ( ! is_array( $value ) || ! isset( $value['settings'] ) || ! is_array( $value['settings'] ) ) {
			return null;
		}

		$value['email_content'] = isset( $value['email_content'] ) && is_array( $value['email_content'] ) ? $value['email_content'] : array();

		return $value;
	}

	/**
	 * What the settings screen shows beside the Undo button.
	 *
	 * @return array{saved_at:string,user:string,note:string}|null
	 */
	public static function meta() {
		$backup = self::get();

		if ( null === $backup ) {
			return null;
		}

		$savedAt = isset( $backup['saved_at'] ) ? (string) $backup['saved_at'] : '';
		$user    = isset( $backup['user_name'] ) ? (string) $backup['user_name'] : '';
		$stamp   = (int) strtotime( $savedAt . ' UTC' );
		$when    = $stamp > 0 && function_exists( 'wp_date' )
			? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stamp )
			: $savedAt;

		return array(
			'saved_at' => $savedAt,
			'user'     => $user,
			'note'     => sprintf(
				/* translators: 1: date and time, 2: user name */
				__( 'Last import: %1$s, by %2$s.', 'ys-fluentcart-order-statuses' ),
				$when,
				'' === $user ? '—' : $user
			),
		);
	}

	/**
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
