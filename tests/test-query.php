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

	/**
	 * The end of a day is the start of the next one, even where the clocks jump
	 * at midnight and the next day (or this one) starts at 01:00.
	 *
	 * @return void
	 */
	public function test_end_of_day_is_the_start_of_the_next_day_where_dst_begins_at_midnight() {
		$zones = array(
			array( 'America/Havana', '2013-03-10' ),
			array( 'Asia/Beirut', '2015-03-29' ),
			array( 'America/Sao_Paulo', '2018-11-04' ),
			array( 'Africa/Cairo', '2010-04-30' ),
			array( 'America/Santiago', '2013-09-08' ),
			array( 'America/New_York', '2026-03-08' ),
			array( 'Asia/Kolkata', '2026-03-08' ),
		);

		foreach ( $zones as $case ) {
			list( $zone, $day ) = $case;

			$tz   = new DateTimeZone( $zone );
			$next = ( new DateTimeImmutable( $day, new DateTimeZone( 'UTC' ) ) )->modify( '+1 day' )->format( 'Y-m-d' );

			$this->assertSame(
				BLT_Activity_Log_Query::utc_range( $next, '', $tz )['from'],
				BLT_Activity_Log_Query::utc_range( '', $day, $tz )['to_exclusive'],
				"$zone $day: the day ends where the next one starts."
			);
		}

		// One worked value: Cuba's clocks jump 00:00 to 01:00 on 2013-03-10, so the next midnight is UTC-4.
		$this->assertSame( '2013-03-11 04:00:00', BLT_Activity_Log_Query::utc_range( '', '2013-03-10', new DateTimeZone( 'America/Havana' ) )['to_exclusive'] );
		// And the day that starts at 01:00 starts at 01:00 (05:00 UTC).
		$this->assertSame( '2013-03-10 05:00:00', BLT_Activity_Log_Query::utc_range( '2013-03-10', '', new DateTimeZone( 'America/Havana' ) )['from'] );
	}

	/**
	 * Hyphens survive in an activity key: the Logger keeps them (`sanitize_key`).
	 *
	 * @return void
	 */
	public function test_activity_keys_may_contain_hyphens() {
		$this->assertSame( 'blt-secure:waf-rule-deployed', BLT_Activity_Log_Query::normalize_args( array( 'activity' => 'blt-secure:waf-rule-deployed' ) )['activity'] );
		$this->assertSame( 'post', BLT_Activity_Log_Query::normalize_args( array( 'activity' => 'post' ) )['activity'] );
		$this->assertSame( '', BLT_Activity_Log_Query::normalize_args( array( 'activity' => 'a b' ) )['activity'], 'Spaces are still refused.' );
		$this->assertSame( '', BLT_Activity_Log_Query::normalize_args( array( 'activity' => "x' OR 1=1" ) )['activity'] );
	}

	/**
	 * Long search text and logins are cut by characters, never through one.
	 *
	 * @return void
	 */
	public function test_long_multibyte_text_is_cut_on_a_character_boundary() {
		$search = BLT_Activity_Log_Query::normalize_args( array( 's' => str_repeat( '日', 300 ) ) )['s'];

		$this->assertTrue( mb_check_encoding( $search, 'UTF-8' ) );
		$this->assertSame( 200, mb_strlen( $search ) );

		$user = BLT_Activity_Log_Query::normalize_args( array( 'user' => str_repeat( 'é', 100 ) ) )['user'];

		$this->assertTrue( mb_check_encoding( $user, 'UTF-8' ) );
		$this->assertSame( 'login:' . str_repeat( 'é', 60 ), $user );
	}

	/**
	 * A page number too big to multiply is held at a sane ceiling.
	 *
	 * @return void
	 */
	public function test_a_huge_page_number_is_capped() {
		$this->assertSame( BLT_Activity_Log_Query::MAX_PAGED, BLT_Activity_Log_Query::normalize_args( array( 'paged' => '99999999999999999999' ) )['paged'] );
		$this->assertSame( BLT_Activity_Log_Query::MAX_PAGED, BLT_Activity_Log_Query::normalize_args( array( 'paged' => PHP_INT_MAX ) )['paged'] );
		$this->assertSame( 1, BLT_Activity_Log_Query::normalize_args( array( 'paged' => '-4' ) )['paged'] );

		$offset = ( BLT_Activity_Log_Query::MAX_PAGED - 1 ) * BLT_Activity_Log_Query::MAX_PER_PAGE;

		$this->assertIsInt( $offset, 'The largest offset stays an integer.' );
	}

	/**
	 * One object: by id or by key, never by its label.
	 *
	 * @return void
	 */
	public function test_item_filter() {
		$by_id = BLT_Activity_Log_Query::build_where( BLT_Activity_Log_Query::normalize_args( array( 'item' => 'post|id:42' ) ), new DateTimeZone( 'UTC' ) );

		$this->assertSame( 'object_type = %s AND object_id = %d', $by_id['sql'] );
		$this->assertSame( array( 'post', 42 ), $by_id['params'] );

		$by_key = BLT_Activity_Log_Query::build_where( BLT_Activity_Log_Query::normalize_args( array( 'item' => 'plugin|key:akismet/akismet.php' ) ), new DateTimeZone( 'UTC' ) );

		$this->assertSame( 'object_type = %s AND object_key = %s', $by_key['sql'] );
		$this->assertSame( array( 'plugin', 'akismet/akismet.php' ), $by_key['params'] );

		foreach ( array( 'post|id:abc', 'post|id:', '|id:4', 'post', 'post|bogus:4', 'Post Type|id:4', array( 'post|id:1' ), "post|id:1' OR '1'='1" ) as $bad ) {
			$this->assertSame( '', BLT_Activity_Log_Query::normalize_args( array( 'item' => $bad ) )['item'], is_string( $bad ) ? $bad : 'array' );
		}

		$this->assertSame( 'post|id:7', BLT_Activity_Log_Query::normalize_args( array( 'item' => 'post|id:0007' ) )['item'], 'The id is normalized.' );
	}
}
