<?php
/**
 * Pruner: cutoff maths and the overdue rule.
 *
 * @package Blt_Activity_Log
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for the pure parts of BLT_Activity_Log_Pruner.
 */
class Test_Pruner extends TestCase {

	/**
	 * Retention to a UTC cutoff.
	 *
	 * @return void
	 */
	public function test_cutoff() {
		$now = strtotime( '2026-10-16 12:00:00 UTC' );

		$this->assertSame( '2026-07-18 12:00:00', BLT_Activity_Log_Pruner::cutoff( 90, $now ) );
		$this->assertSame( '2026-10-15 12:00:00', BLT_Activity_Log_Pruner::cutoff( 1, $now ) );
		$this->assertSame( '2025-10-16 12:00:00', BLT_Activity_Log_Pruner::cutoff( 365, $now ) );
	}

	/**
	 * Zero keeps everything.
	 *
	 * @return void
	 */
	public function test_zero_retention_has_no_cutoff() {
		$this->assertNull( BLT_Activity_Log_Pruner::cutoff( 0 ) );
		$this->assertNull( BLT_Activity_Log_Pruner::cutoff( -5 ) );
	}

	/**
	 * Cron that has not pruned in two days is overdue.
	 *
	 * @return void
	 */
	public function test_overdue_after_two_days() {
		$now = strtotime( '2026-10-16 12:00:00 UTC' );

		$this->assertFalse( BLT_Activity_Log_Pruner::is_overdue( $now - DAY_IN_SECONDS, '', $now ) );
		$this->assertFalse( BLT_Activity_Log_Pruner::is_overdue( $now - ( 2 * DAY_IN_SECONDS ), '', $now ), 'Exactly two days is not yet overdue.' );
		$this->assertTrue( BLT_Activity_Log_Pruner::is_overdue( $now - ( 2 * DAY_IN_SECONDS ) - 1, '', $now ) );
		$this->assertTrue( BLT_Activity_Log_Pruner::is_overdue( $now - ( 30 * DAY_IN_SECONDS ), '', $now ) );
	}

	/**
	 * A fresh install that has never pruned counts from when logging started.
	 *
	 * @return void
	 */
	public function test_never_pruned_counts_from_the_start_date() {
		$now = strtotime( '2026-10-16 12:00:00 UTC' );

		$this->assertFalse( BLT_Activity_Log_Pruner::is_overdue( 0, '2026-10-16 08:00:00', $now ), 'Started this morning.' );
		$this->assertFalse( BLT_Activity_Log_Pruner::is_overdue( 0, '2026-10-15 12:00:00', $now ), 'Started yesterday.' );
		$this->assertTrue( BLT_Activity_Log_Pruner::is_overdue( 0, '2026-10-01 12:00:00', $now ), 'Started two weeks ago and never pruned.' );
	}

	/**
	 * With no baseline at all there is nothing to call overdue.
	 *
	 * @return void
	 */
	public function test_unknown_history_is_not_overdue() {
		$this->assertFalse( BLT_Activity_Log_Pruner::is_overdue( 0, '' ) );
	}
}
