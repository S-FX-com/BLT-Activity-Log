<?php
/**
 * Redactor: sensitive-key vectors, always-redacted keys, truncation, caps.
 *
 * @package Blt_Activity_Log
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Activity_Log_Redactor.
 */
class Test_Redactor extends TestCase {

	/**
	 * Reset shimmed state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
		BLT_Activity_Log_Redactor::reset();
	}

	/**
	 * Keys the default pattern must catch.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function sensitive_keys() {
		return array(
			'mailserver_pass'                       => array( 'mailserver_pass' ),
			'user_pass'                             => array( 'user_pass' ),
			'api_key'                               => array( 'api_key' ),
			'stripe_secret_key'                     => array( 'stripe_secret_key' ),
			'plugin settings key path'              => array( 'blt_secure_settings[cloudflare_token]' ),
			'nonce_salt'                            => array( 'nonce_salt' ),
			'upper case'                            => array( 'API_KEY' ),
			'dashed'                                => array( 'private-key' ),
			'nested path, second level'             => array( 'option[group][client_secret]' ),
			'always list inside a path'             => array( 'meta[session_tokens]' ),
		);
	}

	/**
	 * Keys the default pattern must leave alone.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function ordinary_keys() {
		return array(
			'keyboard_shortcuts' => array( 'keyboard_shortcuts' ),
			'bypass_cache'       => array( 'bypass_cache' ),
			'passage'            => array( 'passage' ),
			'posts_per_page'     => array( 'posts_per_page' ),
			'tokenizer'          => array( 'tokenizer' ),
			'blogname'           => array( 'blogname' ),
			'empty'              => array( '' ),
		);
	}

	/**
	 * @dataProvider sensitive_keys
	 *
	 * @param string $key Key.
	 * @return void
	 */
	public function test_sensitive_keys_are_caught( $key ) {
		$this->assertTrue( BLT_Activity_Log_Redactor::is_sensitive( $key ), $key );
	}

	/**
	 * @dataProvider ordinary_keys
	 *
	 * @param string $key Key.
	 * @return void
	 */
	public function test_ordinary_keys_are_left_alone( $key ) {
		$this->assertFalse( BLT_Activity_Log_Redactor::is_sensitive( $key ), $key );
	}

	/**
	 * The always-redacted keys do not depend on the pattern.
	 *
	 * @return void
	 */
	public function test_always_redacted_keys_ignore_the_pattern() {
		add_filter(
			'blt_activity_log_redact_pattern',
			function () {
				return '/^never-matches$/';
			}
		);

		foreach ( array( 'user_pass', 'user_activation_key', 'session_tokens', 'post_password', 'application_passwords', '_application_passwords' ) as $key ) {
			$this->assertTrue( BLT_Activity_Log_Redactor::is_sensitive( $key ), $key );
		}

		$this->assertFalse( BLT_Activity_Log_Redactor::is_sensitive( 'mailserver_pass' ), 'The pattern really was replaced.' );
	}

	/**
	 * A broken pattern from a filter falls back to the default.
	 *
	 * @return void
	 */
	public function test_invalid_filtered_pattern_falls_back() {
		add_filter(
			'blt_activity_log_redact_pattern',
			function () {
				return '/(unclosed';
			}
		);

		$this->assertSame( BLT_Activity_Log_Redactor::DEFAULT_PATTERN, BLT_Activity_Log_Redactor::pattern() );
		$this->assertTrue( BLT_Activity_Log_Redactor::is_sensitive( 'api_key' ) );
	}

	/**
	 * Masking inside a structure works at every depth.
	 *
	 * @return void
	 */
	public function test_scrub_masks_nested_sensitive_keys() {
		$scrubbed = BLT_Activity_Log_Redactor::scrub(
			array(
				'name'   => 'Acme',
				'api_key' => 'abc',
				'nested' => array(
					'client_secret' => 'xyz',
					'color'         => 'red',
				),
			)
		);

		$this->assertSame( 'Acme', $scrubbed['name'] );
		$this->assertSame( BLT_Activity_Log_Redactor::MASK, $scrubbed['api_key'] );
		$this->assertSame( BLT_Activity_Log_Redactor::MASK, $scrubbed['nested']['client_secret'] );
		$this->assertSame( 'red', $scrubbed['nested']['color'] );
	}

	/**
	 * Truncation records the original length and only cuts what is too long.
	 *
	 * @return void
	 */
	public function test_truncate_records_original_length() {
		list( $short, $short_original ) = BLT_Activity_Log_Redactor::truncate( 'hello', 10 );

		$this->assertSame( 'hello', $short );
		$this->assertNull( $short_original );

		list( $cut, $original ) = BLT_Activity_Log_Redactor::truncate( str_repeat( 'a', 4210 ), 1000 );

		$this->assertSame( 1000, strlen( $cut ) );
		$this->assertSame( 4210, $original );
	}

	/**
	 * Truncation counts characters, not bytes, and never splits one.
	 *
	 * @return void
	 */
	public function test_truncate_is_multibyte_safe() {
		list( $cut, $original ) = BLT_Activity_Log_Redactor::truncate( str_repeat( 'é', 50 ), 10 );

		$this->assertSame( str_repeat( 'é', 10 ), $cut );
		$this->assertSame( 50, $original );
		$this->assertSame( 1, preg_match( '//u', $cut ), 'Still valid UTF-8.' );
	}

	/**
	 * Invalid UTF-8 is repaired so the entry can be JSON-encoded.
	 *
	 * @return void
	 */
	public function test_invalid_utf8_is_made_encodable() {
		$clean = BLT_Activity_Log_Redactor::utf8( "caf\xE9" );

		$this->assertSame( 1, preg_match( '//u', $clean ) );
		$this->assertNotFalse( json_encode( $clean ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * A sensitive change keeps the field and a note, never the values.
	 *
	 * @return void
	 */
	public function test_sanitize_changes_redacts_by_field() {
		$changes = BLT_Activity_Log_Redactor::sanitize_changes(
			array(
				array(
					'field'  => 'mailserver_pass',
					'before' => 'hunter2',
					'after'  => 'hunter3',
				),
				array(
					'field'    => 'post_password',
					'redacted' => true,
					'note'     => 'set',
				),
				array(
					'field'  => 'blogname',
					'before' => 'Old',
					'after'  => 'New',
				),
			),
			1000
		);

		$this->assertSame(
			array(
				'field'    => 'mailserver_pass',
				'redacted' => true,
			),
			$changes[0]
		);
		$this->assertSame( 'set', $changes[1]['note'] );
		$this->assertArrayNotHasKey( 'before', $changes[1] );
		$this->assertSame( 'New', $changes[2]['after'] );
		$this->assertStringNotContainsString( 'hunter', json_encode( $changes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * A long value is shortened and the original length kept, per side.
	 *
	 * @return void
	 */
	public function test_sanitize_changes_truncates_per_side() {
		$changes = BLT_Activity_Log_Redactor::sanitize_changes(
			array(
				array(
					'field'  => 'post_excerpt',
					'before' => 'short',
					'after'  => str_repeat( 'x', 4210 ),
				),
			),
			1000
		);

		$this->assertSame( 'short', $changes[0]['before'] );
		$this->assertSame( 1000, strlen( $changes[0]['after'] ) );
		$this->assertSame( array( 'after' => 4210 ), $changes[0]['truncated'] );
	}

	/**
	 * More than the change cap is cut, and the entry says so.
	 *
	 * @return void
	 */
	public function test_cap_details_limits_change_count() {
		$changes = array();

		for ( $i = 0; $i < 250; $i++ ) {
			$changes[] = array(
				'field'  => 'f' . $i,
				'before' => 'a',
				'after'  => 'b',
			);
		}

		$capped = BLT_Activity_Log_Redactor::cap_details( array( 'changes' => $changes ) );

		$this->assertCount( BLT_Activity_Log_Redactor::MAX_CHANGES, $capped['changes'] );
		$this->assertSame( 50, $capped['changes_omitted'] );
	}

	/**
	 * An oversize structure is brought under the byte cap.
	 *
	 * @return void
	 */
	public function test_cap_details_enforces_byte_cap() {
		$changes = array();

		for ( $i = 0; $i < 100; $i++ ) {
			$changes[] = array(
				'field'  => 'field_' . $i,
				'before' => str_repeat( 'b', 900 ),
				'after'  => str_repeat( 'a', 900 ),
			);
		}

		$capped = BLT_Activity_Log_Redactor::cap_details( array( 'changes' => $changes ), 20000 );

		$this->assertLessThanOrEqual( 20000, BLT_Activity_Log_Redactor::encoded_size( $capped ) );
		$this->assertArrayHasKey( 'changes_omitted', $capped );
	}

	/**
	 * Details already within the cap pass through untouched.
	 *
	 * @return void
	 */
	public function test_cap_details_leaves_small_details_alone() {
		$details = array(
			'changes' => array(
				array(
					'field'  => 'blogname',
					'before' => 'a',
					'after'  => 'b',
				),
			),
		);

		$this->assertSame( $details, BLT_Activity_Log_Redactor::cap_details( $details ) );
	}

	/**
	 * Details built by another plugin may have any shape; the wrong ones are
	 * dropped rather than thrown on.
	 *
	 * @return void
	 */
	public function test_sanitize_details_drops_malformed_parts() {
		$clean = BLT_Activity_Log_Redactor::sanitize_details(
			array(
				'changes' => 'not-an-array',
				'summary' => array( 'nope' ),
				'note'    => 'kept',
			)
		);

		$this->assertSame( array( 'note' => 'kept' ), $clean );

		$clean = BLT_Activity_Log_Redactor::sanitize_details(
			array(
				'changes' => array(),
				'summary' => 42,
			)
		);

		$this->assertSame( array( 'summary' => '42' ), $clean );
	}
}
