<?php
/**
 * Settings: defaults, merging, and hand-sanitized saving.
 *
 * @package Blt_Activity_Log
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Activity_Log_Settings.
 */
class Test_Settings extends TestCase {

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
		$GLOBALS['blt_test_autoload'] = array();
	}

	/**
	 * The defaults from the spec.
	 *
	 * @return void
	 */
	public function test_defaults() {
		$defaults = BLT_Activity_Log_Settings::defaults();

		$this->assertSame( 'core', $defaults['settings_scope'] );
		$this->assertSame( array(), $defaults['excluded_post_types'] );
		$this->assertSame( 90, $defaults['retention_days'] );
		$this->assertSame( 'full', $defaults['ip_mode'] );
		$this->assertTrue( $defaults['store_user_agent'] );
		$this->assertFalse( $defaults['delete_data_on_uninstall'] );
	}

	/**
	 * Every module is on except failed sign-ins.
	 *
	 * @return void
	 */
	public function test_every_module_is_on_except_failed_signins() {
		$modules = BLT_Activity_Log_Settings::defaults()['modules'];

		$this->assertFalse( $modules['session_failed'] );
		unset( $modules['session_failed'] );

		foreach ( $modules as $id => $on ) {
			$this->assertTrue( $on, $id );
		}

		$this->assertSame( array( 'post', 'media', 'term', 'appearance', 'plugin', 'theme', 'core', 'setting', 'user', 'session' ), array_keys( $modules ) );
	}

	/**
	 * Stored values win over defaults, and the module map merges key by key.
	 *
	 * @return void
	 */
	public function test_stored_values_merge_over_defaults() {
		$GLOBALS['blt_test_options']['blt_activity_log_settings'] = array(
			'retention_days' => 30,
			'modules'        => array( 'media' => false ),
		);

		$all = BLT_Activity_Log_Settings::all();

		$this->assertSame( 30, $all['retention_days'] );
		$this->assertSame( 'full', $all['ip_mode'], 'Untouched keys keep their default.' );
		$this->assertFalse( $all['modules']['media'] );
		$this->assertTrue( $all['modules']['post'], 'A module missing from the stored map keeps its default.' );
	}

	/**
	 * Junk in the option cannot break reading it.
	 *
	 * @return void
	 */
	public function test_a_corrupt_option_falls_back_to_defaults() {
		$GLOBALS['blt_test_options']['blt_activity_log_settings'] = 'garbage';

		$this->assertSame( BLT_Activity_Log_Settings::defaults(), BLT_Activity_Log_Settings::all() );
	}

	/**
	 * A module can be asked about, with a fallback for unknown ones.
	 *
	 * @return void
	 */
	public function test_module_enabled() {
		$GLOBALS['blt_test_options']['blt_activity_log_settings'] = array( 'modules' => array( 'user' => false ) );

		$this->assertFalse( BLT_Activity_Log_Settings::module_enabled( 'user' ) );
		$this->assertTrue( BLT_Activity_Log_Settings::module_enabled( 'post' ) );
		$this->assertTrue( BLT_Activity_Log_Settings::module_enabled( 'from_a_filter', true ) );
		$this->assertFalse( BLT_Activity_Log_Settings::module_enabled( 'from_a_filter', false ) );
		$this->assertFalse( BLT_Activity_Log_Settings::module_enabled( 'session_failed' ) );
	}

	/**
	 * Saving is autoloaded (it is read on nearly every request).
	 *
	 * @return void
	 */
	public function test_settings_are_autoloaded_and_state_is_not() {
		BLT_Activity_Log_Settings::save( array( 'ip_mode' => 'off' ) );

		$this->assertTrue( $GLOBALS['blt_test_autoload']['blt_activity_log_settings'] );

		BLT_Activity_Log_Settings::update_state( array( 'last_prune_at' => 5 ) );

		$this->assertFalse( $GLOBALS['blt_test_autoload']['blt_activity_log_state'], 'State changes at its own cadence, so it is not autoloaded.' );
		$this->assertSame( 5, BLT_Activity_Log_Settings::state()['last_prune_at'] );
	}

	/**
	 * A form posts a marker, so every unticked module is read as off.
	 *
	 * @return void
	 */
	public function test_unticked_modules_are_saved_as_off() {
		$saved = BLT_Activity_Log_Settings::save(
			array(
				'_modules' => '1',
				'modules'  => array(
					'post' => '1',
					'user' => '1',
				),
			)
		);

		$this->assertTrue( $saved['modules']['post'] );
		$this->assertTrue( $saved['modules']['user'] );
		$this->assertFalse( $saved['modules']['media'] );
		$this->assertFalse( $saved['modules']['session_failed'] );
	}

	/**
	 * With every toggle off, the marker alone still saves them all as off.
	 *
	 * @return void
	 */
	public function test_all_modules_off_is_possible() {
		$saved = BLT_Activity_Log_Settings::save( array( '_modules' => '1' ) );

		foreach ( $saved['modules'] as $id => $on ) {
			$this->assertFalse( $on, $id );
		}
	}

	/**
	 * A save that does not mention a field leaves it alone.
	 *
	 * @return void
	 */
	public function test_partial_save_keeps_other_fields() {
		BLT_Activity_Log_Settings::save(
			array(
				'retention_days' => 30,
				'ip_mode'        => 'anonymized',
			)
		);

		$saved = BLT_Activity_Log_Settings::save( array( 'retention_days' => 60 ) );

		$this->assertSame( 60, $saved['retention_days'] );
		$this->assertSame( 'anonymized', $saved['ip_mode'] );
		$this->assertTrue( $saved['modules']['post'] );
	}

	/**
	 * Retention is clamped to a sensible range.
	 *
	 * @return void
	 */
	public function test_retention_is_clamped() {
		$this->assertSame( 365, BLT_Activity_Log_Settings::save( array( 'retention_days' => '99999' ) )['retention_days'], 'Capped at a year.' );
		$this->assertSame( 365, BLT_Activity_Log_Settings::save( array( 'retention_days' => '366' ) )['retention_days'] );
		$this->assertSame( 365, BLT_Activity_Log_Settings::save( array( 'retention_days' => '365' ) )['retention_days'] );
		$this->assertSame( 1, BLT_Activity_Log_Settings::save( array( 'retention_days' => '1' ) )['retention_days'] );
		$this->assertSame( 365, BLT_Activity_Log_Settings::save( array( 'retention_days' => '365.9' ) )['retention_days'], 'A decimal is cut to whole days.' );
	}

	/**
	 * There is no "keep forever", and a value that means nothing never shortens the log.
	 *
	 * @return void
	 */
	public function test_zero_and_text_are_refused() {
		BLT_Activity_Log_Settings::save( array( 'retention_days' => 120 ) );

		foreach ( array( '0', 0, 'abc', '', ' ', '-5', -1, '-0.5', '30days', array( 30 ), null ) as $junk ) {
			$this->assertSame( 120, BLT_Activity_Log_Settings::save( array( 'retention_days' => $junk ) )['retention_days'], var_export( $junk, true ) . ' leaves the value alone.' );
		}

		$this->assertSame( 120, BLT_Activity_Log_Settings::retention_days() );
	}

	/**
	 * Stored or configured garbage reads as the default, never as zero.
	 *
	 * @return void
	 */
	public function test_clamp_retention() {
		$this->assertSame( 90, BLT_Activity_Log_Settings::clamp_retention( 0 ) );
		$this->assertSame( 90, BLT_Activity_Log_Settings::clamp_retention( -3 ) );
		$this->assertSame( 90, BLT_Activity_Log_Settings::clamp_retention( 'abc' ) );
		$this->assertSame( 90, BLT_Activity_Log_Settings::clamp_retention( null ) );
		$this->assertSame( 1, BLT_Activity_Log_Settings::clamp_retention( '1' ) );
		$this->assertSame( 30, BLT_Activity_Log_Settings::clamp_retention( '30' ) );
		$this->assertSame( 365, BLT_Activity_Log_Settings::clamp_retention( 3650 ) );
		$this->assertSame( 365, BLT_Activity_Log_Settings::clamp_retention( 1e9 ) );
	}

	/**
	 * Free-text choices are whitelisted.
	 *
	 * @return void
	 */
	public function test_choices_are_whitelisted() {
		$this->assertSame( 'full', BLT_Activity_Log_Settings::save( array( 'ip_mode' => 'something' ) )['ip_mode'] );
		$this->assertSame( 'off', BLT_Activity_Log_Settings::save( array( 'ip_mode' => 'off' ) )['ip_mode'] );
		$this->assertSame( 'core', BLT_Activity_Log_Settings::save( array( 'settings_scope' => 'everything' ) )['settings_scope'] );
		$this->assertSame( 'all', BLT_Activity_Log_Settings::save( array( 'settings_scope' => 'all' ) )['settings_scope'] );
	}

	/**
	 * Booleans are read from the form only when it says it carried them.
	 *
	 * @return void
	 */
	public function test_booleans_need_the_form_marker() {
		$saved = BLT_Activity_Log_Settings::save(
			array(
				'_booleans'                => '1',
				'delete_data_on_uninstall' => '1',
			)
		);

		$this->assertTrue( $saved['delete_data_on_uninstall'] );
		$this->assertFalse( $saved['store_user_agent'], 'Unticked on the form means off.' );

		$kept = BLT_Activity_Log_Settings::save( array( 'ip_mode' => 'off' ) );

		$this->assertTrue( $kept['delete_data_on_uninstall'], 'No marker, no change.' );
	}

	/**
	 * Post type slugs are cleaned and de-duplicated.
	 *
	 * @return void
	 */
	public function test_excluded_post_types_are_sanitized() {
		$this->assertSame( array( 'event', 'shop_order' ), BLT_Activity_Log_Settings::sanitize_slugs( "Event, shop_order\nevent" ) );
		$this->assertSame( array( 'a', 'b' ), BLT_Activity_Log_Settings::sanitize_slugs( array( 'a', 'B', '', 'a' ) ) );
		$this->assertSame( array(), BLT_Activity_Log_Settings::sanitize_slugs( 'this_slug_is_far_too_long_for_a_post_type' ) );
		$this->assertSame( array(), BLT_Activity_Log_Settings::sanitize_slugs( null ) );
	}

	/**
	 * The wp-config constant locks retention.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_constant_locks_retention() {
		define( 'BLT_ACTIVITY_LOG_RETENTION_DAYS', 365 );

		$this->assertTrue( BLT_Activity_Log_Settings::retention_locked() );
		$this->assertSame( 365, BLT_Activity_Log_Settings::retention_days() );

		$saved = BLT_Activity_Log_Settings::save( array( 'retention_days' => 7 ) );

		$this->assertSame( 90, $saved['retention_days'], 'The form cannot change a locked value.' );
		$this->assertSame( 365, BLT_Activity_Log_Settings::retention_days(), 'The constant still wins.' );
	}

	/**
	 * Absurd constant values are clamped to a year.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_constant_is_clamped() {
		define( 'BLT_ACTIVITY_LOG_RETENTION_DAYS', 99999 );

		$this->assertSame( 365, BLT_Activity_Log_Settings::retention_days() );
	}

	/**
	 * A constant of zero is not "forever": it reads as the default.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_constant_of_zero_reads_as_the_default() {
		define( 'BLT_ACTIVITY_LOG_RETENTION_DAYS', 0 );

		$this->assertSame( 90, BLT_Activity_Log_Settings::retention_days() );
	}

	/**
	 * Without the constant retention is the stored value.
	 *
	 * @return void
	 */
	public function test_retention_follows_the_setting_without_the_constant() {
		$this->assertFalse( BLT_Activity_Log_Settings::retention_locked() );
		$this->assertSame( 90, BLT_Activity_Log_Settings::retention_days() );

		BLT_Activity_Log_Settings::save( array( 'retention_days' => 14 ) );

		$this->assertSame( 14, BLT_Activity_Log_Settings::retention_days() );
	}
}
