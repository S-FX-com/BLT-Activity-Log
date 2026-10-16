<?php
/**
 * Query: filters to SQL, whitelisted ordering, DST-safe date ranges.
 *
 * @package Blt_Activity_Log
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Activity_Log_Query.
 */
class Test_Query extends TestCase {

	/**
	 * The site timezone used by the date tests.
	 *
	 * @return DateTimeZone
	 */
	private function new_york() {
		return new DateTimeZone( 'America/New_York' );
	}

	/**
	 * No filters means no condition.
	 *
	 * @return void
	 */
	public function test_no_filters() {
		$where = BLT_Activity_Log_Query::build_where( BLT_Activity_Log_Query::normalize_args( array() ), new DateTimeZone( 'UTC' ) );

		$this->assertSame( '1=1', $where['sql'] );
		$this->assertSame( array(), $where['params'] );
	}

	/**
	 * Activity: a whole category, or one action of it.
	 *
	 * @return void
	 */
	public function test_activity_filter() {
		$tz = new DateTimeZone( 'UTC' );

		$all = BLT_Activity_Log_Query::build_where( BLT_Activity_Log_Query::normalize_args( array( 'activity' => 'post' ) ), $tz );

		$this->assertSame( 'category = %s', $all['sql'] );
		$this->assertSame( array( 'post' ), $all['params'] );

		$one = BLT_Activity_Log_Query::build_where( BLT_Activity_Log_Query::normalize_args( array( 'activity' => 'post:updated' ) ), $tz );

		$this->assertSame( 'category = %s AND action = %s', $one['sql'] );
		$this->assertSame( array( 'post', 'updated' ), $one['params'] );
	}

	/**
	 * A malformed activity value is dropped, never interpolated.
	 *
	 * @return void
	 */
	public function test_bad_activity_is_dropped() {
		foreach ( array( "post' OR 1=1 --", 'post:upd ated', ':updated', 'a:b:c', 'POST;DROP', array( 'post' ) ) as $bad ) {
			$args = BLT_Activity_Log_Query::normalize_args( array( 'activity' => $bad ) );

			$this->assertSame( '', $args['activity'], is_string( $bad ) ? $bad : 'array' );
		}
	}

	/**
	 * User: anyone, nobody, an id, or a login.
	 *
	 * @return void
	 */
	public function test_user_filter() {
		$tz = new DateTimeZone( 'UTC' );

		$none = BLT_Activity_Log_Query::build_where( BLT_Activity_Log_Query::normalize_args( array( 'user' => 'none' ) ), $tz );

		$this->assertSame( 'user_id = %d', $none['sql'] );
		$this->assertSame( array( 0 ), $none['params'] );

		$id = BLT_Activity_Log_Query::build_where( BLT_Activity_Log_Query::normalize_args( array( 'user' => '7' ) ), $tz );

		$this->assertSame( 'user_id = %d', $id['sql'] );
		$this->assertSame( array( 7 ), $id['params'] );

		$login = BLT_Activity_Log_Query::build_where( BLT_Activity_Log_Query::normalize_args( array( 'user' => 'shane' ) ), $tz );

		$this->assertSame( 'user_login = %s', $login['sql'] );
		$this->assertSame( array( 'shane' ), $login['params'] );

		$any = BLT_Activity_Log_Query::build_where( BLT_Activity_Log_Query::normalize_args( array( 'user' => '' ) ), $tz );

		$this->assertSame( '1=1', $any['sql'] );
	}

	/**
	 * Search matches the label, with LIKE wildcards in the text escaped.
	 *
	 * @return void
	 */
	public function test_search_filter_escapes_like_wildcards() {
		$where = BLT_Activity_Log_Query::build_where( BLT_Activity_Log_Query::normalize_args( array( 's' => '50%_off' ) ), new DateTimeZone( 'UTC' ) );

		$this->assertSame( 'object_label LIKE %s', $where['sql'] );
		$this->assertSame( array( '%50\%\_off%' ), $where['params'] );
	}

	/**
	 * Dates: invalid ones are dropped.
	 *
	 * @return void
	 */
	public function test_invalid_dates_are_dropped() {
		foreach ( array( '2026-02-30', '2026-13-01', '16/10/2026', '2026-10-1', 'yesterday', '' ) as $bad ) {
			$args = BLT_Activity_Log_Query::normalize_args(
				array(
					'from' => $bad,
					'to'   => $bad,
				)
			);

			$this->assertSame( '', $args['from'], $bad );
			$this->assertSame( '', $args['to'], $bad );
		}

		$this->assertSame( '2028-02-29', BLT_Activity_Log_Query::normalize_args( array( 'from' => '2028-02-29' ) )['from'], 'A real leap day.' );
	}

	/**
	 * An ordinary winter day in New York is UTC-5.
	 *
	 * @return void
	 */
	public function test_utc_range_for_a_normal_day() {
		$range = BLT_Activity_Log_Query::utc_range( '2026-01-15', '2026-01-15', $this->new_york() );

		$this->assertSame( '2026-01-15 05:00:00', $range['from'] );
		$this->assertSame( '2026-01-16 05:00:00', $range['to_exclusive'], '`to` includes all of its day.' );
	}

	/**
	 * Spring forward, 2026-03-08: that local day is 23 hours long.
	 *
	 * Midnight is still EST (UTC-5) and the next midnight is already EDT
	 * (UTC-4), so the day runs 05:00 UTC to 04:00 UTC the next day.
	 *
	 * @return void
	 */
	public function test_utc_range_across_spring_forward() {
		$range = BLT_Activity_Log_Query::utc_range( '2026-03-08', '2026-03-08', $this->new_york() );

		$this->assertSame( '2026-03-08 05:00:00', $range['from'] );
		$this->assertSame( '2026-03-09 04:00:00', $range['to_exclusive'] );

		$seconds = strtotime( $range['to_exclusive'] . ' UTC' ) - strtotime( $range['from'] . ' UTC' );

		$this->assertSame( 23 * 3600, $seconds );
	}

	/**
	 * Fall back, 2026-11-01: that local day is 25 hours long.
	 *
	 * Midnight is EDT (UTC-4) and the next midnight is EST (UTC-5), so the day
	 * runs 04:00 UTC to 05:00 UTC the next day.
	 *
	 * @return void
	 */
	public function test_utc_range_across_fall_back() {
		$range = BLT_Activity_Log_Query::utc_range( '2026-11-01', '2026-11-01', $this->new_york() );

		$this->assertSame( '2026-11-01 04:00:00', $range['from'] );
		$this->assertSame( '2026-11-02 05:00:00', $range['to_exclusive'] );

		$seconds = strtotime( $range['to_exclusive'] . ' UTC' ) - strtotime( $range['from'] . ' UTC' );

		$this->assertSame( 25 * 3600, $seconds );
	}

	/**
	 * A range that spans the change in the middle.
	 *
	 * @return void
	 */
	public function test_utc_range_spanning_a_dst_change() {
		$range = BLT_Activity_Log_Query::utc_range( '2026-03-07', '2026-03-09', $this->new_york() );

		$this->assertSame( '2026-03-07 05:00:00', $range['from'], 'Before the change: UTC-5.' );
		$this->assertSame( '2026-03-10 04:00:00', $range['to_exclusive'], 'After the change: UTC-4.' );
	}

	/**
	 * Only one bound set leaves the other open.
	 *
	 * @return void
	 */
	public function test_open_ended_ranges() {
		$from_only = BLT_Activity_Log_Query::utc_range( '2026-01-15', '', $this->new_york() );

		$this->assertSame( '2026-01-15 05:00:00', $from_only['from'] );
		$this->assertNull( $from_only['to_exclusive'] );

		$to_only = BLT_Activity_Log_Query::utc_range( '', '2026-01-15', $this->new_york() );

		$this->assertNull( $to_only['from'] );
		$this->assertSame( '2026-01-16 05:00:00', $to_only['to_exclusive'] );
	}

	/**
	 * The dates become a half-open range on created_at.
	 *
	 * @return void
	 */
	public function test_date_filter_in_the_where_clause() {
		$where = BLT_Activity_Log_Query::build_where(
			BLT_Activity_Log_Query::normalize_args(
				array(
					'from' => '2026-03-08',
					'to'   => '2026-03-08',
				)
			),
			$this->new_york()
		);

		$this->assertSame( 'created_at >= %s AND created_at < %s', $where['sql'] );
		$this->assertSame( array( '2026-03-08 05:00:00', '2026-03-09 04:00:00' ), $where['params'] );
	}

	/**
	 * Every filter together, in a fixed order.
	 *
	 * @return void
	 */
	public function test_all_filters_combine() {
		$where = BLT_Activity_Log_Query::build_where(
			BLT_Activity_Log_Query::normalize_args(
				array(
					'activity' => 'plugin:updated',
					'user'     => '3',
					'from'     => '2026-01-15',
					's'        => 'akismet',
				)
			),
			$this->new_york()
		);

		$this->assertSame( 'category = %s AND action = %s AND user_id = %d AND created_at >= %s AND object_label LIKE %s', $where['sql'] );
		$this->assertSame( array( 'plugin', 'updated', 3, '2026-01-15 05:00:00', '%akismet%' ), $where['params'] );
	}

	/**
	 * Sorting is limited to a whitelist, and unknown keys fall back.
	 *
	 * @return void
	 */
	public function test_order_by_whitelist() {
		$this->assertSame( 'created_at DESC, id DESC', BLT_Activity_Log_Query::order_by( 'nonsense', 'asc' ) );
		$this->assertSame( 'created_at DESC, id DESC', BLT_Activity_Log_Query::order_by( 'created_at; DROP TABLE x', 'asc' ) );
		$this->assertSame( 'created_at DESC, id DESC', BLT_Activity_Log_Query::order_by( null, 'asc' ) );
		$this->assertSame( 'created_at ASC, id ASC', BLT_Activity_Log_Query::order_by( 'when', 'asc' ) );
		$this->assertSame( 'created_at DESC, id DESC', BLT_Activity_Log_Query::order_by( 'when', 'desc' ) );
		$this->assertSame( 'user_login ASC, id ASC', BLT_Activity_Log_Query::order_by( 'user', 'ASC' ) );
		$this->assertSame( 'category DESC, action DESC, id DESC', BLT_Activity_Log_Query::order_by( 'activity', 'sideways' ), 'A bad direction is descending.' );
	}

	/**
	 * Paging arguments are clamped.
	 *
	 * @return void
	 */
	public function test_paging_is_clamped() {
		$args = BLT_Activity_Log_Query::normalize_args(
			array(
				'paged'    => '-4',
				'per_page' => '99999',
			)
		);

		$this->assertSame( 1, $args['paged'] );
		$this->assertSame( BLT_Activity_Log_Query::MAX_PER_PAGE, $args['per_page'] );

		$defaults = BLT_Activity_Log_Query::normalize_args( array() );

		$this->assertSame( BLT_Activity_Log_Query::DEFAULT_PER_PAGE, $defaults['per_page'] );
		$this->assertSame( 'when', $defaults['orderby'] );
		$this->assertSame( 'desc', $defaults['order'] );
	}
}
