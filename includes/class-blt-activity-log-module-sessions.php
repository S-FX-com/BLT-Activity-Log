<?php
/**
 * Module: sign-ins.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records sign-ins and sign-outs, and (when its own toggle is on) failed
 * sign-ins.
 *
 * Failed sign-ins are off by default because a brute-force run would flood the
 * table. Lockouts and rate limiting are BLT Secure's job, not this plugin's.
 */
class BLT_Activity_Log_Module_Sessions extends BLT_Activity_Log_Module_Base {

	/**
	 * Longest attempted username kept.
	 */
	const MAX_USERNAME = 60;

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'session';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return __( 'Sign-ins', 'blt-activity-log' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function description() {
		return __( 'People signing in and out.', 'blt-activity-log' );
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
		$this->listen( 'wp_login', 'on_login', 10, 2 );
		$this->listen( 'wp_logout', 'on_logout', 10, 1 );

		if ( BLT_Activity_Log_Settings::module_enabled( 'session_failed', false ) ) {
			$this->listen( 'wp_login_failed', 'on_failed', 10, 2 );
		}
	}

	/**
	 * Someone signed in.
	 *
	 * The current user is not set yet when this fires, so the actor comes from
	 * the user the hook hands over.
	 *
	 * @param string       $user_login Username.
	 * @param WP_User|null $user       The user.
	 * @return void
	 */
	public function on_login( $user_login, $user = null ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$this->record_for( 'logged_in', $user );
	}

	/**
	 * Someone signed out.
	 *
	 * WordPress clears the current user before this fires, so the user is
	 * looked up by the ID the hook passes.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function on_logout( $user_id = 0 ) {
		$user = get_userdata( (int) $user_id );

		if ( $user instanceof WP_User ) {
			$this->record_for( 'logged_out', $user );
		}
	}

	/**
	 * A sign-in failed.
	 *
	 * @param string        $username Username that was tried.
	 * @param WP_Error|null $error    Why it failed.
	 * @return void
	 */
	public function on_failed( $username, $error = null ) {
		unset( $error );

		list( $attempted ) = BLT_Activity_Log_Redactor::truncate( (string) $username, self::MAX_USERNAME );

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'session',
				'action'       => 'login_failed',
				'object_type'  => 'user',
				'object_key'   => $attempted,
				'object_label' => $attempted,
				'user_id'      => 0,
				'user_login'   => '',
				'user_role'    => '',
			)
		);
	}

	/**
	 * Record an entry whose actor is a given user.
	 *
	 * @param string  $action Action key.
	 * @param WP_User $user   The user.
	 * @return void
	 */
	private function record_for( $action, WP_User $user ) {
		$actor = BLT_Activity_Log_Context::describe_user( $user );

		BLT_Activity_Log_Logger::record(
			array_merge(
				array(
					'category'     => 'session',
					'action'       => $action,
					'object_type'  => 'user',
					'object_id'    => (int) $user->ID,
					'object_label' => (string) $user->user_login,
				),
				$actor
			)
		);
	}
}
