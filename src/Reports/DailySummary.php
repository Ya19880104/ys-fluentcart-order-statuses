<?php
/**
 * The optional "what is stuck this morning" e-mail.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Reports;

use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cron, plus a catch-up, because WP-Cron on a real shop is not a scheduler.
 *
 * WP-Cron only fires when somebody visits the site, so on a quiet store the
 * daily event can be hours late or — if the host has disabled it in favour of a
 * system cron that was never actually configured — never fire at all. The
 * event is therefore scheduled as normal *and* checked lazily on every admin
 * page load: if today's summary has not gone out and the scheduled time has
 * passed, it goes out then.
 *
 * "Today" is a date string in the site's own timezone, stored in its own
 * option. A shopkeeper who opens the admin at 09:00 and again at 14:00 gets one
 * e-mail, not two, and the option is written *before* `wp_mail()` runs so a
 * mailer that throws cannot turn into a loop.
 */
final class DailySummary {

	const HOOK = 'ys_fct_status_daily_summary';

	const LAST_SENT_OPTION = 'ys_fct_status_summary_last_sent';

	/** Local hour the summary is aimed at. Before this, the catch-up stays quiet. */
	const SEND_HOUR = 8;

	/**
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK, array( __CLASS__, 'runScheduled' ) );
		add_action( 'admin_init', array( __CLASS__, 'catchUp' ) );

		// The schedule follows the setting, so turning the summary off really
		// does stop the event rather than leaving it firing into a return.
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'syncSchedule' ) );
		add_action( 'add_option_' . Settings::OPTION, array( __CLASS__, 'syncSchedule' ) );

		self::syncSchedule();
	}

	/**
	 * @return void
	 */
	public static function syncSchedule() {
		$enabled   = self::isEnabled();
		$scheduled = wp_next_scheduled( self::HOOK );

		if ( $enabled && ! $scheduled ) {
			wp_schedule_event( self::nextRunTimestamp(), 'daily', self::HOOK );
			return;
		}

		if ( ! $enabled && $scheduled ) {
			wp_unschedule_event( $scheduled, self::HOOK );
		}
	}

	/**
	 * @return void
	 */
	public static function clearSchedule() {
		$scheduled = wp_next_scheduled( self::HOOK );

		if ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::HOOK );
		}
	}

	/**
	 * @return void
	 */
	public static function runScheduled() {
		self::run();
	}

	/**
	 * The lazy half. Cheap enough to run on every admin page load: one option
	 * read, and a string comparison.
	 *
	 * @return void
	 */
	public static function catchUp() {
		if ( ! self::isEnabled() ) {
			return;
		}

		if ( get_option( self::LAST_SENT_OPTION, '' ) === self::today() ) {
			return;
		}

		if ( (int) current_time( 'G' ) < self::SEND_HOUR ) {
			return;
		}

		self::run();
	}

	/**
	 * Build and send today's summary.
	 *
	 * @param bool $force Send even when today's has already gone out.
	 * @return bool Whether `wp_mail()` was called and accepted the message.
	 */
	public static function run( $force = false ) {
		$settings = StatusRegistry::settings();

		if ( ! self::isEnabled( $settings ) ) {
			return false;
		}

		if ( ! $force && get_option( self::LAST_SENT_OPTION, '' ) === self::today() ) {
			return false;
		}

		// Written first: a mailer that fatals must not leave this unset and get
		// retried on the next page load, and the next one, and the next.
		update_option( self::LAST_SENT_OPTION, self::today(), false );

		$to = (string) $settings['daily_summary']['email'];

		if ( '' === $to ) {
			return false;
		}

		$funnel  = ReportService::funnel();
		$stalled = ReportService::stalled();

		$subject = sprintf(
			/* translators: 1: site name, 2: number of orders past the threshold */
			__( '[%1$s] Order workflow summary — %2$d order(s) need attention', 'ys-fluentcart-order-statuses' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			count( $stalled['orders'] )
		);

		return (bool) wp_mail(
			$to,
			$subject,
			self::body( $funnel, $stalled ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	/**
	 * @param array $funnel  Funnel rows.
	 * @param array $stalled `['days' => int, 'orders' => array]`.
	 * @return string HTML body.
	 */
	public static function body( array $funnel, array $stalled ) {
		$lines = array();

		$lines[] = '<p>' . esc_html__( 'Orders currently on each workflow step:', 'ys-fluentcart-order-statuses' ) . '</p>';
		$lines[] = '<ul>';

		foreach ( $funnel as $step ) {
			$lines[] = '<li>' . esc_html( $step['label'] ) . ': <strong>' . (int) $step['total_count'] . '</strong></li>';
		}

		$lines[] = '</ul>';

		$lines[] = '<p>' . esc_html(
			sprintf(
				/* translators: %d: threshold in days */
				__( 'Orders that have been on the same step for more than %d day(s):', 'ys-fluentcart-order-statuses' ),
				(int) $stalled['days']
			)
		) . '</p>';

		if ( empty( $stalled['orders'] ) ) {
			$lines[] = '<p>' . esc_html__( 'None — nothing is overdue.', 'ys-fluentcart-order-statuses' ) . '</p>';
		} else {
			$lines[] = '<ul>';

			foreach ( $stalled['orders'] as $order ) {
				$lines[] = '<li>'
					. esc_html(
						sprintf(
							/* translators: 1: order id, 2: status label, 3: days */
							__( 'Order #%1$d — %2$s — %3$s day(s)', 'ys-fluentcart-order-statuses' ),
							(int) $order['order_id'],
							isset( $order['label'] ) ? $order['label'] : $order['status'],
							$order['days']
						)
					)
					. '</li>';
			}

			$lines[] = '</ul>';
		}

		$lines[] = '<p><a href="' . esc_url( admin_url( 'admin.php?page=ys-fct-order-statuses#reports' ) ) . '">'
			. esc_html__( 'Open the Order Status Report', 'ys-fluentcart-order-statuses' )
			. '</a></p>';

		return implode( "\n", $lines );
	}

	/**
	 * @param array $settings Optional pre-read settings.
	 * @return bool
	 */
	public static function isEnabled( array $settings = null ) {
		$settings = null === $settings ? Settings::all() : $settings;

		return 'yes' === $settings['daily_summary']['enabled'] && '' !== $settings['daily_summary']['email'];
	}

	/**
	 * @return string Site-local date, `Y-m-d`.
	 */
	private static function today() {
		return (string) current_time( 'Y-m-d' );
	}

	/**
	 * @return int Unix timestamp of the next SEND_HOUR in the site's timezone.
	 */
	private static function nextRunTimestamp() {
		$offset = (int) ( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
		$today  = strtotime( self::today() . ' ' . sprintf( '%02d', self::SEND_HOUR ) . ':00:00' ) - $offset;

		return $today > time() ? $today : $today + DAY_IN_SECONDS;
	}
}
