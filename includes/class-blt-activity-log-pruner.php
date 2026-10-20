<?php
/**
 * Retention deletes, in time-boxed batches.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes entries past the retention window, or on request from the Clear log
 * action, without ever holding the table for long.
 *
 * Each batch is `DELETE ... ORDER BY id LIMIT 1000` and is complete on its
 * own, so the job is naturally resumable: when the time budget runs out with
 * rows left, a single event 60 seconds later carries on from wherever the
 * table now stands. Nothing here runs in a page request.
 */
class BLT_Activity_Log_Pruner {

	/**
	 * Rows per DELETE.
	 */
	const BATCH = 1000;

	/**
	 * Seconds before a continuation runs.
	 */
	const CONTINUE_DELAY = 60;

	/**
	 * Cron hook that carries a prune or a clear on after a tick budget ran out.
	 */
	const HOOK_CONTINUE = 'blt_activity_log_prune_continue';

	/**
	 * How long without a prune before the log screen asks cron to run one.
	 */
	const OVERDUE_AFTER = 172800;

	/**
	 * The oldest `created_at` (UTC) an entry may have and be kept.
	 *
	 * @param int      $days Retention in days. Settings guarantees 1 to 365; anything below 1 has no cutoff, so nothing is deleted.
	 * @param int|null $now  Unix time; now when omitted.
	 * @return string|null `Y-m-d H:i:s`, or null when there is no valid cutoff.
	 */
	public static function cutoff( $days, $now = null ) {
		$days = (int) $days;

		if ( $days <= 0 ) {
			return null;
		}

		$now = null === $now ? time() : (int) $now;

		return gmdate( 'Y-m-d H:i:s', $now - ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Whether cron has gone too long without pruning.
	 *
	 * @param int      $last_prune_at Unix time of the last prune (0 if never).
	 * @param string   $started_at    UTC time logging started ('' if unknown).
	 * @param int|null $now           Unix time; now when omitted.
	 * @return bool
	 */
	public static function is_overdue( $last_prune_at, $started_at, $now = null ) {
		$now      = null === $now ? time() : (int) $now;
		$baseline = (int) $last_prune_at;

		// Never pruned: count from when logging started, so a new install is not "overdue".
		if ( $baseline <= 0 && '' !== (string) $started_at ) {
			$baseline = (int) strtotime( $started_at . ' UTC' );
		}

		return $baseline > 0 && ( $now - $baseline ) > self::OVERDUE_AFTER;
	}

	/**
	 * The seconds one pass may spend deleting.
	 *
	 * @return float
	 */
	public static function budget() {
		/**
		 * Filter the time budget, in seconds, of one prune pass.
		 *
		 * @param int $seconds Default 15.
		 */
		return max( 1.0, (float) apply_filters( 'blt_activity_log_tick_budget', 15 ) );
	}

	/**
	 * Daily cron: delete entries past the retention window.
	 *
	 * @return void
	 */
	public static function run() {
		try {
			$cutoff = self::cutoff( BLT_Activity_Log_Settings::retention_days() );

			// Settings keeps retention between 1 and 365 days, so this is only a guard on a destructive path.
			if ( null === $cutoff ) {
				return;
			}

			$result = self::delete( 'cutoff', $cutoff, self::budget() );

			BLT_Activity_Log_Settings::update_state(
				array(
					'last_prune_at'   => time(),
					'last_prune_rows' => $result['deleted'],
				)
			);

			if ( $result['error'] ) {
				BLT_Activity_Log_Logger::report( new RuntimeException( 'prune stopped after ' . $result['deleted'] . ' entries: database error' ) );
			}

			if ( $result['more'] && ! $result['error'] ) {
				self::schedule_continue( 'retention', $cutoff );
			}
		} catch ( \Throwable $e ) {
			BLT_Activity_Log_Logger::report( $e );
		}
	}

	/**
	 * Cron: carry on a prune or a clear that ran out of time.
	 *
	 * @param string          $mode  `retention` (the daily prune: delete what is older than the retention
	 *                               setting as it is now), `cutoff` (delete entries older than the value) or
	 *                               `max_id` (delete entries up to that id).
	 * @param string|int|null $value The cutoff time, or the highest id. Ignored for `retention`.
	 * @return void
	 */
	public static function continue_run( $mode = 'cutoff', $value = '' ) {
		try {
			if ( ! in_array( $mode, array( 'retention', 'cutoff', 'max_id' ), true ) || '' === (string) $value ) {
				return;
			}

			if ( 'retention' === $mode ) {
				// The setting may have changed since this was scheduled (raised, say, to keep more): follow it,
				// not the cutoff of the day the prune started.
				$value = self::cutoff( BLT_Activity_Log_Settings::retention_days() );

				if ( null === $value ) {
					return;
				}

				$delete_mode = 'cutoff';
			} else {
				$delete_mode = (string) $mode;
			}

			$result = self::delete( $delete_mode, $value, self::budget() );
			$state  = BLT_Activity_Log_Settings::state();

			BLT_Activity_Log_Settings::update_state(
				array(
					'last_prune_at'   => time(),
					'last_prune_rows' => (int) $state['last_prune_rows'] + $result['deleted'],
				)
			);

			if ( $result['error'] ) {
				BLT_Activity_Log_Logger::report( new RuntimeException( 'prune stopped after ' . $result['deleted'] . ' entries: database error' ) );
			}

			if ( $result['more'] && ! $result['error'] ) {
				self::schedule_continue( (string) $mode, $value );
			}
		} catch ( \Throwable $e ) {
			BLT_Activity_Log_Logger::report( $e );
		}
	}

	/**
	 * Delete in batches until the budget is spent or nothing is left.
	 *
	 * @param string          $mode   `cutoff` or `max_id`.
	 * @param string|int      $value  The cutoff time, or the highest id.
	 * @param float           $budget Seconds this pass may take.
	 * @return array{deleted:int,more:bool,error:bool} Rows removed, whether rows may remain, and whether
	 *                                                  the database reported an error (a failed delete is
	 *                                                  not "nothing left").
	 */
	public static function delete( $mode, $value, $budget ) {
		global $wpdb;

		$table   = BLT_Activity_Log_Query::table();
		$start   = microtime( true );
		$deleted = 0;
		$more    = false;
		$error   = false;

		do {
			if ( 'max_id' === $mode ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is ours; values are bound.
				$rows = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d ORDER BY id LIMIT %d", (int) $value, self::BATCH ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is ours; values are bound.
				$rows = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s ORDER BY id LIMIT %d", (string) $value, self::BATCH ) );
			}

			if ( false === $rows ) {
				$error = true;
				$more  = false;

				break;
			}

			$deleted += (int) $rows;
			$more     = (int) $rows >= self::BATCH;
		} while ( $more && ( microtime( true ) - $start ) < $budget );

		return array(
			'deleted' => $deleted,
			'more'    => $more,
			'error'   => $error,
		);
	}

	/**
	 * Schedule the continuation of a prune or a clear.
	 *
	 * @param string     $mode  `cutoff` or `max_id`.
	 * @param string|int $value Cutoff time or highest id.
	 * @return void
	 */
	public static function schedule_continue( $mode, $value ) {
		wp_schedule_single_event( time() + self::CONTINUE_DELAY, self::HOOK_CONTINUE, array( $mode, $value ) );
	}

	/**
	 * Called when the log screen opens: if cron has not pruned for two days,
	 * ask it to. Never prunes in the page request.
	 *
	 * @return void
	 */
	public static function maybe_request_overdue() {
		$state = BLT_Activity_Log_Settings::state();

		// A request made recently counts, so reopening the screen does not queue another. It is kept apart
		// from `last_prune_at`, which says when a prune really ran.
		$last = max( (int) $state['last_prune_at'], (int) $state['prune_requested_at'] );

		if ( ! self::is_overdue( $last, (string) $state['started_at'] ) ) {
			return;
		}

		wp_schedule_single_event( time(), BLT_Activity_Log_Install::CRON_PRUNE );

		BLT_Activity_Log_Settings::update_state( array( 'prune_requested_at' => time() ) );

		spawn_cron();
	}
}
