<?php
/**
 * The options WordPress exposes on its own screens, and the ones never logged.
 * WordPress-free.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data, kept in one class so it is easy to audit and to test.
 *
 * The allowlist is built from `$allowed_options` in `wp-admin/options.php` at
 * the 6.0 floor (General, Writing, Reading, Discussion, Media; the Privacy and
 * Permalinks screens save through their own handlers), with the conditional
 * entries included unconditionally, plus a few that WordPress changes through
 * its own flows rather than the options form.
 */
class BLT_Activity_Log_Core_Options {

	/**
	 * Options logged in the default `core` scope.
	 */
	const OPTIONS = array(
		// General.
		'blogname',
		'blogdescription',
		'site_icon',
		'siteurl',
		'home',
		'admin_email',
		'new_admin_email',
		'users_can_register',
		'default_role',
		'gmt_offset',
		'timezone_string',
		'date_format',
		'time_format',
		'start_of_week',
		'WPLANG',
		// Writing.
		'default_category',
		'default_email_category',
		'default_link_category',
		'default_post_format',
		'use_smilies',
		'use_balanceTags',
		'ping_sites',
		'mailserver_url',
		'mailserver_port',
		'mailserver_login',
		'mailserver_pass',
		// Reading.
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'posts_per_page',
		'posts_per_rss',
		'rss_use_excerpt',
		'blog_public',
		'blog_charset',
		// Discussion.
		'default_pingback_flag',
		'default_ping_status',
		'default_comment_status',
		'comments_notify',
		'moderation_notify',
		'comment_moderation',
		'require_name_email',
		'comment_previously_approved',
		'comment_max_links',
		'moderation_keys',
		'disallowed_keys',
		'show_avatars',
		'avatar_rating',
		'avatar_default',
		'close_comments_for_old_posts',
		'close_comments_days_old',
		'thread_comments',
		'thread_comments_depth',
		'page_comments',
		'comments_per_page',
		'default_comments_page',
		'comment_order',
		'comment_registration',
		'show_comments_cookies_opt_in',
		// Media.
		'thumbnail_size_w',
		'thumbnail_size_h',
		'thumbnail_crop',
		'medium_size_w',
		'medium_size_h',
		'large_size_w',
		'large_size_h',
		'image_default_size',
		'image_default_align',
		'image_default_link_type',
		'uploads_use_yearmonth_folders',
		'upload_path',
		'upload_url_path',
		// Permalinks and Privacy.
		'permalink_structure',
		'category_base',
		'tag_base',
		'wp_page_for_privacy_policy',
	);

	/**
	 * Options never logged, in either scope. Exact names, or patterns with `*`.
	 *
	 * Beyond the spec's list, this also holds options another module already
	 * covers (`sidebars_widgets`, `theme_mods_*`, `auto_update_*`) so they are
	 * not logged twice, and update-checker state, which is rewritten on admin
	 * page loads.
	 */
	const IGNORED = array(
		'_transient_*',
		'_site_transient_*',
		'cron',
		'rewrite_rules',
		'active_plugins',
		'recently_activated',
		'uninstall_plugins',
		'*.lock',
		'*_lock',
		'db_upgraded',
		'recovery_keys',
		'can_compress_scripts',
		'blt_activity_log_snapshot',
		'blt_activity_log_state',
		'sidebars_widgets',
		'theme_mods_*',
		'auto_update_plugins',
		'auto_update_themes',
		'external_updates-*',
		// The Themes module records a switch; these are what WordPress writes while doing it.
		'template',
		'stylesheet',
		'current_theme',
		'theme_switched',
		'theme_switch_menu_locations',
		// Caches and upgrade flags WordPress maintains for itself.
		'*_children',
		'wp_calendar_block_has_published_posts',
		'db_version',
		'fresh_site',
		'finished_*',
		'recently_edited',
		// Role definitions (rewritten when a capability is granted) and the admin-email confirmation token.
		'*user_roles',
		'adminhash',
		// This plugin's own schema bookkeeping, and the keys and salts WordPress generates (and stores as
		// options) when wp-config.php does not define them. These are secrets: never logged, whatever the pattern.
		'blt_activity_log_db_version',
		'auth_key',
		'secure_auth_key',
		'logged_in_key',
		'nonce_key',
		'auth_salt',
		'secure_auth_salt',
		'logged_in_salt',
		'nonce_salt',
	);

	/**
	 * Precompiled ignore regex, per pattern list.
	 *
	 * @var array<string,string>
	 */
	private static $compiled = array();

	/**
	 * The `core` scope allowlist, after the filter.
	 *
	 * @return string[]
	 */
	public static function all() {
		/**
		 * Filter the options logged in the `core` settings scope.
		 *
		 * @param string[] $options Option names.
		 */
		$options = apply_filters( 'blt_activity_log_core_options', self::OPTIONS );

		return is_array( $options ) ? $options : self::OPTIONS;
	}

	/**
	 * Whether an option is on the `core` scope allowlist.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function contains( $name ) {
		return in_array( $name, self::all(), true );
	}

	/**
	 * The ignore patterns, after the filter.
	 *
	 * @return string[]
	 */
	public static function ignored() {
		/**
		 * Filter the options never logged, as exact names and `*` patterns.
		 *
		 * @param string[] $patterns Patterns.
		 */
		$patterns = apply_filters( 'blt_activity_log_ignored_options', self::IGNORED );

		return is_array( $patterns ) ? $patterns : self::IGNORED;
	}

	/**
	 * Whether an option name matches any ignore pattern.
	 *
	 * @param string        $name     Option name.
	 * @param string[]|null $patterns Patterns; the filtered list when omitted.
	 * @return bool
	 */
	public static function is_ignored( $name, ?array $patterns = null ) {
		$patterns = null === $patterns ? self::ignored() : $patterns;
		$key      = md5( implode( "\n", $patterns ) );

		if ( ! isset( self::$compiled[ $key ] ) ) {
			$parts = array();

			foreach ( $patterns as $pattern ) {
				$parts[] = str_replace( '\*', '.*', preg_quote( (string) $pattern, '/' ) );
			}

			self::$compiled[ $key ] = empty( $parts ) ? '' : '/^(?:' . implode( '|', $parts ) . ')$/';
		}

		return '' !== self::$compiled[ $key ] && 1 === preg_match( self::$compiled[ $key ], (string) $name );
	}
}
