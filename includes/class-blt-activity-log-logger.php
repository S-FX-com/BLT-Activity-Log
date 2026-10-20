<?php
/**
 * Entry buffer, merge, coalesce and write.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receives entries from the modules, merges the ones that describe one
 * change, and writes them once, on `shutdown`.
 *
 * The rules (CLAUDE.md 4.3) in one place:
 *
 * - Never break the action being logged. Every public method catches
 *   Throwable; a database failure is reported with one error_log() line per
 *   request and otherwise swallowed.
 * - Buffer, then write once: entries wait in memory and go in as a single
 *   multi-row INSERT.
 * - Merge within a request, keyed by (category, type, object), so a post save
 *   that also fires term and meta hooks is one entry.
 * - Coalesce across requests: a repeated `updated` within the coalesce window
 *   is merged into the stored row instead of adding another.
 * - An `updated` that recorded no change is not an entry.
 */
class BLT_Activity_Log_Logger {

	/**
	 * Entries held before an early flush, so a long WP-CLI run stays bounded.
	 */
	const MAX_BUFFER = 500;

	/**
	 * Rows per INSERT statement.
	 */
	const INSERT_CHUNK = 100;

	/**
	 * Actions an `updated` entry may fold into when it finds one buffered for
	 * the same object (a status change, or the creation, in the same request).
	 */
	const FOLD_ACTIONS = array( 'created', 'updated', 'published', 'unpublished', 'scheduled', 'made_private', 'trashed', 'restored' );

	/**
	 * Column formats, in insert order. Null values are written as NULL.
	 */
	const COLUMNS = array(
		'created_at'   => '%s',
		'last_at'      => '%s',
		'occurrences'  => '%d',
		'user_id'      => '%d',
		'user_login'   => '%s',
		'user_role'    => '%s',
		'category'     => '%s',
		'action'       => '%s',
		'object_type'  => '%s',
		'object_id'    => '%d',
		'object_key'   => '%s',
		'object_label' => '%s',
		'context'      => '%s',
		'ip'           => '%s',
		'user_agent'   => '%s',
		'details'      => '%s',
	);

	/**
	 * Buffered entries, keyed by identity and action.
	 *
	 * @var array<string,array>
	 */
	private static $buffer = array();

	/**
	 * Counter that keeps keys unique for entries with no identity to merge on.
	 *
	 * @var int
	 */
	private static $sequence = 0;

	/**
	 * Whether the shutdown flush is hooked.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Whether a failure was already reported in this request.
	 *
	 * @var bool
	 */
	private static $reported = false;

	/**
	 * Clear all state (tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$buffer   = array();
		self::$sequence = 0;
		self::$hooked   = false;
		self::$reported = false;
	}

	/**
	 * The entries waiting to be written (tests).
	 *
	 * @return array<string,array>
	 */
	public static function buffered() {
		return self::$buffer;
	}

	/**
	 * Add an entry to the buffer.
	 *
	 * @param array $entry {
	 *     The entry.
	 *
	 *     @type string      $category     Module id or external category.
	 *     @type string      $action       Action key.
	 *     @type string      $object_type  Post type, taxonomy, 'plugin', 'option' ...
	 *     @type int|null    $object_id    Numeric object.
	 *     @type string|null $object_key    Non-numeric object.
	 *     @type string      $object_label Title or name, as it is now.
	 *     @type array       $details      See CLAUDE.md 5.2.
	 *     @type string[]    $absorb       Actions of a buffered entry for the same
	 *                                     object this one should be merged into.
	 *     @type int         $user_id      Optional actor override (with
	 *                                     `user_login`, `user_role`, `context`).
	 * }
	 * @return void
	 */
	public static function record( array $entry ) {
		try {
			$entry = self::normalize( $entry );

			if ( null === $entry ) {
				return;
			}

			self::add( $entry );
			self::hook();

			if ( count( self::$buffer ) >= self::MAX_BUFFER ) {
				self::flush();
			}
		} catch ( \Throwable $e ) {
			self::report( $e );
		}
	}

	/**
	 * Write every buffered entry. Runs on `shutdown`.
	 *
	 * @return void
	 */
	public static function flush() {
		$guard = 0;

		// A `blt_activity_log_logged` listener can record again; drain that too.
		while ( ! empty( self::$buffer ) && $guard < 5 ) {
			++$guard;

			$entries      = array_values( self::$buffer );
			self::$buffer = array();

			self::write_entries( $entries );
		}
	}

	/**
	 * Write one entry immediately, bypassing the buffer.
	 *
	 * For entries whose request may not reach `shutdown` normally, such as the
	 * plugin's own activation.
	 *
	 * @param array $entry Entry, as for record().
	 * @return int Row id, or 0 when nothing was written.
	 */
	public static function write_now( array $entry ) {
		try {
			$entry = self::normalize( $entry );

			if ( null === $entry ) {
				return 0;
			}

			$written = self::write_entries( array( $entry ) );

			return $written ? (int) $written[0] : 0;
		} catch ( \Throwable $e ) {
			self::report( $e );

			return 0;
		}
	}

	/**
	 * Validate and complete an entry. Returns null for an unusable one.
	 *
	 * @param array $entry Raw entry.
	 * @return array|null
	 */
	private static function normalize( array $entry ) {
		$category = isset( $entry['category'] ) ? sanitize_key( (string) $entry['category'] ) : '';
		$action   = isset( $entry['action'] ) ? sanitize_key( (string) $entry['action'] ) : '';

		if ( '' === $category || '' === $action ) {
			return null;
		}

		$object_id  = ( isset( $entry['object_id'] ) && '' !== $entry['object_id'] ) ? absint( $entry['object_id'] ) : null;
		$object_key = ( isset( $entry['object_key'] ) && '' !== (string) $entry['object_key'] ) ? self::cut( (string) $entry['object_key'], 191 ) : null;
		$label      = isset( $entry['object_label'] ) ? self::plain( (string) $entry['object_label'] ) : '';

		$normalized = array(
			'category'     => self::cut( $category, 32 ),
			'action'       => self::cut( $action, 48 ),
			'object_type'  => isset( $entry['object_type'] ) ? self::cut( sanitize_key( (string) $entry['object_type'] ), 64 ) : '',
			'object_id'    => $object_id,
			'object_key'   => $object_key,
			'object_label' => self::cut( $label, 255 ),
			'details'      => isset( $entry['details'] ) && is_array( $entry['details'] ) ? $entry['details'] : array(),
			'absorb'       => isset( $entry['absorb'] ) && is_array( $entry['absorb'] ) ? array_values( $entry['absorb'] ) : array(),
			// An entry from another plugin: kept even with no recognized detail, and never merged into an earlier row.
			'keep'         => ! empty( $entry['keep'] ),
		);

		$override = array();

		foreach ( array( 'user_id', 'user_login', 'user_role', 'context' ) as $field ) {
			if ( isset( $entry[ $field ] ) ) {
				$override[ $field ] = $entry[ $field ];
			}
		}

		$context = BLT_Activity_Log_Context::snapshot( $override );

		$context['user_login'] = self::cut( $context['user_login'], 60 );
		$context['user_role']  = self::cut( $context['user_role'], 64 );

		$normalized = array_merge( $normalized, $context );

		// A malformed time would fail the whole multi-row INSERT in strict mode, so it falls back to now.
		$time                     = ( isset( $entry['created_at'] ) && is_string( $entry['created_at'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $entry['created_at'] ) ) ? $entry['created_at'] : gmdate( 'Y-m-d H:i:s' );
		$normalized['created_at'] = $time;
		$normalized['last_at']    = $time;

		return $normalized;
	}

	/**
	 * Put an entry in the buffer, merging it into a sibling where one exists.
	 *
	 * @param array $entry Normalized entry.
	 * @return void
	 */
	private static function add( array $entry ) {
		$identity = self::identity( $entry );

		if ( null === $identity ) {
			self::$buffer[ 'solo|' . ( ++self::$sequence ) ] = $entry;

			return;
		}

		// Fold into a buffered entry for the same object, whatever its action:
		// a term or meta hook that fires beside a status change belongs to it.
		// Every listed sibling folds in, not only the first: a new account has
		// profile-field changes and a role change waiting when it is announced.
		$merged = null;

		foreach ( $entry['absorb'] as $action ) {
			$key = $identity . '|' . $action;

			if ( isset( self::$buffer[ $key ] ) ) {
				$merged = BLT_Activity_Log_Coalescer::merge_buffered( self::$buffer[ $key ], null === $merged ? $entry : $merged );

				unset( self::$buffer[ $key ] );
			}
		}

		if ( null !== $merged ) {
			self::$buffer[ $identity . '|' . $merged['action'] ] = $merged;

			return;
		}

		$key = $identity . '|' . $entry['action'];

		if ( isset( self::$buffer[ $key ] ) ) {
			self::$buffer[ $key ] = BLT_Activity_Log_Coalescer::merge_buffered( self::$buffer[ $key ], $entry );

			return;
		}

		self::$buffer[ $key ] = $entry;
	}

	/**
	 * What makes two entries "the same object".
	 *
	 * @param array $entry Normalized entry.
	 * @return string|null Null when the entry names no object.
	 */
	private static function identity( array $entry ) {
		if ( null !== $entry['object_id'] ) {
			return $entry['category'] . '|' . $entry['object_type'] . '|i' . $entry['object_id'];
		}

		if ( null !== $entry['object_key'] ) {
			return $entry['category'] . '|' . $entry['object_type'] . '|k' . $entry['object_key'];
		}

		return null;
	}

	/**
	 * Hook the flush to `shutdown`, once.
	 *
	 * `shutdown` also fires after `exit` and after a fatal error, which is why
	 * the write waits for it.
	 *
	 * @return void
	 */
	private static function hook() {
		if ( self::$hooked ) {
			return;
		}

		self::$hooked = true;

		add_action( 'shutdown', array( __CLASS__, 'flush' ), 0 );
	}

	/**
	 * Filter, clean, coalesce and insert a list of entries.
	 *
	 * @param array[] $entries Normalized entries.
	 * @return int[] Ids of the rows inserted (merged entries have none).
	 */
	private static function write_entries( array $entries ) {
		global $wpdb;

		if ( ! $wpdb ) {
			return array();
		}

		$previous = $wpdb->suppress_errors( true );
		$to_write = array();
		$ids      = array();

		foreach ( $entries as $entry ) {
			// A filter that throws, or a merge that fails, costs that entry and no other.
			try {
				$entry = self::finalize( $entry );

				if ( null === $entry ) {
					continue;
				}

				if ( self::try_coalesce( $entry ) ) {
					continue;
				}

				$to_write[] = $entry;
			} catch ( \Throwable $e ) {
				self::report( $e );
			}
		}

		try {
			$ids = self::insert( $to_write );
		} catch ( \Throwable $e ) {
			self::report( $e );
		}

		$wpdb->suppress_errors( $previous );

		return $ids;
	}

	/**
	 * Apply the filters, then redaction and size caps.
	 *
	 * @param array $entry Normalized entry.
	 * @return array|null Null when the entry should not be written.
	 */
	private static function finalize( array $entry ) {
		if ( empty( $entry['keep'] ) && BLT_Activity_Log_Coalescer::is_empty_update( $entry ) ) {
			return null;
		}

		/**
		 * Veto an entry.
		 *
		 * @param bool  $log   Whether to write it. Default true.
		 * @param array $entry The entry.
		 */
		if ( ! apply_filters( 'blt_activity_log_should_log', true, $entry ) ) {
			return null;
		}

		/**
		 * Modify an entry before it is written.
		 *
		 * @param array $entry The entry.
		 */
		$filtered = apply_filters( 'blt_activity_log_entry', $entry );

		if ( is_array( $filtered ) ) {
			foreach ( array( 'category', 'action', 'object_type', 'object_id', 'object_key', 'object_label', 'details' ) as $field ) {
				if ( array_key_exists( $field, $filtered ) ) {
					$entry[ $field ] = $filtered[ $field ];
				}
			}

			if ( ! is_array( $entry['details'] ) ) {
				$entry['details'] = array();
			}
		}

		// Redaction and caps run after the filters, so nothing a filter adds
		// can reach the table unredacted, or too long for its column (which would fail a whole
		// multi-row INSERT in strict mode).
		foreach ( array(
			'category'     => 32,
			'action'       => 48,
			'object_type'  => 64,
			'object_label' => 255,
		) as $field => $max ) {
			$entry[ $field ] = self::cut( is_scalar( $entry[ $field ] ) ? (string) $entry[ $field ] : '', $max );
		}

		if ( '' === $entry['category'] || '' === $entry['action'] ) {
			return null;
		}

		$entry['object_id']  = ( isset( $entry['object_id'] ) && '' !== $entry['object_id'] && null !== $entry['object_id'] ) ? absint( $entry['object_id'] ) : null;
		$entry['object_key'] = ( isset( $entry['object_key'] ) && is_scalar( $entry['object_key'] ) && '' !== (string) $entry['object_key'] ) ? self::cut( (string) $entry['object_key'], 191 ) : null;

		$entry['details'] = BLT_Activity_Log_Redactor::sanitize_details( $entry['details'], BLT_Activity_Log_Redactor::max_length() );

		return $entry;
	}

	/**
	 * Merge an `updated` entry into a recent stored row for the same object.
	 *
	 * @param array $entry Cleaned entry.
	 * @return bool True when the entry was merged (and needs no insert).
	 */
	private static function try_coalesce( array $entry ) {
		global $wpdb;

		if ( 'updated' !== $entry['action'] || ! empty( $entry['keep'] ) ) {
			return false;
		}

		if ( null === $entry['object_id'] && null === $entry['object_key'] ) {
			return false;
		}

		/**
		 * Filter the window, in seconds, within which a repeated save of one
		 * object by one user is merged into the earlier entry. 0 turns it off.
		 *
		 * @param int $seconds Default 60.
		 */
		$window = (int) apply_filters( 'blt_activity_log_coalesce_window', 60 );

		if ( $window <= 0 ) {
			return false;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', max( 0, strtotime( $entry['created_at'] . ' UTC' ) - $window ) );
		$table  = BLT_Activity_Log_Query::table();

		if ( null !== $entry['object_id'] ) {
			$object_sql = 'object_id = %d';
			$object_arg = (int) $entry['object_id'];
		} else {
			$object_sql = 'object_key = %s';
			$object_arg = (string) $entry['object_key'];
		}

		// Two requests can merge into the same row at once (a Bricks save and a meta box, a double click).
		// The write names the version it read, and a loser reads the winner's row and merges on top of it.
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			// The newest entry for this user and object, of any action: an
			// `updated` is never merged across a publish or a trash.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is ours; the object condition is one of two fixed strings; values are bound.
			$row = $wpdb->get_row(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- {$object_sql} carries the fifth placeholder.
				$wpdb->prepare(
					"SELECT id, action, occurrences, last_at, details FROM {$table} WHERE user_id = %d AND category = %s AND object_type = %s AND {$object_sql} AND last_at >= %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(int) $entry['user_id'],
					$entry['category'],
					$entry['object_type'],
					$object_arg,
					$cutoff
				)
			);

			if ( ! $row || ! BLT_Activity_Log_Coalescer::can_absorb( (string) $row->action, 'updated' ) ) {
				return false;
			}

			$stored = ! empty( $row->details ) ? json_decode( (string) $row->details, true ) : array();

			$merged = BLT_Activity_Log_Coalescer::merge_row(
				array(
					'action'      => (string) $row->action,
					'occurrences' => (int) $row->occurrences,
					'details'     => is_array( $stored ) ? $stored : array(),
				),
				$entry
			);

			$where         = array(
				'id'          => (int) $row->id,
				'occurrences' => (int) $row->occurrences,
				'last_at'     => (string) $row->last_at,
			);
			$where_formats = array( '%d', '%d', '%s' );

			if ( $merged['drop'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$deleted = $wpdb->delete( $table, $where, $where_formats );

				if ( false === $deleted ) {
					self::report( 'merge failed: ' . $wpdb->last_error );

					return false;
				}

				if ( 1 === (int) $deleted ) {
					return true;
				}

				continue;
			}

			$data    = array(
				'details'     => BLT_Activity_Log_Redactor::encode( BLT_Activity_Log_Redactor::cap_details( $merged['details'] ) ),
				'occurrences' => $merged['occurrences'],
				'last_at'     => $merged['last_at'],
			);
			$formats = array( '%s', '%d', '%s' );

			if ( '' !== $merged['object_label'] ) {
				$data['object_label'] = $merged['object_label'];
				$formats[]            = '%s';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update( $table, $data, $where, $formats, $where_formats );

			if ( false === $updated ) {
				self::report( 'update failed: ' . $wpdb->last_error );

				return false;
			}

			if ( 1 === (int) $updated ) {
				return true;
			}
		}

		// Still losing the race after three reads: record this save as its own row rather than lose it.
		return false;
	}

	/**
	 * Insert rows: one multi-row INSERT per chunk, or row by row when a
	 * `blt_activity_log_logged` listener needs each id.
	 *
	 * @param array[] $entries Cleaned entries.
	 * @return int[] Ids of the inserted rows (empty for a multi-row insert).
	 */
	private static function insert( array $entries ) {
		global $wpdb;

		if ( empty( $entries ) ) {
			return array();
		}

		$table = BLT_Activity_Log_Query::table();
		$ids   = array();

		// Row by row when a listener needs each id, or when there is only one
		// entry (a multi-row INSERT cannot say which id is whose).
		if ( 1 === count( $entries ) || has_action( 'blt_activity_log_logged' ) ) {
			foreach ( $entries as $entry ) {
				$id = self::insert_one( $entry );

				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}

			return $ids;
		}

		$columns = implode( ', ', array_keys( self::COLUMNS ) );

		foreach ( array_chunk( $entries, self::INSERT_CHUNK ) as $chunk ) {
			$tuples = array();
			$values = array();

			foreach ( $chunk as $entry ) {
				$row          = self::row( $entry );
				$placeholders = array();

				foreach ( self::COLUMNS as $column => $format ) {
					if ( null === $row[ $column ] ) {
						$placeholders[] = 'NULL';
						continue;
					}

					$placeholders[] = $format;
					$values[]       = $row[ $column ];
				}

				$tuples[] = '(' . implode( ', ', $placeholders ) . ')';
			}

			$sql = "INSERT INTO {$table} ({$columns}) VALUES " . implode( ', ', $tuples );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table and column names are ours; the placeholders are generated per row above, and every value is bound.
			if ( false === $wpdb->query( $wpdb->prepare( $sql, $values ) ) ) {
				self::report( 'insert failed: ' . $wpdb->last_error );

				// One bad row should not take its 99 neighbors with it: try them one at a time.
				foreach ( $chunk as $entry ) {
					self::insert_one( $entry );
				}
			}
		}

		return $ids;
	}

	/**
	 * Insert one row and announce it.
	 *
	 * @param array $entry Cleaned entry.
	 * @return int The row id, or 0 when the insert failed.
	 */
	private static function insert_one( array $entry ) {
		global $wpdb;

		$row = self::row( $entry );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $wpdb->insert( BLT_Activity_Log_Query::table(), $row, array_values( self::COLUMNS ) ) ) {
			self::report( 'insert failed: ' . $wpdb->last_error );

			return 0;
		}

		$id = (int) $wpdb->insert_id;

		// A listener that throws must not stop the rows after this one.
		try {
			/**
			 * Fires after an entry is written.
			 *
			 * @param array $entry The stored entry.
			 * @param int   $id    Row id.
			 */
			do_action( 'blt_activity_log_logged', $entry, $id );
		} catch ( \Throwable $e ) {
			self::report( $e );
		}

		return $id;
	}

	/**
	 * The column values for one entry.
	 *
	 * @param array $entry Cleaned entry.
	 * @return array<string,mixed>
	 */
	private static function row( array $entry ) {
		$details = isset( $entry['details'] ) && is_array( $entry['details'] ) ? $entry['details'] : array();

		return array(
			'created_at'   => $entry['created_at'],
			'last_at'      => $entry['last_at'],
			'occurrences'  => 1,
			'user_id'      => (int) $entry['user_id'],
			'user_login'   => (string) $entry['user_login'],
			'user_role'    => (string) $entry['user_role'],
			'category'     => (string) $entry['category'],
			'action'       => (string) $entry['action'],
			'object_type'  => (string) $entry['object_type'],
			'object_id'    => $entry['object_id'],
			'object_key'   => $entry['object_key'],
			'object_label' => (string) $entry['object_label'],
			'context'      => (string) $entry['context'],
			'ip'           => $entry['ip'],
			'user_agent'   => $entry['user_agent'],
			'details'      => empty( $details ) ? null : BLT_Activity_Log_Redactor::encode( $details ),
		);
	}

	/**
	 * Strip tags and decode entities from a label.
	 *
	 * Titles reach hooks entity-encoded; the log stores plain text and escapes
	 * at output.
	 *
	 * @param string $text Raw label.
	 * @return string
	 */
	private static function plain( $text ) {
		return trim( wp_specialchars_decode( wp_strip_all_tags( $text ), ENT_QUOTES ) );
	}

	/**
	 * Cut a string to a character count.
	 *
	 * @param string $text Text.
	 * @param int    $max  Character cap.
	 * @return string
	 */
	private static function cut( $text, $max ) {
		list( $cut ) = BLT_Activity_Log_Redactor::truncate( $text, $max );

		return $cut;
	}

	/**
	 * Report a failure once per request and carry on.
	 *
	 * A failed log write must never surface to the user or stop the save,
	 * update or sign-in that caused it.
	 *
	 * @param \Throwable|string $problem What went wrong.
	 * @return void
	 */
	public static function report( $problem ) {
		if ( self::$reported ) {
			return;
		}

		self::$reported = true;

		$message = $problem instanceof \Throwable ? $problem->getMessage() : (string) $problem;

		error_log( 'BLT Activity Log: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
