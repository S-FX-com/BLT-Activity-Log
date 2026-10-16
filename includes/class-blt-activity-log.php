<?php
/**
 * Plugin bootstrap.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the plugin on `plugins_loaded`: wires the modules, the cron jobs, the
 * privacy tools and the admin screen, and receives entries other plugins write
 * through `do_action( 'blt_activity_log_record', ... )`.
 */
class BLT_Activity_Log {

	/**
	 * Built-in categories. An outside plugin cannot write into these: they are
	 * what the log itself records, and an audit trail is only worth reading if
	 * nothing else can forge them.
	 */
	const BUILTIN_CATEGORIES = array( 'post', 'media', 'term', 'appearance', 'plugin', 'theme', 'core', 'setting', 'user', 'session', 'system', 'other' );

	/**
	 * Boot. Called on `plugins_loaded` priority 1.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::is_network_activated() ) {
			add_action( 'admin_notices', array( __CLASS__, 'network_notice' ) );
			add_action( 'network_admin_notices', array( __CLASS__, 'network_notice' ) );

			return;
		}

		// Idempotent schema upgrade for a plugin update by file copy, which
		// never fires the activation hook.
		add_action( 'init', array( 'BLT_Activity_Log_Install', 'maybe_upgrade' ), 0 );

		BLT_Activity_Log_Modules::boot();

		add_action( 'blt_activity_log_record', array( __CLASS__, 'record_external' ) );

		add_action( BLT_Activity_Log_Install::CRON_PRUNE, array( 'BLT_Activity_Log_Pruner', 'run' ) );
		add_action( BLT_Activity_Log_Pruner::HOOK_CONTINUE, array( 'BLT_Activity_Log_Pruner', 'continue_run' ), 10, 2 );
		add_action( BLT_Activity_Log_Install::CRON_SNAPSHOT, array( 'BLT_Activity_Log_Snapshot', 'run' ) );

		BLT_Activity_Log_Privacy::init();

		if ( is_admin() ) {
			BLT_Activity_Log_Admin::instance()->init();
		}
	}

	/**
	 * Write an entry on behalf of another plugin.
	 *
	 * Callers use `do_action()`, so they never need to check whether this
	 * plugin is installed:
	 *
	 *     do_action( 'blt_activity_log_record', array( 'category' => 'blt_secure', 'action' => 'waf_rule_deployed', ... ) );
	 *
	 * An unregistered category (or a built-in one) is filed under "Other", with
	 * the name the caller used kept in the details. The actor and context are
	 * always the ones detected for the request: a caller cannot choose them.
	 * The entry then goes through the same redaction, size caps and
	 * `blt_activity_log_should_log` filter as every other entry.
	 *
	 * @param array $args Entry: `category`, `action`, `object_type`, `object_id`,
	 *                    `object_key`, `object_label`, `details`.
	 * @return void
	 */
	public static function record_external( $args = array() ) {
		try {
			if ( ! is_array( $args ) ) {
				return;
			}

			$category = isset( $args['category'] ) ? sanitize_key( (string) $args['category'] ) : '';
			$details  = isset( $args['details'] ) && is_array( $args['details'] ) ? $args['details'] : array();

			if ( '' === $category || in_array( $category, self::BUILTIN_CATEGORIES, true ) || ! array_key_exists( $category, BLT_Activity_Log_Formatter::categories() ) ) {
				if ( '' !== $category ) {
					$details['external_category'] = $category;
				}

				$category = 'other';
			}

			BLT_Activity_Log_Logger::record(
				array(
					'category'     => $category,
					'action'       => isset( $args['action'] ) ? (string) $args['action'] : '',
					'object_type'  => isset( $args['object_type'] ) ? (string) $args['object_type'] : '',
					'object_id'    => isset( $args['object_id'] ) ? $args['object_id'] : null,
					'object_key'   => isset( $args['object_key'] ) ? (string) $args['object_key'] : null,
					'object_label' => isset( $args['object_label'] ) ? (string) $args['object_label'] : '',
					'details'      => $details,
				)
			);
		} catch ( \Throwable $e ) {
			BLT_Activity_Log_Logger::report( $e );
		}
	}

	/**
	 * Whether the plugin was network activated on a multisite install.
	 *
	 * Activation through the Plugins screen is refused, so this only happens
	 * when something else wrote the option. `active_sitewide_plugins` is read
	 * by core on every request, so this costs no query.
	 *
	 * @return bool
	 */
	public static function is_network_activated() {
		if ( ! is_multisite() ) {
			return false;
		}

		$sitewide = get_site_option( 'active_sitewide_plugins', array() );

		return is_array( $sitewide ) && isset( $sitewide[ BLT_ACTIVITY_LOG_BASENAME ] );
	}

	/**
	 * Explain why a network-activated copy does nothing.
	 *
	 * @return void
	 */
	public static function network_notice() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_network_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'BLT Activity Log does not support network activation, so it is not recording anything. Deactivate it for the network and activate it on each site you want to log.', 'blt-activity-log' ) . '</p></div>';
	}
}
