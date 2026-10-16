<?php
/**
 * Plugin, theme and core version snapshot, and out-of-band change detection.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remembers the installed versions so that "from" versions are known, and so
 * that a change nobody logged (a Composer deploy on Bedrock, an FTP upload, a
 * site with `DISALLOW_FILE_MODS`) still shows up as `changed_externally`.
 *
 * The snapshot uses `get_plugins()`, which reads every plugin's header, so it
 * runs only in cron, on activation and after an upgrade. Never on page load.
 */
class BLT_Activity_Log_Snapshot {

	/**
	 * Option holding the snapshot (autoload no).
	 */
	const OPTION = 'blt_activity_log_snapshot';

	/**
	 * What is installed right now.
	 *
	 * @param array $override Values to use instead of the detected ones
	 *                        (`core`), for the moment a core update has just
	 *                        replaced files but the running request still holds
	 *                        the old version.
	 * @return array{core:string,plugins:array<string,string>,themes:array<string,string>}
	 */
	public static function current( array $override = array() ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = array();

		foreach ( get_plugins() as $file => $data ) {
			$plugins[ $file ] = isset( $data['Version'] ) ? (string) $data['Version'] : '';
		}

		$themes = array();

		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$themes[ $stylesheet ] = (string) $theme->get( 'Version' );
		}

		return array(
			'core'    => isset( $override['core'] ) ? (string) $override['core'] : (string) get_bloginfo( 'version' ),
			'plugins' => $plugins,
			'themes'  => $themes,
		);
	}

	/**
	 * The stored snapshot, with every key present.
	 *
	 * @return array{core:string,plugins:array<string,string>,themes:array<string,string>}
	 */
	public static function stored() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'core'    => isset( $stored['core'] ) ? (string) $stored['core'] : '',
			'plugins' => isset( $stored['plugins'] ) && is_array( $stored['plugins'] ) ? $stored['plugins'] : array(),
			'themes'  => isset( $stored['themes'] ) && is_array( $stored['themes'] ) ? $stored['themes'] : array(),
		);
	}

	/**
	 * Re-read what is installed and store it.
	 *
	 * Called after every logged plugin, theme or core change, so only a change
	 * nobody logged is left to find.
	 *
	 * @param array $override See current().
	 * @return array The snapshot stored.
	 */
	public static function refresh( array $override = array() ) {
		$snapshot = self::current( $override );

		update_option( self::OPTION, $snapshot, false );

		return $snapshot;
	}

	/**
	 * What differs between two snapshots. Pure.
	 *
	 * @param array $stored  Earlier snapshot.
	 * @param array $current Later snapshot.
	 * @return array[] Each: `kind` (core|plugin|theme), `change` (added|removed|version),
	 *                 `key`, `from`, `to`.
	 */
	public static function diff( array $stored, array $current ) {
		$changes = array();

		$old_core = isset( $stored['core'] ) ? (string) $stored['core'] : '';
		$new_core = isset( $current['core'] ) ? (string) $current['core'] : '';

		// An empty stored core means "no snapshot yet": nothing to compare to.
		if ( '' !== $old_core && '' !== $new_core && $old_core !== $new_core ) {
			$changes[] = array(
				'kind'   => 'core',
				'change' => 'version',
				'key'    => 'wordpress',
				'from'   => $old_core,
				'to'     => $new_core,
			);
		}

		foreach ( array(
			'plugin' => 'plugins',
			'theme'  => 'themes',
		) as $kind => $bucket ) {
			$old = isset( $stored[ $bucket ] ) && is_array( $stored[ $bucket ] ) ? $stored[ $bucket ] : array();
			$new = isset( $current[ $bucket ] ) && is_array( $current[ $bucket ] ) ? $current[ $bucket ] : array();

			foreach ( $new as $key => $version ) {
				if ( ! array_key_exists( $key, $old ) ) {
					$changes[] = array(
						'kind'   => $kind,
						'change' => 'added',
						'key'    => (string) $key,
						'from'   => '',
						'to'     => (string) $version,
					);
				} elseif ( (string) $old[ $key ] !== (string) $version ) {
					$changes[] = array(
						'kind'   => $kind,
						'change' => 'version',
						'key'    => (string) $key,
						'from'   => (string) $old[ $key ],
						'to'     => (string) $version,
					);
				}
			}

			foreach ( $old as $key => $version ) {
				if ( ! array_key_exists( $key, $new ) ) {
					$changes[] = array(
						'kind'   => $kind,
						'change' => 'removed',
						'key'    => (string) $key,
						'from'   => (string) $version,
						'to'     => '',
					);
				}
			}
		}

		return $changes;
	}

	/**
	 * Daily cron: log what changed outside WordPress, then re-store.
	 *
	 * @return void
	 */
	public static function run() {
		try {
			$stored  = self::stored();
			$current = self::current();

			// First run, or the option was lost: take the baseline, report nothing.
			if ( '' !== $stored['core'] ) {
				foreach ( self::diff( $stored, $current ) as $change ) {
					self::record_external_change( $change );
				}
			}

			update_option( self::OPTION, $current, false );
		} catch ( \Throwable $e ) {
			BLT_Activity_Log_Logger::report( $e );
		}
	}

	/**
	 * Log one out-of-band change.
	 *
	 * @param array $change One item from diff().
	 * @return void
	 */
	private static function record_external_change( array $change ) {
		$label = $change['key'];

		if ( 'plugin' === $change['kind'] ) {
			$plugins = get_plugins();

			if ( isset( $plugins[ $change['key'] ]['Name'] ) ) {
				$label = (string) $plugins[ $change['key'] ]['Name'];
			}
		} elseif ( 'theme' === $change['kind'] ) {
			$theme = wp_get_theme( $change['key'] );

			if ( $theme->exists() ) {
				$label = (string) $theme->get( 'Name' );
			}
		} else {
			$label = 'WordPress';
		}

		$details = array(
			'versions' => array(
				'from' => $change['from'],
				'to'   => $change['to'],
			),
			'method'   => 'external',
			'change'   => $change['change'],
		);

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => $change['kind'],
				'action'       => 'changed_externally',
				'object_type'  => $change['kind'],
				'object_key'   => $change['key'],
				'object_label' => $label,
				'details'      => $details,
				'user_id'      => 0,
				'user_login'   => '',
				'user_role'    => '',
				'context'      => 'cron',
			)
		);
	}
}
