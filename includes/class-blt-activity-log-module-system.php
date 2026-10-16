<?php
/**
 * Module: the plugin's own settings. Always on.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs every change to this plugin's own settings, whatever the settings
 * scope and whether or not the Settings module is on, so turning logging off
 * is itself on the record.
 *
 * Writes to the plugin's snapshot and state options are never logged (they
 * are on the ignore list); `log_cleared` and `exported` are written by the
 * admin screen when those actions run.
 */
class BLT_Activity_Log_Module_System extends BLT_Activity_Log_Module_Base {

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'system';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return __( 'BLT Activity Log', 'blt-activity-log' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function description() {
		return __( 'Changes to this plugin\'s own settings, clearing the log and exporting it. Always on.', 'blt-activity-log' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function default_enabled() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		$this->listen( 'updated_option', 'on_updated', 10, 3 );
		$this->listen( 'added_option', 'on_added', 10, 2 );
	}

	/**
	 * An option changed; log it if it is this plugin's settings.
	 *
	 * @param string $option Option name.
	 * @param mixed  $old_value    Value before.
	 * @param mixed  $new_value    Value after.
	 * @return void
	 */
	public function on_updated( $option, $old_value = null, $new_value = null ) {
		if ( BLT_Activity_Log_Settings::OPTION !== $option ) {
			return;
		}

		$changes = BLT_Activity_Log_Diff::values( BLT_Activity_Log_Settings::OPTION, $old_value, $new_value );

		if ( empty( $changes ) ) {
			return;
		}

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'setting',
				'action'       => 'updated',
				'object_type'  => 'option',
				'object_key'   => BLT_Activity_Log_Settings::OPTION,
				'object_label' => BLT_Activity_Log_Settings::OPTION,
				'details'      => array( 'changes' => $changes ),
			)
		);
	}

	/**
	 * An option was added; log it if it is this plugin's settings.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return void
	 */
	public function on_added( $option, $value = null ) {
		$this->on_updated( $option, array(), $value );
	}
}
