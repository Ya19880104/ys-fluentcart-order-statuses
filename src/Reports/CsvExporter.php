<?php
/**
 * CSV for the two report tables.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Reports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small on purpose, and paranoid about one thing.
 *
 * A CSV from a shop report is opened in Excel or Sheets, and a cell beginning
 * `=`, `+`, `-`, `@`, a tab or a carriage return is executed as a formula there
 * — status labels and order notes are operator-controlled text, so that is a
 * real path from "someone typed a status name" to "a spreadsheet ran a
 * command". Every cell is prefixed with an apostrophe when it starts with one
 * of those, which Excel strips on display and never executes.
 *
 * The BOM is the other half: without it Excel on Windows reads UTF-8 as the
 * system code page and a Chinese status label arrives as mojibake.
 */
final class CsvExporter {

	/** Characters that make Excel treat a cell as a formula. */
	const FORMULA_PREFIXES = array( '=', '+', '-', '@', "\t", "\r" );

	const BOM = "\xEF\xBB\xBF";

	/**
	 * @param array $rows Distribution rows from `ReportService::distribution()`.
	 * @return string
	 */
	public static function distribution( array $rows ) {
		$out = array(
			array(
				__( 'Status', 'ys-fluentcart-order-statuses' ),
				__( 'Slug', 'ys-fluentcart-order-statuses' ),
				__( 'Custom', 'ys-fluentcart-order-statuses' ),
				__( 'Paid orders', 'ys-fluentcart-order-statuses' ),
				__( 'Paid amount (minor units)', 'ys-fluentcart-order-statuses' ),
				__( 'Unpaid orders', 'ys-fluentcart-order-statuses' ),
				__( 'Unpaid amount (minor units)', 'ys-fluentcart-order-statuses' ),
				__( 'Total orders', 'ys-fluentcart-order-statuses' ),
				__( 'Total amount (minor units)', 'ys-fluentcart-order-statuses' ),
			),
		);

		foreach ( $rows as $row ) {
			$out[] = array(
				$row['label'],
				$row['slug'],
				$row['is_custom'] ? 'yes' : 'no',
				$row['paid_count'],
				$row['paid_amount'],
				$row['unpaid_count'],
				$row['unpaid_amount'],
				$row['total_count'],
				$row['total_amount'],
			);
		}

		return self::render( $out );
	}

	/**
	 * @param array $rows Stalled-order rows from `ReportService::stalled()`.
	 * @return string
	 */
	public static function stalled( array $rows ) {
		$out = array(
			array(
				__( 'Order', 'ys-fluentcart-order-statuses' ),
				__( 'Status', 'ys-fluentcart-order-statuses' ),
				__( 'Slug', 'ys-fluentcart-order-statuses' ),
				__( 'Payment status', 'ys-fluentcart-order-statuses' ),
				__( 'Total (minor units)', 'ys-fluentcart-order-statuses' ),
				__( 'Currency', 'ys-fluentcart-order-statuses' ),
				__( 'Entered status (UTC)', 'ys-fluentcart-order-statuses' ),
				__( 'Days in status', 'ys-fluentcart-order-statuses' ),
			),
		);

		foreach ( $rows as $row ) {
			$out[] = array(
				$row['order_id'],
				isset( $row['label'] ) ? $row['label'] : $row['status'],
				$row['status'],
				$row['payment'],
				$row['total'],
				$row['currency'],
				$row['entered_at'],
				$row['days'],
			);
		}

		return self::render( $out );
	}

	/**
	 * @param array $rows Dwell rows from `ReportService::dwell()`.
	 * @return string
	 */
	public static function dwell( array $rows ) {
		$out = array(
			array(
				__( 'Status', 'ys-fluentcart-order-statuses' ),
				__( 'Slug', 'ys-fluentcart-order-statuses' ),
				__( 'Completed stays', 'ys-fluentcart-order-statuses' ),
				__( 'Average days', 'ys-fluentcart-order-statuses' ),
				__( 'Longest days', 'ys-fluentcart-order-statuses' ),
			),
		);

		foreach ( $rows as $row ) {
			$out[] = array(
				$row['label'],
				$row['slug'],
				$row['samples'],
				$row['avg_days'],
				$row['max_days'],
			);
		}

		return self::render( $out );
	}

	/**
	 * @param array<int,array<int,mixed>> $rows Rows of cells.
	 * @return string CSV with a UTF-8 BOM.
	 */
	public static function render( array $rows ) {
		$lines = array();

		foreach ( $rows as $row ) {
			$cells = array();

			foreach ( $row as $cell ) {
				$cells[] = self::cell( $cell );
			}

			$lines[] = implode( ',', $cells );
		}

		return self::BOM . implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * @param mixed $value Cell value.
	 * @return string Quoted, escaped and de-fanged.
	 */
	public static function cell( $value ) {
		$value = (string) $value;

		if ( '' !== $value && in_array( $value[0], self::FORMULA_PREFIXES, true ) ) {
			$value = "'" . $value;
		}

		return '"' . str_replace( '"', '""', $value ) . '"';
	}
}
