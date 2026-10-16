<?php
/**
 * Privacy policy text, personal-data exporter and eraser.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks the plugin into WordPress's privacy tools.
 *
 * The eraser deliberately does not delete audit entries: an activity log is a
 * security record. It blanks the IP address and browser on a person's entries
 * and says, in the request's own report, that the entries are retained.
 */
class BLT_Activity_Log_Privacy {

	/**
	 * Entries handled per exporter or eraser call.
	 */
	const BATCH = 100;

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'add_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Suggest text for the site's privacy policy.
	 *
	 * @return void
	 */
	public static function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$days = BLT_Activity_Log_Settings::retention_days();
		$mode = BLT_Activity_Log_Settings::get( 'ip_mode', 'full' );

		$text  = '<h2>' . esc_html__( 'Activity log', 'blt-activity-log' ) . '</h2>';
		$text .= '<p class="privacy-policy-tutorial">' . esc_html__( 'This text suggests what to tell visitors and users about the activity log kept by BLT Activity Log. Review it and edit it to match how you use the log.', 'blt-activity-log' ) . '</p>';
		$text .= '<p>' . esc_html__( 'We keep a record of changes made to this site: content, plugins, themes, settings and user accounts, and when people sign in. Each record notes who made the change, when, and from which screen.', 'blt-activity-log' ) . '</p>';

		if ( 'off' === $mode ) {
			$text .= '<p>' . esc_html__( 'The record does not include IP addresses.', 'blt-activity-log' ) . '</p>';
		} elseif ( 'anonymized' === $mode ) {
			$text .= '<p>' . esc_html__( 'The record includes IP addresses with their last part removed, and the browser used.', 'blt-activity-log' ) . '</p>';
		} else {
			$text .= '<p>' . esc_html__( 'The record includes the IP address and the browser used.', 'blt-activity-log' ) . '</p>';
		}

		if ( $days > 0 ) {
			$text .= '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of days entries are kept. */
					_n( 'Entries are deleted automatically after %d day.', 'Entries are deleted automatically after %d days.', $days, 'blt-activity-log' ),
					$days
				)
			) . '</p>';
		} else {
			$text .= '<p>' . esc_html__( 'Entries are kept until a site administrator clears them.', 'blt-activity-log' ) . '</p>';
		}

		wp_add_privacy_policy_content( 'BLT Activity Log', wp_kses_post( $text ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['blt-activity-log'] = array(
			'exporter_friendly_name' => __( 'BLT Activity Log', 'blt-activity-log' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['blt-activity-log'] = array(
			'eraser_friendly_name' => __( 'BLT Activity Log', 'blt-activity-log' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export the entries a person made: time, activity, item and IP address.
	 *
	 * @param string $email_address The person's email address.
	 * @param int    $page          Page of results, from 1.
	 * @return array{data:array,done:bool}
	 */
	public static function export( $email_address, $page = 1 ) {
		global $wpdb;

		$user = get_user_by( 'email', (string) $email_address );

		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$table  = BLT_Activity_Log_Query::table();
		$offset = ( max( 1, (int) $page ) - 1 ) * self::BATCH;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is ours; values are bound.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, category, action, object_label, ip FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d", (int) $user->ID, self::BATCH, $offset ) );
		$rows = is_array( $rows ) ? $rows : array();

		$items = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'blt-activity-log',
				'group_label' => __( 'Activity log', 'blt-activity-log' ),
				'item_id'     => 'blt-activity-log-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Time', 'blt-activity-log' ),
						'value' => BLT_Activity_Log_Formatter::site_time( (string) $row->created_at, 'Y-m-d H:i:s' ),
					),
					array(
						'name'  => __( 'Activity', 'blt-activity-log' ),
						'value' => BLT_Activity_Log_Formatter::category_label( (string) $row->category ) . ': ' . BLT_Activity_Log_Formatter::action_label( (string) $row->category, (string) $row->action ),
					),
					array(
						'name'  => __( 'Item', 'blt-activity-log' ),
						'value' => (string) $row->object_label,
					),
					array(
						'name'  => __( 'IP address', 'blt-activity-log' ),
						'value' => (string) $row->ip,
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < self::BATCH,
		);
	}

	/**
	 * Erase personal data: blank the IP address and browser on a person's
	 * entries. The entries themselves are kept, as a security record.
	 *
	 * @param string $email_address The person's email address.
	 * @param int    $page          Unused: each call removes what it finds.
	 * @return array
	 */
	public static function erase( $email_address, $page = 1 ) {
		global $wpdb;

		unset( $page );

		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		$user = get_user_by( 'email', (string) $email_address );

		if ( ! $user ) {
			return $result;
		}

		$table = BLT_Activity_Log_Query::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is ours; values are bound.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET ip = NULL, user_agent = NULL WHERE user_id = %d AND ( ip IS NOT NULL OR user_agent IS NOT NULL ) ORDER BY id LIMIT %d", (int) $user->ID, self::BATCH ) );
		$updated = false === $updated ? 0 : (int) $updated;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is ours; values are bound.
		$kept = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", (int) $user->ID ) );

		$result['items_removed']  = $updated > 0;
		$result['items_retained'] = $kept > 0;
		$result['done']           = $updated < self::BATCH;

		if ( $kept > 0 ) {
			$result['messages'][] = __( 'Activity log entries are kept as a record of site security. The IP address and browser were removed from them.', 'blt-activity-log' );
		}

		return $result;
	}
}
