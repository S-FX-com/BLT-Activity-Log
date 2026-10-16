<?php
/**
 * Module: site settings (options).
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records changes to site options, as a field-level diff.
 *
 * Two scopes, chosen on the Settings tab:
 *
 * - `core` (default): an allowlist of the options WordPress exposes on its own
 *   screens (General, Writing, Reading, Discussion, Media, Permalinks,
 *   Privacy).
 * - `all`: any option changed during a request with a signed-in user, minus an
 *   ignore list. Changes from cron, background jobs and anonymous requests are
 *   not logged in this mode.
 *
 * `updated_option` fires constantly (every transient write on a site without
 * an object cache), so the cheapest checks come first.
 */
class BLT_Activity_Log_Module_Settings extends BLT_Activity_Log_Module_Base {

	/**
	 * Option whose change is logged as a request rather than a change.
	 */
	const ADMIN_EMAIL_REQUEST = 'new_admin_email';

	/**
	 * Values of options just before they are deleted, keyed by option name.
	 *
	 * @var array<string,mixed>
	 */
	private $doomed = array();

	/**
	 * The allowlist as a lookup set, built on first use.
	 *
	 * @var array<string,int>|null
	 */
	private $core_set = null;

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'setting';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return __( 'Settings', 'blt-activity-log' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function description() {
		return __( 'Changes to site settings, with the old and new value of each field.', 'blt-activity-log' );
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
		$this->listen( 'delete_option', 'on_before_delete', 10, 1 );
		$this->listen( 'deleted_option', 'on_deleted', 10, 1 );
	}

	/**
	 * An option changed.
	 *
	 * @param string $option Option name.
	 * @param mixed  $old_value    Value before.
	 * @param mixed  $new_value    Value after.
	 * @return void
	 */
	public function on_updated( $option, $old_value = null, $new_value = null ) {
		if ( $this->is_loggable( (string) $option ) ) {
			$this->record( (string) $option, $old_value, $new_value, 'updated' );
		}
	}

	/**
	 * An option was added.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return void
	 */
	public function on_added( $option, $value = null ) {
		if ( $this->is_loggable( (string) $option ) ) {
			$this->record( (string) $option, '', $value, 'updated' );
		}
	}

	/**
	 * An option is about to be deleted. Read it while it exists.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public function on_before_delete( $option ) {
		if ( $this->is_loggable( (string) $option ) ) {
			$this->doomed[ (string) $option ] = get_option( $option );
		}
	}

	/**
	 * An option was deleted.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public function on_deleted( $option ) {
		$option = (string) $option;

		if ( ! array_key_exists( $option, $this->doomed ) ) {
			return;
		}

		$old_value = $this->doomed[ $option ];

		unset( $this->doomed[ $option ] );

		$this->record( $option, $old_value, '', 'deleted' );
	}

	/**
	 * Whether a change to an option should be considered at all.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private function is_loggable( $option ) {
		// The plugin's own settings are always logged, by the system module.
		if ( BLT_Activity_Log_Settings::OPTION === $option ) {
			return false;
		}

		// Options written before `init` are WordPress booting, not a person editing.
		if ( ! did_action( 'init' ) ) {
			return false;
		}

		if ( 'all' === BLT_Activity_Log_Settings::get( 'settings_scope', 'core' ) ) {
			$actor = BLT_Activity_Log_Context::actor();

			return $actor['user_id'] > 0 && ! BLT_Activity_Log_Core_Options::is_ignored( $option );
		}

		if ( null === $this->core_set ) {
			$this->core_set = array_flip( BLT_Activity_Log_Core_Options::all() );
		}

		return isset( $this->core_set[ $option ] ) && ! BLT_Activity_Log_Core_Options::is_ignored( $option );
	}

	/**
	 * Record one option change.
	 *
	 * @param string $option Option name.
	 * @param mixed  $old_value    Value before.
	 * @param mixed  $new_value    Value after.
	 * @param string $action `updated` or `deleted`.
	 * @return void
	 */
	private function record( $option, $old_value, $new_value, $action ) {
		$changes = BLT_Activity_Log_Diff::values( $option, $old_value, $new_value );

		if ( empty( $changes ) ) {
			return;
		}

		$details = array( 'changes' => $changes );

		if ( did_action( 'customize_save' ) ) {
			$details['via'] = 'customizer';
		}

		if ( self::ADMIN_EMAIL_REQUEST === $option && 'updated' === $action ) {
			$action = 'admin_email_change_requested';
		}

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'setting',
				'action'       => $action,
				'object_type'  => 'option',
				'object_key'   => $option,
				'object_label' => $option,
				'details'      => $details,
			)
		);
	}
}
