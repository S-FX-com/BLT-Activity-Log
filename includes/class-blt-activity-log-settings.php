<?php
/**
 * Settings storage and retrieval.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the single `blt_activity_log_settings` option, merged over defaults
 * and hand-sanitized on save (never `register_setting`), plus the separate,
 * non-autoloaded state option.
 */
class BLT_Activity_Log_Settings {

	/**
	 * Settings option (autoload yes: read on nearly every request).
	 */
	const OPTION = 'blt_activity_log_settings';

	/**
	 * State option (autoload no): last prune time, counters, start date.
	 */
	const STATE_OPTION = 'blt_activity_log_state';

	/**
	 * Highest retention that can be saved, in days.
	 */
	const MAX_RETENTION_DAYS = 3650;

	/**
	 * The modules a site owner can switch, plus the nested failed-sign-in
	 * toggle, with their defaults.
	 *
	 * @return array<string,bool>
	 */
	public static function default_modules() {
		return array(
			'post'           => true,
			'media'          => true,
			'term'           => true,
			'appearance'     => true,
			'plugin'         => true,
			'theme'          => true,
			'core'           => true,
			'setting'        => true,
			'user'           => true,
			'session'        => true,
			'session_failed' => false,
		);
	}

	/**
	 * Default settings values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'modules'                  => self::default_modules(),
			'settings_scope'           => 'core',
			'excluded_post_types'      => array(),
			'retention_days'           => 90,
			'ip_mode'                  => 'full',
			'store_user_agent'         => true,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Seed defaults on activation without clobbering existing values.
	 *
	 * @return void
	 */
	public static function seed_defaults() {
		$existing = get_option( self::OPTION, array() );

		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		update_option( self::OPTION, self::merge( $existing ), true );
	}

	/**
	 * All settings, merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		return self::merge( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Merge stored values over defaults, one level deeper for `modules`.
	 *
	 * @param array $stored Stored settings.
	 * @return array<string,mixed>
	 */
	public static function merge( array $stored ) {
		$defaults = self::defaults();
		$merged   = array_merge( $defaults, $stored );

		$modules = isset( $stored['modules'] ) && is_array( $stored['modules'] ) ? $stored['modules'] : array();

		$merged['modules'] = array_merge( $defaults['modules'], $modules );

		return $merged;
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Fallback when the key does not exist.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Whether a module (or the nested `session_failed` toggle) is switched on.
	 *
	 * @param string $id      Module id.
	 * @param bool   $fallback Value for a module the settings do not know about.
	 * @return bool
	 */
	public static function module_enabled( $id, $fallback = true ) {
		$modules = self::all()['modules'];

		return array_key_exists( $id, $modules ) ? (bool) $modules[ $id ] : (bool) $fallback;
	}

	/**
	 * Retention in days; 0 keeps entries forever.
	 *
	 * The wp-config constant wins over the stored setting.
	 *
	 * @return int
	 */
	public static function retention_days() {
		if ( self::retention_locked() ) {
			return max( 0, min( self::MAX_RETENTION_DAYS, (int) BLT_ACTIVITY_LOG_RETENTION_DAYS ) );
		}

		return max( 0, min( self::MAX_RETENTION_DAYS, (int) self::get( 'retention_days', 90 ) ) );
	}

	/**
	 * Whether retention is fixed by the wp-config constant.
	 *
	 * @return bool
	 */
	public static function retention_locked() {
		return defined( 'BLT_ACTIVITY_LOG_RETENTION_DAYS' );
	}

	/**
	 * Persist a sanitized settings array.
	 *
	 * Fields missing from the input keep their stored value, so a partial
	 * programmatic save cannot reset the rest. A submitted form always carries
	 * every toggle (the screen posts a hidden marker with the module map).
	 *
	 * @param array<string,mixed> $input Raw input (already unslashed).
	 * @return array<string,mixed> The stored settings.
	 */
	public static function save( array $input ) {
		$current = self::all();
		$clean   = $current;

		// An unticked toggle is not posted at all, so the form sends a marker
		// and every module the registry knows is read as on or off from it.
		if ( isset( $input['_modules'] ) || isset( $input['modules'] ) ) {
			$posted = isset( $input['modules'] ) && is_array( $input['modules'] ) ? $input['modules'] : array();
			$ids    = array_unique( array_merge( array_keys( self::default_modules() ), array_keys( BLT_Activity_Log_Modules::toggleable() ) ) );

			$modules = array();

			foreach ( $ids as $id ) {
				$modules[ $id ] = ! empty( $posted[ $id ] );
			}

			$clean['modules'] = $modules;
		}

		if ( isset( $input['settings_scope'] ) ) {
			$clean['settings_scope'] = 'all' === $input['settings_scope'] ? 'all' : 'core';
		}

		if ( isset( $input['excluded_post_types'] ) ) {
			$clean['excluded_post_types'] = self::sanitize_slugs( $input['excluded_post_types'] );
		}

		if ( isset( $input['retention_days'] ) && ! self::retention_locked() ) {
			$clean['retention_days'] = max( 0, min( self::MAX_RETENTION_DAYS, absint( $input['retention_days'] ) ) );
		}

		if ( isset( $input['ip_mode'] ) ) {
			$clean['ip_mode'] = in_array( $input['ip_mode'], array( 'full', 'anonymized', 'off' ), true ) ? $input['ip_mode'] : 'full';
		}

		if ( isset( $input['_booleans'] ) ) {
			$clean['store_user_agent']         = ! empty( $input['store_user_agent'] );
			$clean['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );
		}

		update_option( self::OPTION, $clean, true );

		return $clean;
	}

	/**
	 * Normalize a list of slugs from an array or a newline / comma separated string.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	public static function sanitize_slugs( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();

		foreach ( $value as $slug ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' !== $slug && strlen( $slug ) <= 20 && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
		}

		return $out;
	}

	/**
	 * The state option, merged over its defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function state() {
		$stored = get_option( self::STATE_OPTION, array() );

		return array_merge(
			array(
				'started_at'      => '',
				'last_prune_at'   => 0,
				'last_prune_rows' => 0,
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * Merge values into the state option (autoload no).
	 *
	 * @param array<string,mixed> $values Values to set.
	 * @return void
	 */
	public static function update_state( array $values ) {
		update_option( self::STATE_OPTION, array_merge( self::state(), $values ), false );
	}
}
