<?php
/**
 * The report tab's REST surface.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Rest;

use YangSheep\FluentCart\OrderStatuses\History\Backfill;
use YangSheep\FluentCart\OrderStatuses\History\HistoryRepository;
use YangSheep\FluentCart\OrderStatuses\Pipeline\Template;
use YangSheep\FluentCart\OrderStatuses\Reports\CsvExporter;
use YangSheep\FluentCart\OrderStatuses\Reports\DailySummary;
use YangSheep\FluentCart\OrderStatuses\Reports\ReportService;
use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;
use YangSheep\FluentCart\OrderStatuses\Support\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `ys-fct-status/v1/reports/*`, plus the two one-click actions beside them.
 *
 * Same bar as the settings routes — capability **and** nonce — even though
 * everything here except `backfill` and `template` is read-only. Order counts
 * and amounts by status are commercial information, and a nonce-less GET is
 * readable by any page the logged-in shopkeeper happens to open.
 *
 * CSV comes back as a string inside the JSON envelope rather than as a file
 * download. The admin screen turns it into a Blob and saves it, which keeps
 * every route in one shape, keeps the nonce in a header where it belongs, and
 * avoids a second authenticated URL that has to stream a body.
 */
final class ReportController {

	/** Report types the export route will produce. */
	const EXPORTS = array( 'distribution', 'shipping', 'dwell', 'stalled' );

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
			StatusController::NAMESPACE_V1,
			'/reports/overview',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'overview' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			StatusController::NAMESPACE_V1,
			'/reports/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'history' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			StatusController::NAMESPACE_V1,
			'/reports/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			StatusController::NAMESPACE_V1,
			'/reports/backfill',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'backfill' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			StatusController::NAMESPACE_V1,
			'/reports/summary-test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'summaryTest' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			StatusController::NAMESPACE_V1,
			'/template',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'applyTemplate' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function overview( $request ) {
		list( $since, $until ) = self::range( $request );

		return rest_ensure_response( ReportService::overview( $since, $until, self::axis( $request ) ) );
	}

	/**
	 * Which workflow the funnel, the dwell table and the stuck list describe.
	 *
	 * The two distribution tables are per-axis by construction and both are
	 * always returned; this switch is for the three blocks that read a
	 * *workflow* rather than a column, and which therefore have to be told
	 * which of the two workflows is meant.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string 'order' or 'shipping'.
	 */
	private static function axis( $request ) {
		return 'shipping' === (string) $request->get_param( 'axis' ) ? 'shipping' : 'order';
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function history( $request ) {
		$orderId = (int) $request->get_param( 'order_id' );

		if ( $orderId <= 0 ) {
			return new \WP_Error(
				'ys_fct_status_bad_order',
				__( 'Which order?', 'ys-fluentcart-order-statuses' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'order_id' => $orderId,
				'history'  => HistoryRepository::forOrder( $orderId ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export( $request ) {
		$type = (string) $request->get_param( 'type' );

		if ( ! in_array( $type, self::EXPORTS, true ) ) {
			return new \WP_Error(
				'ys_fct_status_bad_export',
				__( 'Unknown report.', 'ys-fluentcart-order-statuses' ),
				array( 'status' => 400 )
			);
		}

		list( $since, $until ) = self::range( $request );

		$axis = self::axis( $request );

		switch ( $type ) {
			case 'shipping':
				$csv = CsvExporter::distribution( ReportService::distribution( 'shipping', $since, $until ) );
				break;

			case 'dwell':
				$csv = CsvExporter::dwell( ReportService::dwell( $axis, $since, $until ) );
				break;

			case 'stalled':
				$stalled = ReportService::stalled( 0, $axis );
				$csv     = CsvExporter::stalled( $stalled['orders'] );
				break;

			case 'distribution':
			default:
				$csv = CsvExporter::distribution( ReportService::distribution( 'order', $since, $until ) );
				break;
		}

		// The axis is in the filename for the two reports that have one: an
		// operator who exports both ends up with two files in one folder, and
		// "dwell" twice tells them nothing about which workflow each describes.
		$scope = in_array( $type, array( 'dwell', 'stalled' ), true ) ? $type . '-' . $axis : $type;

		return rest_ensure_response(
			array(
				'filename' => 'ys-order-status-' . $scope . '-' . gmdate( 'Ymd-His' ) . '.csv',
				'csv'      => $csv,
			)
		);
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function backfill() {
		$result = Backfill::run();

		return rest_ensure_response(
			array(
				'result'  => $result,
				'rows'    => HistoryRepository::count(),
				'message' => sprintf(
					/* translators: 1: rows written, 2: orders touched, 3: lines skipped */
					__( 'Read FluentCart\'s activity log: %1$d status changes across %2$d orders were added to the history. %3$d line(s) were skipped because they were already recorded or could not be read.', 'ys-fluentcart-order-statuses' ),
					$result['parsed'],
					$result['orders'],
					$result['skipped']
				),
			)
		);
	}

	/**
	 * Send the daily summary now, whatever the clock says.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function summaryTest() {
		$settings = StatusRegistry::settings();

		if ( ! DailySummary::isEnabled( $settings ) ) {
			return new \WP_Error(
				'ys_fct_status_summary_off',
				__( 'Switch the daily summary on and give it an address first.', 'ys-fluentcart-order-statuses' ),
				array( 'status' => 400 )
			);
		}

		$sent = DailySummary::run( true );

		return rest_ensure_response(
			array(
				'sent'    => $sent,
				'message' => $sent
					? sprintf(
						/* translators: %s: e-mail address */
						__( 'Summary sent to %s.', 'ys-fluentcart-order-statuses' ),
						$settings['daily_summary']['email']
					)
					: __( 'WordPress refused to send the message. Check the site\'s mail configuration.', 'ys-fluentcart-order-statuses' ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function applyTemplate( $request ) {
		// Same rule as Save and Import: a screen that is out of date is told.
		$stale = StatusController::staleRefusal( $request );

		if ( null !== $stale ) {
			return $stale;
		}

		$axis = 'shipping' === (string) $request->get_param( 'axis' ) ? 'shipping' : 'order';

		$merged = 'shipping' === $axis
			? Template::mergeShippingInto( Settings::all() )
			: Template::mergeInto( Settings::all() );

		Settings::save( $merged['settings'] );
		StatusRegistry::flushCache();

		return rest_ensure_response(
			array(
				'settings' => Settings::all(),
				'axis'     => $axis,
				'added'    => $merged['added'],
				'skipped'  => $merged['skipped'],
				'message'  => empty( $merged['added'] )
					? __( 'Every step of the template was already there — nothing was changed.', 'ys-fluentcart-order-statuses' )
					: sprintf(
						/* translators: %d: number of statuses added */
						_n( '%d workflow step was added.', '%d workflow steps were added.', count( $merged['added'] ), 'ys-fluentcart-order-statuses' ),
						count( $merged['added'] )
					),
			)
		);
	}

	/**
	 * A `since`/`until` pair as GMT timestamps, or two empty strings.
	 *
	 * Dates arrive as `YYYY-MM-DD` from a native date input, are read in the
	 * site's timezone (the operator means their own days, not UTC ones) and are
	 * converted to GMT because that is what FluentCart stores. `until` covers
	 * the whole of its day.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{0:string,1:string}
	 */
	private static function range( $request ) {
		$since = self::toGmt( (string) $request->get_param( 'since' ), '00:00:00' );
		$until = self::toGmt( (string) $request->get_param( 'until' ), '23:59:59' );

		return array( $since, $until );
	}

	/**
	 * @param string $date `YYYY-MM-DD`.
	 * @param string $time Time of day to attach.
	 * @return string GMT `Y-m-d H:i:s`, or ''.
	 */
	private static function toGmt( $date, $time ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		$local = $date . ' ' . $time;

		return (string) get_gmt_from_date( $local, 'Y-m-d H:i:s' );
	}
}
