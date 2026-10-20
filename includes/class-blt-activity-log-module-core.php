<?php
/**
 * Module: WordPress core updates.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records a successful WordPress core update, with the version it came from
 * and the one it went to.
 */
class BLT_Activity_Log_Module_Core extends BLT_Activity_Log_Module_Base {

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'core';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return __( 'WordPress updates', 'blt-activity-log' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function description() {
		return __( 'WordPress core updated to a new version.', 'blt-activity-log' );
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
		$this->listen( '_core_updated_successfully', 'on_updated', 10, 1 );
	}

	/**
	 * Core finished updating.
	 *
	 * The argument is the NEW version: update_core() imports it from the new
	 * release's version file. The running request still holds the old one in
	 * the `$wp_version` global, which is why the snapshot is refreshed with the
	 * new version passed in rather than read back.
	 *
	 * @param string $new_version The version core was updated to.
	 * @return void
	 */
	public function on_updated( $new_version = '' ) {
		$new_version = (string) $new_version;
		$stored      = BLT_Activity_Log_Snapshot::stored();
		$from        = '' !== $stored['core'] ? $stored['core'] : (string) get_bloginfo( 'version' );

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'core',
				'action'       => 'updated',
				'object_type'  => 'core',
				'object_key'   => 'wordpress',
				'object_label' => 'WordPress',
				'details'      => array(
					'versions' => array(
						'from' => $from,
						'to'   => $new_version,
					),
					'method'   => $this->update_method(),
				),
			)
		);

		BLT_Activity_Log_Snapshot::refresh_keys( array(), array(), array( 'core' => $new_version ), true );
	}
}
