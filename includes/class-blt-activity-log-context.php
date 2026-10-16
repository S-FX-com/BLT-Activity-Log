<?php
/**
 * Who did it, and from where.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the actor, the request context, the client IP and the user agent
 * for an entry, and snapshots them: logins and roles can change or vanish
 * later, so the log stores them as they were.
 */
class BLT_Activity_Log_Context {

	/**
	 * Every value `context` can take.
	 */
	const CONTEXTS = array( 'cli', 'cron', 'rest', 'ajax', 'xmlrpc', 'login', 'admin', 'front' );

	/**
	 * The signed-in user, as stored with an entry.
	 *
	 * Pluggable functions are not loaded until after plugins, so an entry made
	 * before that has no user.
	 *
	 * @return array{user_id:int,user_login:string,user_role:string}
	 */
	public static function actor() {
		$none = array(
			'user_id'    => 0,
			'user_login' => '',
			'user_role'  => '',
		);

		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return $none;
		}

		$user = wp_get_current_user();

		if ( ! $user || empty( $user->ID ) ) {
			return $none;
		}

		return self::describe_user( $user );
	}

	/**
	 * A user object as the stored actor snapshot.
	 *
	 * @param WP_User $user User.
	 * @return array{user_id:int,user_login:string,user_role:string}
	 */
	public static function describe_user( $user ) {
		$roles = isset( $user->roles ) && is_array( $user->roles ) ? array_values( $user->roles ) : array();

		return array(
			'user_id'    => (int) $user->ID,
			'user_login' => (string) $user->user_login,
			'user_role'  => isset( $roles[0] ) ? (string) $roles[0] : '',
		);
	}

	/**
	 * Where the request came from.
	 *
	 * Checked in order: WP-CLI, cron, REST, AJAX, XML-RPC, sign-in, admin,
	 * front end. Automatic updates are `cron`; the module that logs them marks
	 * `details.method = automatic`.
	 *
	 * @return string One of self::CONTEXTS.
	 */
	public static function request_context() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return 'cron';
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return 'ajax';
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}

		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return 'login';
		}

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return 'admin';
		}

		return 'front';
	}

	/**
	 * The client IP as it should be stored, per the IP setting.
	 *
	 * @return string|null Null when the setting is off or no address is known.
	 */
	public static function ip() {
		$mode = BLT_Activity_Log_Settings::get( 'ip_mode', 'full' );

		if ( 'off' === $mode ) {
			return null;
		}

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$cf_ip  = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : '';

		$ip = BLT_Activity_Log_Ip::resolve( $remote, $cf_ip );

		/**
		 * Filter the client IP before it is stored.
		 *
		 * @param string $ip     Detected IP ('' when none).
		 * @param string $remote REMOTE_ADDR.
		 * @param string $cf_ip  CF-Connecting-IP header value.
		 */
		$ip = apply_filters( 'blt_activity_log_client_ip', $ip, $remote, $cf_ip );

		if ( ! is_string( $ip ) || '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		if ( 'anonymized' === $mode && function_exists( 'wp_privacy_anonymize_ip' ) ) {
			$ip = wp_privacy_anonymize_ip( $ip );
		}

		return substr( $ip, 0, 45 );
	}

	/**
	 * The browser user agent, shortened.
	 *
	 * @return string|null Null when the setting is off or none was sent.
	 */
	public static function user_agent() {
		if ( ! BLT_Activity_Log_Settings::get( 'store_user_agent', true ) ) {
			return null;
		}

		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return null;
		}

		$agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );

		list( $agent ) = BLT_Activity_Log_Redactor::truncate( $agent, 255 );

		return '' === $agent ? null : $agent;
	}

	/**
	 * Everything about the current request that an entry stores.
	 *
	 * @param array $override Fields to use instead of the detected ones
	 *                        (`user_id`, `user_login`, `user_role`, `context`).
	 * @return array
	 */
	public static function snapshot( array $override = array() ) {
		$actor = isset( $override['user_id'] )
			? array(
				'user_id'    => (int) $override['user_id'],
				'user_login' => isset( $override['user_login'] ) ? (string) $override['user_login'] : '',
				'user_role'  => isset( $override['user_role'] ) ? (string) $override['user_role'] : '',
			)
			: self::actor();

		return array_merge(
			$actor,
			array(
				'context'    => isset( $override['context'] ) ? (string) $override['context'] : self::request_context(),
				'ip'         => self::ip(),
				'user_agent' => self::user_agent(),
			)
		);
	}
}
