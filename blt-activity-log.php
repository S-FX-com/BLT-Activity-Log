<?php
/**
 * Plugin Name:       BLT Activity Log
 * Plugin URI:        https://github.com/S-FX-com/BLT-Activity-Log
 * Description:       A record of what changed on your site: content, plugins, themes, settings, users and sign-ins, with who did it, when, and exactly which fields changed.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            S-FX.com
 * Author URI:        https://www.s-fx.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blt-activity-log
 * Domain Path:       /languages
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BLT_ACTIVITY_LOG_VERSION', '1.0.0' ); // Must equal the header. CI enforces this.
define( 'BLT_ACTIVITY_LOG_FILE', __FILE__ );
define( 'BLT_ACTIVITY_LOG_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLT_ACTIVITY_LOG_URL', plugin_dir_url( __FILE__ ) );
define( 'BLT_ACTIVITY_LOG_BASENAME', plugin_basename( __FILE__ ) );

// ---------- BLT family layer ----------
// Registered during load, not inside a hook, so the registry is complete before
// the library elects a copy on plugins_loaded and BLT_Family_Updates is
// available to the update-checker wiring below.
require_once BLT_ACTIVITY_LOG_DIR . 'includes/blt-family/bootstrap.php';

blt_family_register(
	BLT_ACTIVITY_LOG_FILE,
	array(
		'name'    => 'BLT Activity Log',
		'slug'    => 'blt-activity-log',
		'version' => BLT_ACTIVITY_LOG_VERSION,
		// The log lives under Tools, not a top-level menu, so the family screen
		// links to it with a relative admin URL (see blt_family_register()).
		'menu'    => 'tools.php?page=blt-activity-log',
		// No credentials are held, so no shared group applies. The repository is
		// public: update checks need no token.
		'groups'  => array(),
	)
);

// ---------- Update checker ----------
// Updates come from GitHub Releases: the CI-built zip with a stable
// blt-activity-log/ top-level folder, never a source zipball. Checks run from
// wp-admin, WP-Cron or WP-CLI only, so a front-end request never loads it.
if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require_once BLT_ACTIVITY_LOG_DIR . 'includes/lib/plugin-update-checker/plugin-update-checker.php';

	// The fourth argument MUST be 24: a checker built with 0 registers no
	// scheduler hooks and cannot be revived.
	$blt_activity_log_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/S-FX-com/BLT-Activity-Log/',
		BLT_ACTIVITY_LOG_FILE,
		'blt-activity-log',
		24
	);
	$blt_activity_log_update_checker->setBranch( 'main' );

	// Optional wp-config constant. Only raises the API rate limit, or keeps
	// updates working if the repository is ever made private.
	if ( defined( 'BLT_ACTIVITY_LOG_GITHUB_TOKEN' ) && is_string( BLT_ACTIVITY_LOG_GITHUB_TOKEN ) && '' !== BLT_ACTIVITY_LOG_GITHUB_TOKEN ) {
		$blt_activity_log_update_checker->setAuthentication( BLT_ACTIVITY_LOG_GITHUB_TOKEN );
	}

	// Only accept the CI-built zip asset; ignore checksums and source archives.
	$blt_activity_log_update_checker->getVcsApi()->enableReleaseAssets( '/^blt-activity-log(-[\d.]+)?\.zip$/i' );

	// One automatic check a day, anchored to midnight site time. Manual checks
	// always run immediately.
	BLT_Family_Updates::apply(
		$blt_activity_log_update_checker,
		array(
			'basename'  => BLT_ACTIVITY_LOG_BASENAME,
			'icons_url' => BLT_ACTIVITY_LOG_URL . 'assets/img/',
		)
	);
}

// ---------- Autoloader ----------
// Resolves BLT_Activity_Log to includes/class-blt-activity-log.php and
// BLT_Activity_Log_<Thing> to includes/class-blt-activity-log-<thing>.php,
// probing admin/ as a fallback. Adding a class means creating its file.
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'BLT_Activity_Log';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$rest = substr( $class_name, strlen( $prefix ) );

		if ( '' !== $rest && '_' !== $rest[0] ) {
			return;
		}

		$file = 'class-blt-activity-log' . strtolower( str_replace( '_', '-', $rest ) ) . '.php';

		foreach ( array( BLT_ACTIVITY_LOG_DIR . 'includes/' . $file, BLT_ACTIVITY_LOG_DIR . 'admin/' . $file ) as $path ) {
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

// ---------- Activation / deactivation ----------
register_activation_hook( __FILE__, array( 'BLT_Activity_Log_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BLT_Activity_Log_Install', 'deactivate' ) );

// ---------- i18n ----------
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'blt-activity-log', false, dirname( BLT_ACTIVITY_LOG_BASENAME ) . '/languages' );
	}
);

// ---------- Boot ----------
add_action( 'plugins_loaded', array( 'BLT_Activity_Log', 'boot' ), 1 );
