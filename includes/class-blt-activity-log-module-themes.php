<?php
/**
 * Module: themes.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records themes installed, updated, switched, deleted, and their auto-update
 * setting changed. Same pattern as plugins; the "from" version comes from the
 * snapshot.
 */
class BLT_Activity_Log_Module_Themes extends BLT_Activity_Log_Module_Base {

	/**
	 * Name and version of themes about to be deleted, keyed by stylesheet.
	 *
	 * @var array<string,array{name:string,version:string}>
	 */
	private $doomed = array();

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'theme';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return __( 'Themes', 'blt-activity-log' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function description() {
		return __( 'Themes installed, updated, switched or deleted.', 'blt-activity-log' );
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
		$this->listen( 'switch_theme', 'on_switched', 10, 3 );
		$this->listen( 'delete_theme', 'on_before_delete', 10, 1 );
		$this->listen( 'deleted_theme', 'on_deleted', 10, 2 );
		$this->listen( 'updated_option', 'on_option_updated', 10, 3 );
		$this->listen( 'added_option', 'on_option_added', 10, 2 );
	}

	/**
	 * An upgrader finished: a theme was installed or updated.
	 *
	 * @param WP_Upgrader $upgrader   The upgrader.
	 * @param array       $hook_extra What it did.
	 * @return void
	 */
	public function on_upgrader_complete( $upgrader, $hook_extra = array() ) {
		if ( ! is_array( $hook_extra ) || ! isset( $hook_extra['type'], $hook_extra['action'] ) || 'theme' !== $hook_extra['type'] ) {
			return;
		}

		if ( ! in_array( $hook_extra['action'], array( 'install', 'update' ), true ) ) {
			return;
		}

		$stylesheets = array();

		if ( 'install' === $hook_extra['action'] ) {
			$theme = ( is_object( $upgrader ) && method_exists( $upgrader, 'theme_info' ) ) ? $upgrader->theme_info() : false;

			if ( $theme instanceof WP_Theme ) {
				$stylesheets[] = $theme->get_stylesheet();
			}
		} elseif ( ! empty( $hook_extra['themes'] ) && is_array( $hook_extra['themes'] ) ) {
			$stylesheets = array_map( 'strval', $hook_extra['themes'] );
		} elseif ( ! empty( $hook_extra['theme'] ) ) {
			$stylesheets[] = (string) $hook_extra['theme'];
		}

		if ( empty( $stylesheets ) ) {
			return;
		}

		wp_clean_themes_cache( false );

		$known  = BLT_Activity_Log_Snapshot::stored()['themes'];
		$method = $this->update_method( $upgrader );

		foreach ( $stylesheets as $stylesheet ) {
			$data          = self::theme_data( $stylesheet );
			$was_installed = array_key_exists( $stylesheet, $known );
			$action        = ( 'install' === $hook_extra['action'] && ! $was_installed ) ? 'installed' : 'updated';

			BLT_Activity_Log_Logger::record(
				array(
					'category'     => 'theme',
					'action'       => $action,
					'object_type'  => 'theme',
					'object_key'   => $stylesheet,
					'object_label' => '' !== $data['name'] ? $data['name'] : $stylesheet,
					'details'      => array(
						'versions' => array(
							'from' => $was_installed ? (string) $known[ $stylesheet ] : '',
							'to'   => $data['version'],
						),
						'method'   => $method,
					),
				)
			);
		}

		BLT_Activity_Log_Snapshot::refresh();
	}

	/**
	 * The active theme changed.
	 *
	 * @param string        $new_name  New theme name.
	 * @param WP_Theme|null $new_theme New theme.
	 * @param WP_Theme|null $old_theme Old theme.
	 * @return void
	 */
	public function on_switched( $new_name, $new_theme = null, $old_theme = null ) {
		$stylesheet = $new_theme instanceof WP_Theme ? $new_theme->get_stylesheet() : '';

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'theme',
				'action'       => 'switched',
				'object_type'  => 'theme',
				'object_key'   => '' !== $stylesheet ? $stylesheet : sanitize_title( (string) $new_name ),
				'object_label' => (string) $new_name,
				'details'      => array(
					'changes' => array(
						array(
							'field'  => 'theme',
							'before' => $old_theme instanceof WP_Theme ? (string) $old_theme->get( 'Name' ) : '',
							'after'  => (string) $new_name,
						),
					),
				),
			)
		);
	}

	/**
	 * A theme is about to be deleted. Its header is unreadable afterwards.
	 *
	 * @param string $stylesheet Theme folder.
	 * @return void
	 */
	public function on_before_delete( $stylesheet ) {
		$this->doomed[ (string) $stylesheet ] = self::theme_data( (string) $stylesheet );
	}

	/**
	 * A theme was deleted.
	 *
	 * @param string $stylesheet Theme folder.
	 * @param bool   $deleted    Whether the files were removed.
	 * @return void
	 */
	public function on_deleted( $stylesheet, $deleted = false ) {
		if ( ! $deleted ) {
			return;
		}

		$stylesheet = (string) $stylesheet;
		$data       = isset( $this->doomed[ $stylesheet ] ) ? $this->doomed[ $stylesheet ] : array(
			'name'    => $stylesheet,
			'version' => '',
		);

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'theme',
				'action'       => 'deleted',
				'object_type'  => 'theme',
				'object_key'   => $stylesheet,
				'object_label' => '' !== $data['name'] ? $data['name'] : $stylesheet,
				'details'      => array(
					'versions' => array(
						'from' => $data['version'],
						'to'   => '',
					),
				),
			)
		);

		BLT_Activity_Log_Snapshot::refresh();
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
		if ( 'auto_update_themes' === $option ) {
			$this->record_auto_updates( $old_value, $new_value );
		}
	}

	/**
	 * An option was added.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return void
	 */
	public function on_option_added( $option, $value = null ) {
		if ( 'auto_update_themes' === $option ) {
			$this->record_auto_updates( array(), $value );
		}
	}

	/**
	 * Name and version of an installed theme.
	 *
	 * @param string $stylesheet Theme folder.
	 * @return array{name:string,version:string}
	 */
	public static function theme_data( $stylesheet ) {
		$theme = wp_get_theme( $stylesheet );

		if ( ! $theme->exists() ) {
			return array(
				'name'    => '',
				'version' => '',
			);
		}

		return array(
			'name'    => (string) $theme->get( 'Name' ),
			'version' => (string) $theme->get( 'Version' ),
		);
	}

	/**
	 * Record themes added to or removed from the auto-update list.
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
		) as $action => $stylesheets ) {
			foreach ( $stylesheets as $stylesheet ) {
				$data = self::theme_data( $stylesheet );

				BLT_Activity_Log_Logger::record(
					array(
						'category'     => 'theme',
						'action'       => $action,
						'object_type'  => 'theme',
						'object_key'   => $stylesheet,
						'object_label' => '' !== $data['name'] ? $data['name'] : $stylesheet,
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
