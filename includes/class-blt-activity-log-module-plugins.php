<?php
/**
 * Module: plugins.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records plugins installed, updated, activated, deactivated, deleted, and
 * their auto-update setting changed.
 *
 * The "from" version of an update comes from the snapshot, which is refreshed
 * after every logged change; a change nobody logged is found later by the
 * daily snapshot run and recorded as `changed_externally`.
 */
class BLT_Activity_Log_Module_Plugins extends BLT_Activity_Log_Module_Base {

	/**
	 * Name and version of plugins about to be deleted, keyed by plugin file.
	 *
	 * @var array<string,array{name:string,version:string}>
	 */
	private $doomed = array();

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'plugin';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return __( 'Plugins', 'blt-activity-log' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function description() {
		return __( 'Plugins installed, updated, activated, deactivated or deleted.', 'blt-activity-log' );
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
		$this->listen( 'upgrader_process_complete', 'on_upgrader_complete', 10, 2 );
		$this->listen( 'activated_plugin', 'on_activated', 10, 2 );
		$this->listen( 'deactivated_plugin', 'on_deactivated', 10, 2 );
		$this->listen( 'delete_plugin', 'on_before_delete', 10, 1 );
		$this->listen( 'deleted_plugin', 'on_deleted', 10, 2 );
		$this->listen( 'updated_option', 'on_option_updated', 10, 3 );
		$this->listen( 'added_option', 'on_option_added', 10, 2 );
	}

	/**
	 * An upgrader finished: a plugin was installed or updated.
	 *
	 * @param WP_Upgrader $upgrader   The upgrader.
	 * @param array       $hook_extra What it did.
	 * @return void
	 */
	public function on_upgrader_complete( $upgrader, $hook_extra = array() ) {
		if ( ! is_array( $hook_extra ) || ! isset( $hook_extra['type'], $hook_extra['action'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}

		if ( ! in_array( $hook_extra['action'], array( 'install', 'update' ), true ) ) {
			return;
		}

		$files = array();

		if ( 'install' === $hook_extra['action'] ) {
			$file = ( is_object( $upgrader ) && method_exists( $upgrader, 'plugin_info' ) ) ? $upgrader->plugin_info() : false;

			if ( is_string( $file ) && '' !== $file ) {
				$files[] = $file;
			}
		} elseif ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$files = array_map( 'strval', $hook_extra['plugins'] );
		} elseif ( ! empty( $hook_extra['plugin'] ) ) {
			$files[] = (string) $hook_extra['plugin'];
		}

		if ( empty( $files ) ) {
			return;
		}

		wp_clean_plugins_cache( false );

		$installed = get_plugins();
		$known     = BLT_Activity_Log_Snapshot::stored()['plugins'];
		$method    = $this->update_method( $upgrader );

		$moved = array();

		foreach ( $files as $file ) {
			$to   = isset( $installed[ $file ]['Version'] ) ? (string) $installed[ $file ]['Version'] : '';
			$name = isset( $installed[ $file ]['Name'] ) ? (string) $installed[ $file ]['Name'] : $file;

			// An upload over a plugin that is already installed is an update.
			$was_installed = array_key_exists( $file, $known );
			$action        = ( 'install' === $hook_extra['action'] && ! $was_installed ) ? 'installed' : 'updated';

			// WordPress fires this hook after a failed update too, and a bulk update fires it once with every
			// plugin it was asked about, up to date, failed or not reached. A plugin whose version did not
			// move (an upload excepted: it may replace a plugin with itself) or that is gone was not updated.
			// It is left out here and out of the snapshot, so a plugin that really changed is still found.
			if ( 'updated' === $action && 'upload' !== $method && ( '' === $to || ( $was_installed && (string) $known[ $file ] === $to ) ) ) {
				continue;
			}

			$moved[] = $file;

			BLT_Activity_Log_Logger::record(
				array(
					'category'     => 'plugin',
					'action'       => $action,
					'object_type'  => 'plugin',
					'object_key'   => $file,
					'object_label' => $name,
					'details'      => array(
						'versions' => array(
							'from' => $was_installed ? (string) $known[ $file ] : '',
							'to'   => $to,
						),
						'method'   => $method,
					),
				)
			);
		}

		BLT_Activity_Log_Snapshot::refresh_keys( $moved );
	}

	/**
	 * A plugin was activated.
	 *
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Whether network-wide.
	 * @return void
	 */
	public function on_activated( $plugin, $network_wide = false ) {
		$this->record_state( 'activated', (string) $plugin, (bool) $network_wide );
	}

	/**
	 * A plugin was deactivated.
	 *
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Whether network-wide.
	 * @return void
	 */
	public function on_deactivated( $plugin, $network_wide = false ) {
		$this->record_state( 'deactivated', (string) $plugin, (bool) $network_wide );
	}

	/**
	 * A plugin is about to be deleted. Its header is unreadable afterwards.
	 *
	 * @param string $plugin_file Plugin file.
	 * @return void
	 */
	public function on_before_delete( $plugin_file ) {
		$data = self::plugin_data( (string) $plugin_file );

		$this->doomed[ (string) $plugin_file ] = $data;
	}

	/**
	 * A plugin was deleted.
	 *
	 * @param string $plugin_file Plugin file.
	 * @param bool   $deleted     Whether the files were removed.
	 * @return void
	 */
	public function on_deleted( $plugin_file, $deleted = false ) {
		if ( ! $deleted ) {
			return;
		}

		$plugin_file = (string) $plugin_file;
		$data        = isset( $this->doomed[ $plugin_file ] ) ? $this->doomed[ $plugin_file ] : array(
			'name'    => $plugin_file,
			'version' => '',
		);

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'plugin',
				'action'       => 'deleted',
				'object_type'  => 'plugin',
				'object_key'   => $plugin_file,
				'object_label' => '' !== $data['name'] ? $data['name'] : $plugin_file,
				'details'      => array(
					'versions' => array(
						'from' => $data['version'],
						'to'   => '',
					),
				),
			)
		);

		// delete_plugins() leaves the plugin list cached; without this the deleted plugin stays in the snapshot.
		wp_clean_plugins_cache( false );

		BLT_Activity_Log_Snapshot::refresh_keys( array( $plugin_file ) );
	}

	/**
	 * An option was updated: the auto-update list is ours.
	 *
	 * @param string $option Option name.
	 * @param mixed  $old_value    Value before.
	 * @param mixed  $new_value    Value after.
	 * @return void
	 */
	public function on_option_updated( $option, $old_value = null, $new_value = null ) {
		if ( 'auto_update_plugins' === $option ) {
			$this->record_auto_updates( $old_value, $new_value );
		}
	}

	/**
	 * An option was added: the first plugin set to auto-update adds the list.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return void
	 */
	public function on_option_added( $option, $value = null ) {
		if ( 'auto_update_plugins' === $option ) {
			$this->record_auto_updates( array(), $value );
		}
	}

	/**
	 * Name and version of an installed plugin, read from its header.
	 *
	 * @param string $plugin_file Plugin file, relative to the plugins folder.
	 * @return array{name:string,version:string}
	 */
	public static function plugin_data( $plugin_file ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		$data = is_readable( $path ) ? get_plugin_data( $path, false, false ) : array();

		return array(
			'name'    => isset( $data['Name'] ) ? (string) $data['Name'] : '',
			'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
		);
	}

	/**
	 * Record an activation or deactivation.
	 *
	 * @param string $action       `activated` or `deactivated`.
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Whether network-wide.
	 * @return void
	 */
	private function record_state( $action, $plugin, $network_wide ) {
		$data    = self::plugin_data( $plugin );
		$details = array(
			'versions' => array(
				'from' => '',
				'to'   => $data['version'],
			),
		);

		if ( $network_wide ) {
			$details['network_wide'] = true;
		}

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'plugin',
				'action'       => $action,
				'object_type'  => 'plugin',
				'object_key'   => $plugin,
				'object_label' => '' !== $data['name'] ? $data['name'] : $plugin,
				'details'      => $details,
			)
		);
	}

	/**
	 * Record plugins added to or removed from the auto-update list.
	 *
	 * @param mixed $old_value List before.
	 * @param mixed $new_value List after.
	 * @return void
	 */
	private function record_auto_updates( $old_value, $new_value ) {
		$old_value = is_array( $old_value ) ? array_map( 'strval', $old_value ) : array();
		$new_value = is_array( $new_value ) ? array_map( 'strval', $new_value ) : array();

		foreach ( array(
			'auto_updates_enabled'  => array_diff( $new_value, $old_value ),
			'auto_updates_disabled' => array_diff( $old_value, $new_value ),
		) as $action => $files ) {
			foreach ( $files as $file ) {
				$data = self::plugin_data( $file );

				BLT_Activity_Log_Logger::record(
					array(
						'category'     => 'plugin',
						'action'       => $action,
						'object_type'  => 'plugin',
						'object_key'   => $file,
						'object_label' => '' !== $data['name'] ? $data['name'] : $file,
						'details'      => array(
							'versions' => array(
								'from' => '',
								'to'   => $data['version'],
							),
						),
					)
				);
			}
		}
	}
}
