<?php
/**
 * Logger: buffer, merge, coalesce and write, against a fake $wpdb.
 *
 * @package Blt_Activity_Log
 */

use PHPUnit\Framework\TestCase;

/**
 * A stand-in for $wpdb that records what the Logger asks of it.
 */
class Blt_Fake_Wpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Id of the last inserted row.
	 *
	 * @var int
	 */
	public $insert_id = 0;

	/**
	 * Last error.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Every SQL string or call seen, in order.
	 *
	 * @var string[]
	 */
	public $log = array();

	/**
	 * Row returned by get_row().
	 *
	 * @var object|null
	 */
	public $row = null;

	/**
	 * Make writes fail with false.
	 *
	 * @var bool
	 */
	public $fail = false;

	/**
	 * Make writes throw.
	 *
	 * @var bool
	 */
	public $throw = false;

	/**
	 * Rows passed to insert().
	 *
	 * @var array[]
	 */
	public $inserted = array();

	/**
	 * Data passed to update().
	 *
	 * @var array[]
	 */
	public $updated = array();

	/**
	 * Whether errors are suppressed.
	 *
	 * @var bool
	 */
	public $suppressed = false;

	/**
	 * Suppress errors.
	 *
	 * @param bool $suppress Flag.
	 * @return bool Previous value.
	 */
	public function suppress_errors( $suppress = true ) {
		$old              = $this->suppressed;
		$this->suppressed = $suppress;

		return $old;
	}

	/**
	 * Minimal prepare(): substitutes %s and %d.
	 *
	 * @param string $sql  SQL.
	 * @param mixed  ...$args Values, or one array of them.
	 * @return string
	 */
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$i = 0;

		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( &$args, &$i ) {
				$value = $args[ $i++ ];

				return '%d' === $m[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$sql
		);
	}

	/**
	 * Run a query.
	 *
	 * @param string $sql SQL.
	 * @return int|false
	 */
	public function query( $sql ) {
		if ( $this->throw ) {
			throw new RuntimeException( 'database exploded' );
		}

		$this->log[] = $sql;

		if ( $this->fail ) {
			$this->last_error = 'Table does not exist';

			return false;
		}

		return 1;
	}

	/**
	 * Insert a row.
	 *
	 * @param string $table   Table.
	 * @param array  $data    Data.
	 * @param array  $formats Formats.
	 * @return int|false
	 */
	public function insert( $table, $data, $formats ) { // phpcs:ignore
		if ( $this->throw ) {
			throw new RuntimeException( 'database exploded' );
		}

		$this->log[] = 'INSERT ' . $table;

		if ( $this->fail ) {
			$this->last_error = 'Table does not exist';

			return false;
		}

		$this->inserted[] = $data;
		++$this->insert_id;

		return 1;
	}

	/**
	 * Fetch a row.
	 *
	 * @param string $sql SQL.
	 * @return object|null
	 */
	public function get_row( $sql ) {
		$this->log[] = $sql;

		return $this->row;
	}

	/**
	 * Update a row.
	 *
	 * @param string $table   Table.
	 * @param array  $data    Data.
	 * @param array  $where   Where.
	 * @param array  $formats Formats.
	 * @param array  $where_formats Where formats.
	 * @return int
	 */
	public function update( $table, $data, $where, $formats = array(), $where_formats = array() ) { // phpcs:ignore
		$this->log[]     = 'UPDATE ' . $table;
		$this->updated[] = array(
			'data'  => $data,
			'where' => $where,
		);

		return 1;
	}

	/**
	 * Delete a row.
	 *
	 * @param string $table   Table.
	 * @param array  $where   Where.
	 * @param array  $formats Formats.
	 * @return int
	 */
	public function delete( $table, $where, $formats = array() ) { // phpcs:ignore
		$this->log[] = 'DELETE ' . $table . ' ' . wp_json_encode( $where );

		return 1;
	}
}

/**
 * Tests for BLT_Activity_Log_Logger.
 */
class Test_Logger extends TestCase {

	/**
	 * The fake database.
	 *
	 * @var Blt_Fake_Wpdb
	 */
	private $db;

	/**
	 * Where error_log() lines go during a test.
	 *
	 * @var string
	 */
	private $error_log;

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
		BLT_Activity_Log_Redactor::reset();
		BLT_Activity_Log_Logger::reset();

		$this->db              = new Blt_Fake_Wpdb();
		$GLOBALS['wpdb']       = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->error_log       = tempnam( sys_get_temp_dir(), 'blt-log' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		ini_set( 'error_log', $this->error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $_SERVER['REMOTE_ADDR'] );

		if ( file_exists( $this->error_log ) ) {
			unlink( $this->error_log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * A post save entry.
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	private function entry( array $overrides = array() ) {
		return array_merge(
			array(
				'category'     => 'post',
				'action'       => 'updated',
				'object_type'  => 'page',
				'object_id'    => 12,
				'object_label' => 'About',
				'details'      => array(
					'changes' => array(
						array(
							'field'  => 'post_title',
							'before' => 'About',
							'after'  => 'About Us',
						),
					),
				),
			),
			$overrides
		);
	}

	/**
	 * The error_log lines written so far.
	 *
	 * @return string[]
	 */
	private function logged_errors() {
		$text = file_exists( $this->error_log ) ? (string) file_get_contents( $this->error_log ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return array_values( array_filter( explode( "\n", $text ) ) );
	}

	/**
	 * Entries are held until shutdown, and the flush is hooked once.
	 *
	 * @return void
	 */
	public function test_record_buffers_and_hooks_shutdown_once() {
		BLT_Activity_Log_Logger::record( $this->entry() );
		BLT_Activity_Log_Logger::record( $this->entry( array( 'object_id' => 13 ) ) );

		$this->assertCount( 2, BLT_Activity_Log_Logger::buffered() );
		$this->assertCount( 1, $GLOBALS['blt_test_actions']['shutdown'] );
		$this->assertSame( array(), $this->db->log, 'Nothing is written before shutdown.' );
	}

	/**
	 * An entry with no category or action is refused.
	 *
	 * @return void
	 */
	public function test_unusable_entries_are_not_buffered() {
		BLT_Activity_Log_Logger::record( array( 'action' => 'updated' ) );
		BLT_Activity_Log_Logger::record( array( 'category' => 'post' ) );
		BLT_Activity_Log_Logger::record( array() );

		$this->assertSame( array(), BLT_Activity_Log_Logger::buffered() );
	}

	/**
	 * Two entries about one object in one request become one entry.
	 *
	 * @return void
	 */
	public function test_entries_for_one_object_merge_within_a_request() {
		BLT_Activity_Log_Logger::record( $this->entry() );
		BLT_Activity_Log_Logger::record(
			$this->entry(
				array(
					'details' => array(
						'changes' => array(
							array(
								'field'  => 'post_excerpt',
								'before' => '',
								'after'  => 'x',
							),
						),
					),
				)
			)
		);

		$buffered = array_values( BLT_Activity_Log_Logger::buffered() );

		$this->assertCount( 1, $buffered );
		$this->assertSame( array( 'post_title', 'post_excerpt' ), array_column( $buffered[0]['details']['changes'], 'field' ) );
	}

	/**
	 * A save that also publishes is one entry with the more specific action.
	 *
	 * @return void
	 */
	public function test_a_folded_update_joins_a_status_change() {
		BLT_Activity_Log_Logger::record(
			$this->entry(
				array(
					'details' => array(
						'terms' => array(
							'category' => array(
								'added'   => array( 'News' ),
								'removed' => array(),
							),
						),
					),
					'absorb'  => BLT_Activity_Log_Logger::FOLD_ACTIONS,
				)
			)
		);

		BLT_Activity_Log_Logger::record(
			$this->entry(
				array(
					'action'  => 'published',
					'absorb'  => array( 'updated' ),
					'details' => array(
						'changes' => array(
							array(
								'field'  => 'post_status',
								'before' => 'draft',
								'after'  => 'publish',
							),
						),
					),
				)
			)
		);

		$buffered = array_values( BLT_Activity_Log_Logger::buffered() );

		$this->assertCount( 1, $buffered, 'Terms and the status change are one entry.' );
		$this->assertSame( 'published', $buffered[0]['action'] );
		$this->assertArrayHasKey( 'terms', $buffered[0]['details'] );
		$this->assertArrayHasKey( 'changes', $buffered[0]['details'] );
	}

	/**
	 * An entry that lists several actions folds every one of them in, not only
	 * the first it finds: a new account has profile-field changes and a role
	 * change waiting when it is announced.
	 *
	 * @return void
	 */
	public function test_an_entry_absorbs_every_listed_sibling() {
		BLT_Activity_Log_Logger::record(
			$this->entry(
				array(
					'action'  => 'updated',
					'details' => array( 'changes' => array( array( 'field' => 'first_name', 'before' => '', 'after' => 'Jane' ) ) ),
				)
			)
		);
		BLT_Activity_Log_Logger::record(
			$this->entry(
				array(
					'action'  => 'role_changed',
					'details' => array( 'changes' => array( array( 'field' => 'role', 'before' => '', 'after' => 'editor' ) ) ),
				)
			)
		);
		BLT_Activity_Log_Logger::record(
			$this->entry(
				array(
					'action'  => 'created',
					'absorb'  => array( 'updated', 'role_changed' ),
					'details' => array( 'changes' => array( array( 'field' => 'user_email', 'before' => '', 'after' => 'j@example.com' ) ) ),
				)
			)
		);

		$buffered = array_values( BLT_Activity_Log_Logger::buffered() );

		$this->assertCount( 1, $buffered );
		$this->assertSame( 'created', $buffered[0]['action'] );

		$fields = array_column( $buffered[0]['details']['changes'], 'field' );

		sort( $fields );

		$this->assertSame( array( 'first_name', 'role', 'user_email' ), $fields );
	}

	/**
	 * A meta hook that fires after the save folds into it just the same.
	 *
	 * @return void
	 */
	public function test_a_late_fold_joins_the_created_entry() {
		BLT_Activity_Log_Logger::record( $this->entry( array( 'action' => 'created', 'details' => array() ) ) );
		BLT_Activity_Log_Logger::record( $this->entry( array( 'absorb' => BLT_Activity_Log_Logger::FOLD_ACTIONS ) ) );

		$buffered = array_values( BLT_Activity_Log_Logger::buffered() );

		$this->assertCount( 1, $buffered );
		$this->assertSame( 'created', $buffered[0]['action'] );
		$this->assertArrayHasKey( 'changes', $buffered[0]['details'] );
	}

	/**
	 * Different actions on one object stay separate entries.
	 *
	 * @return void
	 */
	public function test_different_actions_do_not_merge() {
		BLT_Activity_Log_Logger::record( $this->entry( array( 'action' => 'trashed' ) ) );
		BLT_Activity_Log_Logger::record( $this->entry( array( 'action' => 'deleted' ) ) );

		$this->assertCount( 2, BLT_Activity_Log_Logger::buffered() );
	}

	/**
	 * Entries that name no object are never merged.
	 *
	 * @return void
	 */
	public function test_entries_without_an_object_are_not_merged() {
		$anonymous = array(
			'category' => 'external',
			'action'   => 'something_happened',
		);

		BLT_Activity_Log_Logger::record( $anonymous );
		BLT_Activity_Log_Logger::record( $anonymous );

		$this->assertCount( 2, BLT_Activity_Log_Logger::buffered() );
	}

	/**
	 * Several entries go in as one multi-row INSERT.
	 *
	 * @return void
	 */
	public function test_flush_writes_one_multi_row_insert() {
		BLT_Activity_Log_Logger::record( $this->entry() );
		BLT_Activity_Log_Logger::record( $this->entry( array( 'object_id' => 13, 'object_label' => "O'Reilly" ) ) );
		BLT_Activity_Log_Logger::record( $this->entry( array( 'object_id' => 14 ) ) );

		BLT_Activity_Log_Logger::flush();

		$inserts = array_values(
			array_filter(
				$this->db->log,
				function ( $sql ) {
					return 0 === strpos( $sql, 'INSERT INTO' );
				}
			)
		);

		$this->assertCount( 1, $inserts, 'One statement for three entries.' );
		$this->assertSame( 3, substr_count( $inserts[0], '), (' ) + 1 );
		$this->assertStringContainsString( 'INSERT INTO wp_blt_activity_log_entries (created_at, last_at, occurrences,', $inserts[0] );
		$this->assertStringContainsString( "O\\'Reilly", $inserts[0], 'Values are escaped.' );
		$this->assertSame( array(), BLT_Activity_Log_Logger::buffered(), 'The buffer is emptied.' );
	}

	/**
	 * Absent values are written as NULL, not as empty strings.
	 *
	 * @return void
	 */
	public function test_null_columns_are_written_as_null() {
		$_SERVER['REMOTE_ADDR'] = '';

		BLT_Activity_Log_Logger::record( $this->entry( array( 'details' => array( 'summary' => 'x' ) ) ) );
		BLT_Activity_Log_Logger::record( $this->entry( array( 'object_id' => 13, 'details' => array( 'summary' => 'y' ) ) ) );
		BLT_Activity_Log_Logger::flush();

		$insert = end( $this->db->log );

		$this->assertStringContainsString( ', NULL, ', $insert, 'object_key, ip and user_agent have no value.' );
		$this->assertStringNotContainsString( "'NULL'", $insert, 'NULL is the keyword, not the text.' );
	}

	/**
	 * A single entry is inserted on its own, so its id is known.
	 *
	 * @return void
	 */
	public function test_single_entry_uses_a_row_insert_and_returns_its_id() {
		$id = BLT_Activity_Log_Logger::write_now( $this->entry( array( 'action' => 'created' ) ) );

		$this->assertSame( 1, $id );
		$this->assertCount( 1, $this->db->inserted );
		$this->assertSame( 'post', $this->db->inserted[0]['category'] );
		$this->assertSame( 12, $this->db->inserted[0]['object_id'] );
		$this->assertNull( $this->db->inserted[0]['object_key'] );
		$this->assertSame( 1, $this->db->inserted[0]['occurrences'] );
	}

	/**
	 * Details are stored as JSON, and an entry without any stores NULL.
	 *
	 * @return void
	 */
	public function test_details_are_json_or_null() {
		BLT_Activity_Log_Logger::write_now( $this->entry() );
		BLT_Activity_Log_Logger::write_now( $this->entry( array( 'action' => 'trashed', 'details' => array() ) ) );

		$this->assertJson( $this->db->inserted[0]['details'] );
		$this->assertSame( 'About Us', json_decode( $this->db->inserted[0]['details'], true )['changes'][0]['after'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_decode
		$this->assertNull( $this->db->inserted[1]['details'] );
	}

	/**
	 * A save that changed nothing writes nothing.
	 *
	 * @return void
	 */
	public function test_an_empty_update_is_not_written() {
		BLT_Activity_Log_Logger::record( $this->entry( array( 'details' => array() ) ) );
		BLT_Activity_Log_Logger::record( $this->entry( array( 'object_id' => 13, 'details' => array( 'changes' => array() ) ) ) );
		BLT_Activity_Log_Logger::flush();

		$this->assertSame( array(), $this->db->log );
	}

	/**
	 * A status change with no field diff is still an entry.
	 *
	 * @return void
	 */
	public function test_a_status_change_without_a_diff_is_written() {
		BLT_Activity_Log_Logger::record( $this->entry( array( 'action' => 'trashed', 'details' => array() ) ) );
		BLT_Activity_Log_Logger::flush();

		$this->assertCount( 1, $this->db->inserted );
	}

	/**
	 * Sensitive fields are redacted before anything is stored.
	 *
	 * @return void
	 */
	public function test_secrets_never_reach_the_insert() {
		BLT_Activity_Log_Logger::write_now(
			array(
				'category'     => 'setting',
				'action'       => 'updated',
				'object_type'  => 'option',
				'object_key'   => 'mailserver_pass',
				'object_label' => 'mailserver_pass',
				'details'      => array(
					'changes' => array(
						array(
							'field'  => 'mailserver_pass',
							'before' => 'hunter2',
							'after'  => 'hunter3',
						),
					),
				),
			)
		);

		$stored = $this->db->inserted[0]['details'];

		$this->assertStringNotContainsString( 'hunter', $stored );
		$this->assertStringContainsString( '"redacted":true', $stored );
	}

	/**
	 * Redaction runs after the filters, so a filter cannot smuggle a secret in.
	 *
	 * @return void
	 */
	public function test_filters_cannot_bypass_redaction() {
		add_filter(
			'blt_activity_log_entry',
			function ( $entry ) {
				$entry['details']['changes'][] = array(
					'field'  => 'api_key',
					'before' => 'old-secret',
					'after'  => 'new-secret',
				);

				return $entry;
			}
		);

		BLT_Activity_Log_Logger::write_now( $this->entry() );

		$stored = $this->db->inserted[0]['details'];

		$this->assertStringNotContainsString( 'secret', $stored );
		$this->assertStringContainsString( 'api_key', $stored );
	}

	/**
	 * Long values are cut and say how long they were.
	 *
	 * @return void
	 */
	public function test_long_values_are_truncated() {
		BLT_Activity_Log_Logger::write_now(
			$this->entry(
				array(
					'details' => array(
						'changes' => array(
							array(
								'field'  => 'post_excerpt',
								'before' => '',
								'after'  => str_repeat( 'x', 4210 ),
							),
						),
					),
				)
			)
		);

		$change = json_decode( $this->db->inserted[0]['details'], true )['changes'][0]; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_decode

		$this->assertSame( 1000, strlen( $change['after'] ) );
		$this->assertSame( array( 'after' => 4210 ), $change['truncated'] );
	}

	/**
	 * The veto filter keeps an entry out.
	 *
	 * @return void
	 */
	public function test_should_log_filter_can_veto() {
		add_filter( 'blt_activity_log_should_log', '__return_false' );

		BLT_Activity_Log_Logger::record( $this->entry() );
		BLT_Activity_Log_Logger::flush();

		$this->assertSame( array(), $this->db->log );
	}

	/**
	 * Labels are stored as plain text, tags stripped and entities decoded.
	 *
	 * @return void
	 */
	public function test_labels_are_stored_as_plain_text() {
		BLT_Activity_Log_Logger::write_now( $this->entry( array( 'object_label' => '<b>Fish</b> &amp; Chips &#8211; &quot;Menu&quot;' ) ) );

		$this->assertSame( 'Fish & Chips &#8211; "Menu"', $this->db->inserted[0]['object_label'] );
	}

	/**
	 * A database that refuses the write does not break the request.
	 *
	 * @return void
	 */
	public function test_a_failed_insert_is_reported_once_and_swallowed() {
		$this->db->fail = true;

		BLT_Activity_Log_Logger::record( $this->entry() );
		BLT_Activity_Log_Logger::record( $this->entry( array( 'object_id' => 13 ) ) );
		BLT_Activity_Log_Logger::flush();

		BLT_Activity_Log_Logger::record( $this->entry( array( 'object_id' => 14 ) ) );
		BLT_Activity_Log_Logger::flush();

		$errors = $this->logged_errors();

		$this->assertCount( 1, $errors, 'One error_log line per request, however many writes fail.' );
		$this->assertStringContainsString( 'BLT Activity Log', $errors[0] );
		$this->assertStringContainsString( 'Table does not exist', $errors[0] );
	}

	/**
	 * Even an exception from the database layer is swallowed.
	 *
	 * @return void
	 */
	public function test_an_exception_during_the_write_is_swallowed() {
		$this->db->throw = true;

		BLT_Activity_Log_Logger::record( $this->entry() );
		BLT_Activity_Log_Logger::record( $this->entry( array( 'object_id' => 13 ) ) );

		BLT_Activity_Log_Logger::flush();

		$this->assertSame( array(), BLT_Activity_Log_Logger::buffered() );
		$this->assertStringContainsString( 'database exploded', implode( "\n", $this->logged_errors() ) );
		$this->assertSame( 0, BLT_Activity_Log_Logger::write_now( $this->entry() ), 'write_now() returns 0, not an exception.' );
	}

	/**
	 * A throwing filter cannot break the request either.
	 *
	 * @return void
	 */
	public function test_a_throwing_filter_is_swallowed() {
		add_filter(
			'blt_activity_log_entry',
			function () {
				throw new RuntimeException( 'bad filter' );
			}
		);

		BLT_Activity_Log_Logger::record( $this->entry() );
		BLT_Activity_Log_Logger::flush();

		$this->assertStringContainsString( 'bad filter', implode( "\n", $this->logged_errors() ) );
	}

	/**
	 * Database errors are suppressed while the Logger writes, then restored.
	 *
	 * @return void
	 */
	public function test_wpdb_errors_are_suppressed_during_the_write_and_restored() {
		BLT_Activity_Log_Logger::record( $this->entry() );
		BLT_Activity_Log_Logger::flush();

		$this->assertFalse( $this->db->suppressed, 'The previous setting is put back.' );
	}

	/**
	 * Every row records who and where.
	 *
	 * @return void
	 */
	public function test_context_is_snapshotted() {
		BLT_Activity_Log_Logger::write_now( $this->entry() );

		$row = $this->db->inserted[0];

		$this->assertSame( 0, $row['user_id'] );
		$this->assertSame( 'front', $row['context'] );
		$this->assertSame( '203.0.113.5', $row['ip'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['created_at'] );
		$this->assertSame( $row['created_at'], $row['last_at'] );
	}

	/**
	 * An explicit actor (a sign-in, whose current user is not set yet) wins.
	 *
	 * @return void
	 */
	public function test_actor_can_be_given_explicitly() {
		BLT_Activity_Log_Logger::write_now(
			$this->entry(
				array(
					'user_id'    => 7,
					'user_login' => 'shane',
					'user_role'  => 'administrator',
				)
			)
		);

		$row = $this->db->inserted[0];

		$this->assertSame( 7, $row['user_id'] );
		$this->assertSame( 'shane', $row['user_login'] );
		$this->assertSame( 'administrator', $row['user_role'] );
	}

	/**
	 * The IP and browser settings are honored.
	 *
	 * @return void
	 */
	public function test_ip_and_user_agent_settings() {
		$GLOBALS['blt_test_options']['blt_activity_log_settings'] = array(
			'ip_mode'          => 'off',
			'store_user_agent' => false,
		);
		$_SERVER['HTTP_USER_AGENT']                               = 'TestBrowser/1.0';

		BLT_Activity_Log_Logger::write_now( $this->entry() );

		$this->assertNull( $this->db->inserted[0]['ip'] );
		$this->assertNull( $this->db->inserted[0]['user_agent'] );

		$GLOBALS['blt_test_options']['blt_activity_log_settings'] = array(
			'ip_mode'          => 'full',
			'store_user_agent' => true,
		);

		BLT_Activity_Log_Logger::write_now( $this->entry( array( 'object_id' => 99 ) ) );

		$this->assertSame( '203.0.113.5', $this->db->inserted[1]['ip'] );
		$this->assertSame( 'TestBrowser/1.0', $this->db->inserted[1]['user_agent'] );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	/**
	 * A spoofed Cloudflare header is ignored from a non-Cloudflare address.
	 *
	 * @return void
	 */
	public function test_spoofed_cloudflare_header_is_not_recorded() {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';

		BLT_Activity_Log_Logger::write_now( $this->entry() );

		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );

		$this->assertSame( '203.0.113.5', $this->db->inserted[0]['ip'] );
	}

	/**
	 * When a listener wants each id, rows go in one by one and it is told.
	 *
	 * @return void
	 */
	public function test_logged_action_fires_with_each_id() {
		$seen = array();

		add_action(
			'blt_activity_log_logged',
			function ( $entry, $id ) use ( &$seen ) {
				$seen[] = array( $entry['object_id'], $id );
			}
		);

		BLT_Activity_Log_Logger::record( $this->entry() );
		BLT_Activity_Log_Logger::record( $this->entry( array( 'object_id' => 13 ) ) );
		BLT_Activity_Log_Logger::flush();

		$this->assertSame( array( array( 12, 1 ), array( 13, 2 ) ), $seen );
		$this->assertCount( 2, $this->db->inserted );
	}

	/**
	 * A long run is flushed early so memory stays bounded.
	 *
	 * @return void
	 */
	public function test_buffer_flushes_when_full() {
		for ( $i = 1; $i <= BLT_Activity_Log_Logger::MAX_BUFFER; $i++ ) {
			BLT_Activity_Log_Logger::record( $this->entry( array( 'object_id' => $i ) ) );
		}

		$this->assertSame( array(), BLT_Activity_Log_Logger::buffered(), 'The buffer filled and was written.' );
		$this->assertNotEmpty( $this->db->log );
	}

	/**
	 * Large writes are split into several statements.
	 *
	 * @return void
	 */
	public function test_large_writes_are_chunked() {
		for ( $i = 1; $i <= 250; $i++ ) {
			BLT_Activity_Log_Logger::record( $this->entry( array( 'object_id' => $i ) ) );
		}

		BLT_Activity_Log_Logger::flush();

		$inserts = array_filter(
			$this->db->log,
			function ( $sql ) {
				return 0 === strpos( $sql, 'INSERT INTO' );
			}
		);

		$this->assertCount( 3, $inserts, '250 rows at 100 per statement.' );
	}

	/**
	 * A repeated save within the window is merged into the stored row.
	 *
	 * @return void
	 */
	public function test_repeated_update_is_coalesced_into_a_stored_created_entry() {
		$this->db->row = (object) array(
			'id'          => 41,
			'action'      => 'created',
			'occurrences' => 1,
			'details'     => '{"changes":[{"field":"post_status","before":"","after":"draft"}]}',
		);

		BLT_Activity_Log_Logger::record( $this->entry() );
		BLT_Activity_Log_Logger::flush();

		$this->assertSame( array(), $this->db->inserted, 'No new row.' );
		$this->assertCount( 1, $this->db->updated );
		$this->assertSame( array( 'id' => 41 ), $this->db->updated[0]['where'] );
		$this->assertSame( 2, $this->db->updated[0]['data']['occurrences'] );

		$merged = json_decode( $this->db->updated[0]['data']['details'], true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_decode

		$this->assertSame( array( 'post_status', 'post_title' ), array_column( $merged['changes'], 'field' ) );
		$this->assertStringContainsString( 'user_id = 0', $this->db->log[0], 'Same user only.' );
		$this->assertStringContainsString( "object_type = 'page'", $this->db->log[0] );
		$this->assertStringContainsString( 'object_id = 12', $this->db->log[0] );
	}

	/**
	 * An update is never merged across a publish.
	 *
	 * @return void
	 */
	public function test_update_is_not_coalesced_into_a_published_entry() {
		$this->db->row = (object) array(
			'id'          => 41,
			'action'      => 'published',
			'occurrences' => 1,
			'details'     => '{}',
		);

		BLT_Activity_Log_Logger::record( $this->entry() );
		BLT_Activity_Log_Logger::flush();

		$this->assertSame( array(), $this->db->updated );
		$this->assertCount( 1, $this->db->inserted );
	}

	/**
	 * A save that completely reverts the stored update removes it.
	 *
	 * @return void
	 */
	public function test_a_complete_revert_deletes_the_stored_update() {
		$this->db->row = (object) array(
			'id'          => 41,
			'action'      => 'updated',
			'occurrences' => 1,
			'details'     => '{"changes":[{"field":"post_title","before":"About Us","after":"About"}]}',
		);

		BLT_Activity_Log_Logger::record( $this->entry() );
		BLT_Activity_Log_Logger::flush();

		$this->assertSame( array(), $this->db->inserted );
		$this->assertSame( array(), $this->db->updated );
		$this->assertStringContainsString( 'DELETE', implode( "\n", $this->db->log ) );
	}

	/**
	 * With no recent stored row the update is inserted as usual.
	 *
	 * @return void
	 */
	public function test_no_stored_row_means_a_normal_insert() {
		$this->db->row = null;

		BLT_Activity_Log_Logger::record( $this->entry() );
		BLT_Activity_Log_Logger::flush();

		$this->assertCount( 1, $this->db->inserted );
	}

	/**
	 * The window can be turned off.
	 *
	 * @return void
	 */
	public function test_coalescing_can_be_disabled() {
		add_filter(
			'blt_activity_log_coalesce_window',
			function () {
				return 0;
			}
		);

		$this->db->row = (object) array(
			'id'          => 41,
			'action'      => 'updated',
			'occurrences' => 1,
			'details'     => '{"changes":[{"field":"post_title","before":"x","after":"y"}]}',
		);

		BLT_Activity_Log_Logger::record( $this->entry() );
		BLT_Activity_Log_Logger::flush();

		$this->assertCount( 1, $this->db->inserted );

		$selects = array_filter(
			$this->db->log,
			function ( $sql ) {
				return 0 === strpos( $sql, 'SELECT' );
			}
		);

		$this->assertCount( 0, $selects, 'No lookup was made.' );
	}

	/**
	 * Only `updated` entries are looked up for coalescing.
	 *
	 * @return void
	 */
	public function test_only_updates_are_coalesced() {
		$this->db->row = (object) array(
			'id'          => 41,
			'action'      => 'updated',
			'occurrences' => 1,
			'details'     => '{}',
		);

		BLT_Activity_Log_Logger::record( $this->entry( array( 'action' => 'trashed' ) ) );
		BLT_Activity_Log_Logger::flush();

		$selects = array_filter(
			$this->db->log,
			function ( $sql ) {
				return 0 === strpos( $sql, 'SELECT' );
			}
		);

		$this->assertCount( 0, $selects );
		$this->assertCount( 1, $this->db->inserted );
	}
}
