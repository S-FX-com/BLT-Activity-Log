<?php
/**
 * Filter arguments to prepared SQL, and the reads the admin screen needs.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the WHERE and ORDER BY for the log, and runs the reads.
 *
 * The builders are pure: they take plain arguments and a timezone and return
 * a SQL fragment with `%s` / `%d` placeholders plus the values to bind, so the
 * filter-to-SQL mapping and the DST-safe date ranges are unit-tested without
 * a database. Only table names and whitelisted column names are interpolated;
 * every value is bound through `$wpdb->prepare()`.
 */
class BLT_Activity_Log_Query {

	/**
	 * Default and allowed page sizes.
	 */
	const DEFAULT_PER_PAGE = 50;
	const MAX_PER_PAGE     = 200;

	/**
	 * Sort keys the list may use, mapped to the column sorted on.
	 */
	const SORTABLE = array(
		'when'     => 'created_at',
		'user'     => 'user_login',
		'activity' => 'category',
	);

	/**
	 * Columns read for the list. `details` is included because the Summary
	 * column is built from it; `user_agent` is only needed on the detail view.
	 */
	const LIST_COLUMNS = 'id, created_at, last_at, occurrences, user_id, user_login, user_role, category, action, object_type, object_id, object_key, object_label, context, ip, details';

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'blt_activity_log_entries';
	}

	/**
	 * Clean raw request arguments into the arguments the builders accept.
	 *
	 * @param array $raw Unslashed request arguments.
	 * @return array
	 */
	public static function normalize_args( array $raw ) {
		$args = array(
			'activity' => '',
			'user'     => '',
			'from'     => '',
			'to'       => '',
			's'        => '',
			'orderby'  => 'when',
			'order'    => 'desc',
			'paged'    => 1,
			'per_page' => self::DEFAULT_PER_PAGE,
		);

		if ( isset( $raw['activity'] ) && is_string( $raw['activity'] ) ) {
			$activity = strtolower( trim( $raw['activity'] ) );

			if ( 1 === preg_match( '/^[a-z0-9_]+(:[a-z0-9_]+)?$/', $activity ) ) {
				$args['activity'] = $activity;
			}
		}

		if ( isset( $raw['user'] ) && is_scalar( $raw['user'] ) ) {
			$user = trim( (string) $raw['user'] );

			if ( 'none' === $user || ( ctype_digit( $user ) && '' !== $user ) ) {
				$args['user'] = $user;
			} elseif ( '' !== $user ) {
				$args['user'] = 'login:' . substr( $user, 0, 60 );
			}
		}

		foreach ( array( 'from', 'to' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && self::valid_date( $raw[ $key ] ) ) {
				$args[ $key ] = $raw[ $key ];
			}
		}

		if ( isset( $raw['s'] ) && is_string( $raw['s'] ) ) {
			$args['s'] = substr( trim( $raw['s'] ), 0, 200 );
		}

		if ( isset( $raw['orderby'] ) && is_string( $raw['orderby'] ) && isset( self::SORTABLE[ $raw['orderby'] ] ) ) {
			$args['orderby'] = $raw['orderby'];
		}

		if ( isset( $raw['order'] ) && is_string( $raw['order'] ) && 'asc' === strtolower( $raw['order'] ) ) {
			$args['order'] = 'asc';
		}

		if ( isset( $raw['paged'] ) ) {
			$args['paged'] = max( 1, (int) $raw['paged'] );
		}

		if ( isset( $raw['per_page'] ) ) {
			$args['per_page'] = max( 1, min( self::MAX_PER_PAGE, (int) $raw['per_page'] ) );
		}

		return $args;
	}

	/**
	 * Whether a string is a real `Y-m-d` calendar date.
	 *
	 * @param string $date Candidate.
	 * @return bool
	 */
	public static function valid_date( $date ) {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}

		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $date ) );

		return checkdate( $month, $day, $year );
	}

	/**
	 * Convert a site-local date range to UTC bounds.
	 *
	 * `from` is the start of that local day. `to` is inclusive of its whole
	 * day, so the upper bound is the start of the NEXT local day and is
	 * exclusive. Adding the day on the wall clock (not 86,400 seconds) keeps
	 * the range right across a daylight-saving change, where a local day is 23
	 * or 25 hours long.
	 *
	 * @param string       $from Local `Y-m-d`, or ''.
	 * @param string       $to   Local `Y-m-d`, or ''.
	 * @param DateTimeZone $tz   Site timezone.
	 * @return array{from:string|null,to_exclusive:string|null} UTC `Y-m-d H:i:s`.
	 */
	public static function utc_range( $from, $to, DateTimeZone $tz ) {
		$utc    = new DateTimeZone( 'UTC' );
		$result = array(
			'from'         => null,
			'to_exclusive' => null,
		);

		if ( '' !== $from && self::valid_date( $from ) ) {
			$start          = new DateTimeImmutable( $from . ' 00:00:00', $tz );
			$result['from'] = $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		}

		if ( '' !== $to && self::valid_date( $to ) ) {
			$end                    = ( new DateTimeImmutable( $to . ' 00:00:00', $tz ) )->modify( '+1 day' );
			$result['to_exclusive'] = $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		}

		return $result;
	}

	/**
	 * Escape a LIKE fragment (the same rule as `$wpdb->esc_like()`).
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * Build the WHERE clause for a set of normalized arguments.
	 *
	 * @param array        $args Normalized arguments.
	 * @param DateTimeZone $tz   Site timezone.
	 * @return array{sql:string,params:array} `sql` is '1=1' when nothing filters.
	 */
	public static function build_where( array $args, DateTimeZone $tz ) {
		$clauses = array();
		$params  = array();

		$activity = isset( $args['activity'] ) ? (string) $args['activity'] : '';

		if ( '' !== $activity ) {
			$parts = explode( ':', $activity, 2 );

			$clauses[] = 'category = %s';
			$params[]  = $parts[0];

			if ( isset( $parts[1] ) && '' !== $parts[1] ) {
				$clauses[] = 'action = %s';
				$params[]  = $parts[1];
			}
		}

		$user = isset( $args['user'] ) ? (string) $args['user'] : '';

		if ( 'none' === $user ) {
			$clauses[] = 'user_id = %d';
			$params[]  = 0;
		} elseif ( '' !== $user && ctype_digit( $user ) ) {
			$clauses[] = 'user_id = %d';
			$params[]  = (int) $user;
		} elseif ( 0 === strpos( $user, 'login:' ) ) {
			$clauses[] = 'user_login = %s';
			$params[]  = substr( $user, 6 );
		}

		$range = self::utc_range(
			isset( $args['from'] ) ? (string) $args['from'] : '',
			isset( $args['to'] ) ? (string) $args['to'] : '',
			$tz
		);

		if ( null !== $range['from'] ) {
			$clauses[] = 'created_at >= %s';
			$params[]  = $range['from'];
		}

		if ( null !== $range['to_exclusive'] ) {
			$clauses[] = 'created_at < %s';
			$params[]  = $range['to_exclusive'];
		}

		$search = isset( $args['s'] ) ? (string) $args['s'] : '';

		if ( '' !== $search ) {
			$clauses[] = 'object_label LIKE %s';
			$params[]  = '%' . self::esc_like( $search ) . '%';
		}

		return array(
			'sql'    => empty( $clauses ) ? '1=1' : implode( ' AND ', $clauses ),
			'params' => $params,
		);
	}

	/**
	 * The ORDER BY clause for a sort key and direction.
	 *
	 * An unknown key falls back to newest first. `id` is the tiebreaker, so
	 * paging through entries made in the same second is stable.
	 *
	 * @param string $orderby Sort key.
	 * @param string $order   'asc' or 'desc'.
	 * @return string
	 */
	public static function order_by( $orderby, $order ) {
		if ( ! is_string( $orderby ) || ! isset( self::SORTABLE[ $orderby ] ) ) {
			return 'created_at DESC, id DESC';
		}

		$direction = 'asc' === strtolower( (string) $order ) ? 'ASC' : 'DESC';
		$column    = self::SORTABLE[ $orderby ];

		if ( 'category' === $column ) {
			return "category {$direction}, action {$direction}, id {$direction}";
		}

		return "{$column} {$direction}, id {$direction}";
	}

	/**
	 * Entries matching the arguments, one page.
	 *
	 * @param array $args Normalized arguments.
	 * @return array<int,object>
	 */
	public static function find( array $args ) {
		global $wpdb;

		$where  = self::build_where( $args, self::timezone() );
		$table  = self::table();
		$offset = ( max( 1, (int) $args['paged'] ) - 1 ) * (int) $args['per_page'];
		$order  = self::order_by( $args['orderby'], $args['order'] );
		$cols   = self::LIST_COLUMNS;

		$params   = $where['params'];
		$params[] = (int) $args['per_page'];
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table, columns and ORDER BY come from whitelists; the WHERE placeholders come from build_where(); every value is bound.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$cols} FROM {$table} WHERE {$where['sql']} ORDER BY {$order} LIMIT %d OFFSET %d", $params ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many entries match the arguments.
	 *
	 * @param array $args Normalized arguments.
	 * @return int
	 */
	public static function count( array $args ) {
		global $wpdb;

		$where = self::build_where( $args, self::timezone() );
		$table = self::table();

		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where['sql']}";

		if ( ! empty( $where['params'] ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table is ours; the placeholders sit in the WHERE fragment built by build_where(), and every value is bound.
			$sql = $wpdb->prepare( $sql, $where['params'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * How many entries are older than a UTC time, for the Clear log preview.
	 *
	 * @param string $cutoff `Y-m-d H:i:s` in UTC.
	 * @return int
	 */
	public static function count_before( $cutoff ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is ours; the time is bound.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at < %s", (string) $cutoff ) );
	}

	/**
	 * The highest entry id, or 0 for an empty log.
	 *
	 * @return int
	 */
	public static function max_id() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		return (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
	}

	/**
	 * One entry by id.
	 *
	 * @param int $id Entry id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is ours; id is bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );

		return $row ? $row : null;
	}

	/**
	 * A batch of entries for the export, keyed on `id` so memory stays flat.
	 *
	 * Newest first. Pass the smallest id of the previous batch as `$before_id`
	 * (0 for the first batch).
	 *
	 * @param array $args      Normalized arguments.
	 * @param int   $before_id Return only entries with a smaller id (0 = no bound).
	 * @param int   $limit     Batch size.
	 * @return array<int,object>
	 */
	public static function export_batch( array $args, $before_id, $limit ) {
		global $wpdb;

		$where  = self::build_where( $args, self::timezone() );
		$table  = self::table();
		$params = $where['params'];
		$sql    = $where['sql'];

		if ( (int) $before_id > 0 ) {
			$sql     .= ' AND id < %d';
			$params[] = (int) $before_id;
		}

		$params[] = max( 1, (int) $limit );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is ours; values are bound.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql} ORDER BY id DESC LIMIT %d", $params ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every distinct user seen in the log, for the User filter.
	 *
	 * @param int $limit Stop reading after this many rows.
	 * @return array<int,object> Rows with `user_id` and `user_login`.
	 */
	public static function distinct_users( $limit = 201 ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is ours; limit is bound.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, MAX(user_login) AS user_login FROM {$table} WHERE user_id > 0 GROUP BY user_id ORDER BY user_login ASC LIMIT %d", max( 1, (int) $limit ) ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every `category:action` pair present in the log.
	 *
	 * @return array<int,object> Rows with `category` and `action`.
	 */
	public static function activity_pairs() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		$rows = $wpdb->get_results( "SELECT DISTINCT category, action FROM {$table} ORDER BY category ASC, action ASC" );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Row count, and the oldest and newest entry times (UTC).
	 *
	 * @return array{total:int,oldest:string|null,newest:string|null}
	 */
	public static function summary() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		$row = $wpdb->get_row( "SELECT COUNT(*) AS total, MIN(created_at) AS oldest, MAX(created_at) AS newest FROM {$table}" );

		return array(
			'total'  => $row ? (int) $row->total : 0,
			'oldest' => ( $row && $row->oldest ) ? (string) $row->oldest : null,
			'newest' => ( $row && $row->newest ) ? (string) $row->newest : null,
		);
	}

	/**
	 * The site timezone.
	 *
	 * @return DateTimeZone
	 */
	public static function timezone() {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}
}
