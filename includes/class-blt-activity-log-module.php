<?php
/**
 * The contract every logging module implements.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A module is one category of activity: it listens to a group of WordPress
 * hooks and records entries through BLT_Activity_Log_Logger.
 *
 * The file is named `class-` rather than `interface-` because the computed
 * autoloader resolves every BLT_Activity_Log_* name to a `class-` file.
 */
interface BLT_Activity_Log_Module {

	/**
	 * Stable id. Also the entry `category` and the key in the settings
	 * `modules` map.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Translated name shown on the Settings tab and in the Activity filter.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * One translated line saying what the module records.
	 *
	 * @return string
	 */
	public function description();

	/**
	 * Whether the module is on until the owner says otherwise.
	 *
	 * @return bool
	 */
	public function default_enabled();

	/**
	 * Hook the module's listeners. Called only when the module is enabled.
	 *
	 * @return void
	 */
	public function register();
}
