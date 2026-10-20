<?php
/**
 * Shared behaviour for the built-in modules.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for the built-in modules.
 *
 * Its one job is `listen()`: every listener a module hooks is wrapped in a
 * try/catch, so a fault while recording can never break the save, update or
 * sign-in that triggered it (CLAUDE.md 4.3, rule 1).
 */
abstract class BLT_Activity_Log_Module_Base implements BLT_Activity_Log_Module {

	/**
	 * Hook a method of this module, wrapped so it can never throw.
	 *
	 * @param string $hook          Action name.
	 * @param string $method        Method on this object to call.
	 * @param int    $priority      Hook priority.
	 * @param int    $accepted_args Number of arguments the hook passes along.
	 * @return void
	 */
	protected function listen( $hook, $method, $priority = 10, $accepted_args = 1 ) {
		add_action(
			$hook,
			function () use ( $method ) {
				try {
					call_user_func_array( array( $this, $method ), func_get_args() );
				} catch ( \Throwable $e ) {
					BLT_Activity_Log_Logger::report( $e );
				}
			},
			$priority,
			$accepted_args
		);
	}

	/**
	 * Whether the current request is a visitor's, not an editor's.
	 *
	 * A page view does not edit content, nor does an anonymous AJAX or REST
	 * call (a view counter, a voting widget). A visit that bumps a counter in
	 * post meta, or touches terms, is not an edit, and logging it would turn the
	 * log into a page-view recorder. Cron, WP-CLI and signed-in requests are not
	 * visitors': an import or a script changes content for real.
	 *
	 * @return bool
	 */
	protected function is_front_request() {
		$context = BLT_Activity_Log_Context::request_context();

		if ( 'front' === $context ) {
			return true;
		}

		return in_array( $context, array( 'ajax', 'rest', 'xmlrpc' ), true ) && 0 === (int) get_current_user_id();
	}

	/**
	 * How an install or update was started.
	 *
	 * @param object|null $upgrader The upgrader that ran, when there is one.
	 * @return string One of `cli`, `automatic`, `upload`, `screen`.
	 */
	protected function update_method( $upgrader = null ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}

		$skin = ( is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) ) ? $upgrader->skin : null;

		// WP_Ajax_Upgrader_Skin (the "Update now" links) EXTENDS
		// Automatic_Upgrader_Skin, so it has to be ruled out first or every
		// update started by a person would read as automatic.
		if ( $skin && class_exists( 'WP_Ajax_Upgrader_Skin', false ) && $skin instanceof WP_Ajax_Upgrader_Skin ) {
			return 'screen';
		}

		if ( $skin && class_exists( 'Automatic_Upgrader_Skin', false ) && $skin instanceof Automatic_Upgrader_Skin ) {
			return 'automatic';
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return 'automatic';
		}

		// The installer skins record whether the package was uploaded or fetched.
		if ( $skin && isset( $skin->type ) && 'upload' === $skin->type ) {
			return 'upload';
		}

		return 'screen';
	}
}
