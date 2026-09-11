<?php
/**
 * Seeding the history table from FluentCart's activity feed.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\History;

use YangSheep\FluentCart\OrderStatuses\Support\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Best effort, by construction.
 *
 * FluentCart records every status change in `fct_activity`, but as a sentence:
 *
 *     Order status has been updated from on-hold to sourcing
 *
 * There is no axis column, no slug column, and the sentence goes through
 * `__()`, so on a translated store the words around the slugs are in another
 * language. What survives translation is the shape — two status slugs, in
 * order, inside one line — and that is all this parser relies on. A line it
 * cannot read is skipped and counted, never guessed at.
 *
 * It runs once, on request, and only fills in history from before the plugin
 * was recording it. Everything after that comes from `Recorder`, which has the
 * real values and does not have to read English.
 */
final class Backfill {

	/** Rows read from `fct_activity` in one pass. */
	const BATCH = 2000;

	/**
	 * Rebuild every backfilled row.
	 *
	 * Previous backfill rows are removed first, so running this twice does not
	 * double the history. Rows written by the hooks are never touched.
	 *
	 * @return array{parsed:int,skipped:int,removed:int,orders:int}
	 */
	public static function run() {
		global $wpdb;

		if ( ! Schema::tableExists() ) {
			Schema::install();
		}

		$removed = HistoryRepository::deleteBySource( 'backfill' );

		$activity = $wpdb->prefix . 'fct_activity';

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, module_id, title, content, created_by, created_at FROM `' . $activity . '`'
				. ' WHERE module_id IS NOT NULL AND module_type LIKE %s AND content LIKE %s'
				. ' ORDER BY id ASC LIMIT %d',
				'%Order',
				'%status has been updated%',
				self::BATCH
			),
			ARRAY_A
		);

		// A change the hooks already recorded must not be duplicated by a line
		// describing the same change, so every (order, axis, from, to, minute)
		// already in the table is remembered and skipped.
		$known = self::existingKeys();

		$parsed  = 0;
		$skipped = 0;
		$orders  = array();

		foreach ( (array) $rows as $row ) {
			$pair = self::parseLine( (string) $row['content'] );

			if ( null === $pair ) {
				$skipped++;
				continue;
			}

			$axis      = self::axisFor( (string) $row['title'], (string) $row['content'] );
			$orderId   = (int) $row['module_id'];
			$changedAt = (string) $row['created_at'];
			$key       = self::key( $orderId, $axis, $pair[0], $pair[1], $changedAt );

			if ( isset( $known[ $key ] ) ) {
				$skipped++;
				continue;
			}

			$written = HistoryRepository::record(
				$orderId,
				$axis,
				$pair[0],
				$pair[1],
				'backfill',
				(string) $row['created_by'],
				$changedAt
			);

			if ( ! $written ) {
				$skipped++;
				continue;
			}

			$known[ $key ]     = true;
			$orders[ $orderId ] = true;
			$parsed++;
		}

		return array(
			'parsed'  => $parsed,
			'skipped' => $skipped,
			'removed' => $removed,
			'orders'  => count( $orders ),
		);
	}

	/**
	 * The two slugs in an activity line, or null.
	 *
	 * @param string $content Activity content.
	 * @return array{0:string,1:string}|null
	 */
	public static function parseLine( $content ) {
		$content = trim( (string) $content );

		if ( '' === $content ) {
			return null;
		}

		// A status slug is lowercase ASCII with underscores or dashes, at most
		// 20 characters — the column is VARCHAR(20). Two of them, separated by
		// whatever the store's language puts between them.
		$token = '([a-z][a-z0-9_-]{0,19})';

		if ( preg_match( '/\bfrom\s+' . $token . '\s+to\s+' . $token . '\b/i', $content, $matches ) ) {
			return array( strtolower( $matches[1] ), strtolower( $matches[2] ) );
		}

		// Translated store: fall back to the shape alone. The sentence has
		// exactly two bare slug-shaped tokens and they are in order.
		//
		// The boundaries exclude upper-case letters as well as lower-case ones,
		// which is not pedantry: without that, "Order Paid" matches "rder" and
		// "aid" — the lookbehind sees the capital as a word boundary and the
		// parser invents a transition out of an unrelated log line.
		if ( preg_match_all( '/(?<![A-Za-z0-9_-])' . $token . '(?![A-Za-z0-9_-])/', $content, $all ) ) {
			$candidates = array();

			foreach ( $all[1] as $candidate ) {
				// Drop the ordinary words of whatever language wrote the line:
				// a real slug either carries a separator or is a status name,
				// and both of those are far more specific than "status".
				if ( strlen( $candidate ) < 3 || in_array( $candidate, self::STOP_WORDS, true ) ) {
					continue;
				}

				$candidates[] = $candidate;
			}

			if ( count( $candidates ) >= 2 ) {
				return array( $candidates[0], $candidates[1] );
			}
		}

		return null;
	}

	/** Words the shape-only fallback must not mistake for a slug. */
	const STOP_WORDS = array( 'order', 'status', 'has', 'been', 'updated', 'from', 'the', 'and', 'was', 'this', 'shipping', 'payment', 'customer' );

	/**
	 * @param string $title   Activity title.
	 * @param string $content Activity content.
	 * @return string 'order' or 'shipping'.
	 */
	public static function axisFor( $title, $content ) {
		$haystack = strtolower( $title . ' ' . $content );

		return false !== strpos( $haystack, 'shipping' ) ? 'shipping' : 'order';
	}

	/**
	 * Every transition already in the table, to the minute.
	 *
	 * To the minute rather than the second on purpose: an activity row is
	 * written inside the same request as the event that produced it, but not in
	 * the same second, so exact timestamps would miss the overlap the
	 * de-duplication exists to catch.
	 *
	 * @return array<string,bool>
	 */
	private static function existingKeys() {
		global $wpdb;

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			'SELECT order_id, axis, old_status, new_status, changed_at FROM `' . Schema::table() . '`',
			ARRAY_A
		);

		$keys = array();

		foreach ( (array) $rows as $row ) {
			$keys[ self::key( (int) $row['order_id'], $row['axis'], $row['old_status'], $row['new_status'], $row['changed_at'] ) ] = true;
		}

		return $keys;
	}

	/**
	 * @param int    $orderId   Order id.
	 * @param string $axis      Axis.
	 * @param string $from      Old slug.
	 * @param string $to        New slug.
	 * @param string $changedAt `Y-m-d H:i:s`.
	 * @return string
	 */
	private static function key( $orderId, $axis, $from, $to, $changedAt ) {
		return $orderId . '|' . $axis . '|' . $from . '|' . $to . '|' . substr( (string) $changedAt, 0, 16 );
	}
}
