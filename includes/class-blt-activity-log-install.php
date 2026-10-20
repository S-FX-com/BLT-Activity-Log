<?php
/**
 * Activation, schema, capability and cron lifecycle.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the table, grants the capability, schedules the two
 * daily jobs, and refuses network activation. Deactivation is non-destructive:
 * the log is never removed here (see uninstall.php for the opt-in teardown).
 */
class BLT_Activity_Log_Install {

	/**
	 * Schema version. Bump when the CREATE TABLE definition changes.
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option storing the installed schema version (autoload yes).
	 */
	const DB_VERSION_OPTION = 'blt_activity_log_db_version';

	/**
	 * Capability that opens the log.
	 */
	const CAP = 'blt_view_activity_log';

	/**
	 * Cron hooks.
	 */
	const CRON_PRUNE    = 'blt_activity_log_prune';
	const CRON_SNAPSHOT = 'blt_activity_log_snapshot';

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether the activation is network-wide.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			// activate_plugin() runs this hook before it records the plugin as
			// active, so stopping here is enough: nothing is left to undo.
			wp_die(
				esc_html__( 'BLT Activity Log cannot be network activated. Activate it on each site you want to log, from that site\'s Plugins screen.', 'blt-activity-log' ),
				esc_html__( 'BLT Activity Log', 'blt-activity-log' ),
				array( 'back_link' => true )
			);
		}

		self::install_schema();
		BLT_Activity_Log_Settings::seed_defaults();
		self::grant_caps();
		self::schedule();

		$state = BLT_Activity_Log_Settings::state();

		if ( '' === (string) $state['started_at'] ) {
			BLT_Activity_Log_Settings::update_state( array( 'started_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		}

		// The first entry is written directly: the plugin's listeners are not
		// registered yet in the request that activates it.
		BLT_Activity_Log_Snapshot::refresh();

		BLT_Activity_Log_Logger::write_now(
			array(
				'category'     => 'system',
				'action'       => 'logging_started',
				'object_type'  => 'plugin',
				'object_key'   => BLT_ACTIVITY_LOG_BASENAME,
				'object_label' => 'BLT Activity Log',
				'details'      => array(
					'versions' => array(
						'from' => '',
						'to'   => BLT_ACTIVITY_LOG_VERSION,
					),
				),
			)
		);
	}

	/**
	 * Deactivation hook. Stops the jobs; keeps every entry and setting.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::unschedule();
	}

	/**
	 * Re-run the install when the stored schema version is stale.
	 *
	 * Hooked on `init` so a plugin update by file copy, which never fires the
	 * activation hook, still migrates. The version is autoloaded, so a
	 * request with nothing to upgrade runs no query.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install_schema();
		self::grant_caps();
		self::schedule();
	}

	/**
	 * Create or upgrade the table. Idempotent: dbDelta diffs the schema.
	 *
	 * The statement follows dbDelta's formatting rules to the letter: one
	 * column per line, KEY (not INDEX), two spaces after PRIMARY KEY, no
	 * backticks.
	 *
	 * @return void
	 */
	public static function install_schema() {
		global $wpdb;

		$table           = BLT_Activity_Log_Query::table();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  last_at datetime NOT NULL,
  occurrences smallint(5) unsigned NOT NULL DEFAULT 1,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_login varchar(60) NOT NULL DEFAULT '',
  user_role varchar(64) NOT NULL DEFAULT '',
  category varchar(32) NOT NULL,
  action varchar(48) NOT NULL,
  object_type varchar(64) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned DEFAULT NULL,
  object_key varchar(191) DEFAULT NULL,
  object_label varchar(255) NOT NULL DEFAULT '',
  context varchar(16) NOT NULL,
  ip varchar(45) DEFAULT NULL,
  user_agent varchar(255) DEFAULT NULL,
  details longtext,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY user_created (user_id,created_at),
  KEY cat_action_created (category,action,created_at),
  KEY object_num (object_type,object_id),
  KEY object_key (object_key)
) {$charset_collate};"
		);

		// If the table could not be created (no CREATE privilege, say), do not record it as done: the next
		// request tries again, instead of every write failing for good.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table name.
		if ( 0 === strcasecmp( (string) $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $wpdb->esc_like( $table ) ) . "'" ), $table ) ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
		}
	}

	/**
	 * Give Administrators the capability that opens the log.
	 *
	 * @return void
	 */
	public static function grant_caps() {
		$role = get_role( 'administrator' );

		if ( $role && ! $role->has_cap( self::CAP ) ) {
			$role->add_cap( self::CAP );
		}
	}

	/**
	 * Schedule the daily prune and snapshot, unless already scheduled.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_PRUNE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_PRUNE );
		}

		if ( ! wp_next_scheduled( self::CRON_SNAPSHOT ) ) {
			wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'daily', self::CRON_SNAPSHOT );
		}
	}

	/**
	 * Remove both cron hooks.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_PRUNE );
		wp_clear_scheduled_hook( self::CRON_SNAPSHOT );
	}
}
