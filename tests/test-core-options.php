<?php
/**
 * Core_Options: the allowlist and the ignore patterns.
 *
 * @package Blt_Activity_Log
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Activity_Log_Core_Options.
 */
class Test_Core_Options extends TestCase {

	/**
	 * Reset shimmed state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * Options named in the spec, and the ones on the 6.0 options screens.
	 *
	 * @return void
	 */
	public function test_allowlist_contains_the_core_settings() {
		foreach ( array(
			'blogname',
			'blogdescription',
			'admin_email',
			'new_admin_email',
			'users_can_register',
			'default_role',
			'timezone_string',
			'posts_per_page',
			'show_on_front',
			'page_on_front',
			'blog_public',
			'default_comment_status',
			'thumbnail_size_w',
			'permalink_structure',
			'category_base',
			'tag_base',
			'wp_page_for_privacy_policy',
			'mailserver_pass',
		) as $option ) {
			$this->assertTrue( BLT_Activity_Log_Core_Options::contains( $option ), $option );
		}
	}

	/**
	 * Options that are not on a WordPress settings screen are not in it.
	 *
	 * @return void
	 */
	public function test_allowlist_excludes_everything_else() {
		foreach ( array( 'cron', 'active_plugins', 'rewrite_rules', 'widget_text', 'woocommerce_currency', '_transient_foo', 'blt_activity_log_settings' ) as $option ) {
			$this->assertFalse( BLT_Activity_Log_Core_Options::contains( $option ), $option );
		}
	}

	/**
	 * The allowlist has no duplicates and only strings.
	 *
	 * @return void
	 */
	public function test_allowlist_is_clean() {
		$all = BLT_Activity_Log_Core_Options::OPTIONS;

		$this->assertSame( count( $all ), count( array_unique( $all ) ) );

		foreach ( $all as $option ) {
			$this->assertIsString( $option );
			$this->assertNotSame( '', $option );
		}
	}

	/**
	 * A site can extend the allowlist.
	 *
	 * @return void
	 */
	public function test_allowlist_is_filterable() {
		add_filter(
			'blt_activity_log_core_options',
			function ( $options ) {
				$options[] = 'my_option';

				return $options;
			}
		);

		$this->assertTrue( BLT_Activity_Log_Core_Options::contains( 'my_option' ) );
	}

	/**
	 * Names and `*` patterns from the spec are all ignored.
	 *
	 * @return void
	 */
	public function test_ignored_names_and_patterns() {
		foreach ( array(
			'_transient_timeout_foo',
			'_transient_feed_abc',
			'_site_transient_update_plugins',
			'cron',
			'rewrite_rules',
			'active_plugins',
			'recently_activated',
			'uninstall_plugins',
			'something.lock',
			'my_plugin_lock',
			'db_upgraded',
			'recovery_keys',
			'can_compress_scripts',
			'blt_activity_log_snapshot',
			'blt_activity_log_state',
		) as $option ) {
			$this->assertTrue( BLT_Activity_Log_Core_Options::is_ignored( $option ), $option );
		}
	}

	/**
	 * Options another module already covers are not logged twice.
	 *
	 * @return void
	 */
	public function test_options_covered_by_other_modules_are_ignored() {
		foreach ( array( 'sidebars_widgets', 'theme_mods_twentytwentyfive', 'auto_update_plugins', 'auto_update_themes', 'external_updates-blt-activity-log' ) as $option ) {
			$this->assertTrue( BLT_Activity_Log_Core_Options::is_ignored( $option ), $option );
		}
	}

	/**
	 * What WordPress writes as a side effect of its own work is not a setting
	 * somebody changed: a theme switch, caches, upgrade flags, role definitions
	 * and the admin-email confirmation token.
	 *
	 * @return void
	 */
	public function test_wordpress_side_effect_options_are_ignored() {
		foreach ( array(
			'template',
			'stylesheet',
			'current_theme',
			'theme_switched',
			'theme_switch_menu_locations',
			'category_children',
			'wp_calendar_block_has_published_posts',
			'db_version',
			'fresh_site',
			'finished_splitting_shared_terms',
			'recently_edited',
			'wp_user_roles',
			'adminhash',
		) as $option ) {
			$this->assertTrue( BLT_Activity_Log_Core_Options::is_ignored( $option ), $option );
		}
	}

	/**
	 * The `core` allowlist never names an option the ignore list drops.
	 *
	 * @return void
	 */
	public function test_the_allowlist_and_the_ignore_list_do_not_overlap() {
		foreach ( BLT_Activity_Log_Core_Options::OPTIONS as $option ) {
			$this->assertFalse( BLT_Activity_Log_Core_Options::is_ignored( $option ), $option );
		}
	}

	/**
	 * Ordinary options are not ignored, and a wildcard is anchored.
	 *
	 * @return void
	 */
	public function test_ordinary_options_are_not_ignored() {
		foreach ( array( 'blogname', 'my_transient_like', 'cron_schedules', 'lockdown', 'block_lock_screen', 'woocommerce_currency' ) as $option ) {
			$this->assertFalse( BLT_Activity_Log_Core_Options::is_ignored( $option ), $option );
		}
	}

	/**
	 * Regex metacharacters in a pattern are literal.
	 *
	 * @return void
	 */
	public function test_pattern_metacharacters_are_literal() {
		$patterns = array( 'a.b', 'x+y*' );

		$this->assertTrue( BLT_Activity_Log_Core_Options::is_ignored( 'a.b', $patterns ) );
		$this->assertFalse( BLT_Activity_Log_Core_Options::is_ignored( 'aXb', $patterns ), 'A dot is a dot.' );
		$this->assertTrue( BLT_Activity_Log_Core_Options::is_ignored( 'x+yzzz', $patterns ) );
		$this->assertFalse( BLT_Activity_Log_Core_Options::is_ignored( 'xxy', $patterns ) );
	}

	/**
	 * The ignore list is filterable, and an empty list ignores nothing.
	 *
	 * @return void
	 */
	public function test_ignore_list_is_filterable() {
		$this->assertFalse( BLT_Activity_Log_Core_Options::is_ignored( 'cron', array() ) );

		add_filter(
			'blt_activity_log_ignored_options',
			function ( $patterns ) {
				$patterns[] = 'noisy_*';

				return $patterns;
			}
		);

		$this->assertTrue( BLT_Activity_Log_Core_Options::is_ignored( 'noisy_counter' ) );
	}
}
