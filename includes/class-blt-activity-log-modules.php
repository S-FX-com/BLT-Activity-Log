<?php
/**
 * Module registry.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the list of modules, and registers the enabled ones.
 *
 * Booted on `plugins_loaded` priority 1. The list is filterable through
 * `blt_activity_log_modules`.
 */
class BLT_Activity_Log_Modules {

	/**
	 * The `system` module is always on and has no toggle.
	 */
	const ALWAYS_ON = 'system';

	/**
	 * Built-in module classes, in the order they are listed in the UI.
	 *
	 * @return string[]
	 */
	public static function builtin() {
		return array(
			'BLT_Activity_Log_Module_Posts',
			'BLT_Activity_Log_Module_Media',
			'BLT_Activity_Log_Module_Terms',
			'BLT_Activity_Log_Module_Appearance',
			'BLT_Activity_Log_Module_Plugins',
			'BLT_Activity_Log_Module_Themes',
			'BLT_Activity_Log_Module_Core',
			'BLT_Activity_Log_Module_Settings',
			'BLT_Activity_Log_Module_Users',
			'BLT_Activity_Log_Module_Sessions',
			'BLT_Activity_Log_Module_System',
		);
	}

	/**
	 * Instances of every module, built-in and added through the filter.
	 *
	 * @return BLT_Activity_Log_Module[] Keyed by module id.
	 */
	public static function all() {
		/**
		 * Filter the module classes. Add or remove entries to change what the
		 * plugin can record; each must implement BLT_Activity_Log_Module.
		 *
		 * @param string[] $classes Module class names.
		 */
		$classes = apply_filters( 'blt_activity_log_modules', self::builtin() );
		$modules = array();

		if ( ! is_array( $classes ) ) {
			return $modules;
		}

		foreach ( $classes as $class_name ) {
			if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
				continue;
			}

			$module = new $class_name();

			if ( $module instanceof BLT_Activity_Log_Module ) {
				$modules[ $module->id() ] = $module;
			}
		}

		return $modules;
	}

	/**
	 * Whether a module is switched on.
	 *
	 * @param BLT_Activity_Log_Module $module Module.
	 * @return bool
	 */
	public static function is_enabled( BLT_Activity_Log_Module $module ) {
		if ( self::ALWAYS_ON === $module->id() ) {
			return true;
		}

		return BLT_Activity_Log_Settings::module_enabled( $module->id(), $module->default_enabled() );
	}

	/**
	 * Register every enabled module's listeners.
	 *
	 * @return void
	 */
	public static function boot() {
		foreach ( self::all() as $module ) {
			if ( self::is_enabled( $module ) ) {
				$module->register();
			}
		}
	}

	/**
	 * The modules a site owner can switch (everything except `system`).
	 *
	 * @return BLT_Activity_Log_Module[] Keyed by module id.
	 */
	public static function toggleable() {
		$modules = self::all();

		unset( $modules[ self::ALWAYS_ON ] );

		return $modules;
	}
}
