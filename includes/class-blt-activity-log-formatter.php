<?php
/**
 * Turns a stored entry into translated text, at render time.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entries store keys, snapshots and raw values; this class produces every
 * sentence, label and summary a person reads. Nothing it returns is stored, so
 * the text is translated into the viewer's language and stays correct when a
 * post is renamed or a user deleted. All output is plain text: callers escape.
 */
class BLT_Activity_Log_Formatter {

	/**
	 * Most fields named in a one-line summary before "+N more".
	 */
	const SUMMARY_FIELDS = 3;

	/**
	 * Decode the details JSON of a stored row.
	 *
	 * @param object|array $row Stored row.
	 * @return array
	 */
	public static function details( $row ) {
		$raw = is_object( $row ) ? ( isset( $row->details ) ? $row->details : '' ) : ( isset( $row['details'] ) ? $row['details'] : '' );

		if ( is_array( $raw ) ) {
			return self::normalize_details( $raw );
		}

		$decoded = '' !== (string) $raw ? json_decode( (string) $raw, true ) : array();

		return self::normalize_details( $decoded );
	}

	/**
	 * Bring decoded details into the documented shape (CLAUDE.md 5.2).
	 *
	 * Other plugins write entries through `blt_activity_log_record`, and any
	 * row can outlive the code that wrote it, so the screens never assume the
	 * shape: what does not fit is dropped here, once, instead of being guarded
	 * against at every use.
	 *
	 * @param mixed $details Decoded JSON.
	 * @return array
	 */
	public static function normalize_details( $details ) {
		if ( ! is_array( $details ) ) {
			return array();
		}

		$out = $details;

		if ( isset( $out['changes'] ) ) {
			$out['changes'] = self::normalize_changes( $out['changes'] );

			if ( empty( $out['changes'] ) ) {
				unset( $out['changes'] );
			}
		}

		foreach ( array( 'summary', 'method', 'via' ) as $key ) {
			if ( isset( $out[ $key ] ) ) {
				if ( is_scalar( $out[ $key ] ) ) {
					$out[ $key ] = (string) $out[ $key ];
				} else {
					unset( $out[ $key ] );
				}
			}
		}

		if ( isset( $out['changes_omitted'] ) ) {
			$out['changes_omitted'] = is_numeric( $out['changes_omitted'] ) ? (int) $out['changes_omitted'] : 0;
		}

		if ( isset( $out['versions'] ) ) {
			if ( is_array( $out['versions'] ) ) {
				$out['versions'] = array(
					'from' => isset( $out['versions']['from'] ) && is_scalar( $out['versions']['from'] ) ? (string) $out['versions']['from'] : '',
					'to'   => isset( $out['versions']['to'] ) && is_scalar( $out['versions']['to'] ) ? (string) $out['versions']['to'] : '',
				);
			} else {
				unset( $out['versions'] );
			}
		}

		if ( isset( $out['terms'] ) ) {
			$out['terms'] = self::normalize_terms( $out['terms'] );

			if ( empty( $out['terms'] ) ) {
				unset( $out['terms'] );
			}
		}

		if ( isset( $out['content'] ) ) {
			$out['content'] = self::normalize_summary( $out['content'], array( 'blocks_added', 'blocks_removed' ) );

			if ( isset( $out['content']['chars'] ) ) {
				$chars = $out['content']['chars'];

				if ( is_array( $chars ) && 2 === count( $chars ) ) {
					$chars = array_values( $chars );

					$out['content']['chars'] = array( is_numeric( $chars[0] ) ? (int) $chars[0] : 0, is_numeric( $chars[1] ) ? (int) $chars[1] : 0 );
				} else {
					unset( $out['content']['chars'] );
				}
			}

			if ( isset( $out['content']['revision_id'] ) ) {
				if ( is_numeric( $out['content']['revision_id'] ) && (int) $out['content']['revision_id'] > 0 ) {
					$out['content']['revision_id'] = (int) $out['content']['revision_id'];
				} else {
					unset( $out['content']['revision_id'] );
				}
			}
		}

		if ( isset( $out['bricks'] ) ) {
			$out['bricks'] = self::normalize_summary( $out['bricks'], array( 'elements_added', 'elements_removed' ) );
		}

		return $out;
	}

	/**
	 * A list of change items with only the parts that can be shown.
	 *
	 * @param mixed $changes Decoded `changes`.
	 * @return array
	 */
	private static function normalize_changes( $changes ) {
		if ( ! is_array( $changes ) ) {
			return array();
		}

		$clean = array();

		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) || ! isset( $change['field'] ) || ! is_scalar( $change['field'] ) || '' === (string) $change['field'] ) {
				continue;
			}

			$item = array( 'field' => (string) $change['field'] );

			foreach ( array( 'before', 'after' ) as $side ) {
				if ( isset( $change[ $side ] ) ) {
					$item[ $side ] = BLT_Activity_Log_Diff::to_string( $change[ $side ] );
				}
			}

			foreach ( array( 'redacted', 'order_changed', 'edited' ) as $flag ) {
				if ( ! empty( $change[ $flag ] ) ) {
					$item[ $flag ] = true;
				}
			}

			if ( isset( $change['truncated'] ) && is_array( $change['truncated'] ) ) {
				$item['truncated'] = array_map( 'intval', array_filter( $change['truncated'], 'is_numeric' ) );
			}

			$clean[] = $item;
		}

		return $clean;
	}

	/**
	 * Taxonomy => added and removed names.
	 *
	 * @param mixed $terms Decoded `terms`.
	 * @return array<string,array{added:string[],removed:string[]}>
	 */
	private static function normalize_terms( $terms ) {
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$clean = array();

		foreach ( $terms as $taxonomy => $moved ) {
			if ( ! is_array( $moved ) ) {
				continue;
			}

			$entry = array(
				'added'   => array(),
				'removed' => array(),
			);

			foreach ( array( 'added', 'removed' ) as $side ) {
				if ( isset( $moved[ $side ] ) && is_array( $moved[ $side ] ) ) {
					$entry[ $side ] = array_values( array_map( 'strval', array_filter( $moved[ $side ], 'is_scalar' ) ) );
				}
			}

			$clean[ (string) $taxonomy ] = $entry;
		}

		return $clean;
	}

	/**
	 * A content or Bricks summary: histograms of name => count, anything else kept.
	 *
	 * @param mixed    $summary    Decoded summary.
	 * @param string[] $histograms Keys that hold name => count maps.
	 * @return array
	 */
	private static function normalize_summary( $summary, array $histograms ) {
		if ( ! is_array( $summary ) ) {
			return array();
		}

		foreach ( $histograms as $key ) {
			if ( ! isset( $summary[ $key ] ) ) {
				continue;
			}

			if ( ! is_array( $summary[ $key ] ) ) {
				unset( $summary[ $key ] );

				continue;
			}

			$map = array();

			foreach ( $summary[ $key ] as $name => $count ) {
				if ( is_numeric( $count ) ) {
					$map[ (string) $name ] = (int) $count;
				}
			}

			$summary[ $key ] = $map;
		}

		return $summary;
	}

	/**
	 * A UTC database time in the site's timezone.
	 *
	 * @param string $utc    `Y-m-d H:i:s` in UTC.
	 * @param string $format Date format; the site's date and time format when omitted.
	 * @return string
	 */
	public static function site_time( $utc, $format = '' ) {
		$timestamp = strtotime( (string) $utc . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		if ( '' === $format ) {
			$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		}

		return wp_date( $format, $timestamp );
	}

	/**
	 * "12 minutes ago" for a UTC database time.
	 *
	 * @param string $utc `Y-m-d H:i:s` in UTC.
	 * @return string
	 */
	public static function relative_time( $utc ) {
		$timestamp = strtotime( (string) $utc . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		/* translators: %s: a length of time, such as "5 minutes". */
		return sprintf( __( '%s ago', 'blt-activity-log' ), human_time_diff( $timestamp, time() ) );
	}

	/**
	 * Every category and its label, built-in and registered by other plugins.
	 *
	 * @return array<string,string>
	 */
	public static function categories() {
		$categories = array(
			'post'       => __( 'Posts & pages', 'blt-activity-log' ),
			'media'      => __( 'Media', 'blt-activity-log' ),
			'term'       => __( 'Categories & tags', 'blt-activity-log' ),
			'appearance' => __( 'Menus, widgets & Customizer', 'blt-activity-log' ),
			'plugin'     => __( 'Plugins', 'blt-activity-log' ),
			'theme'      => __( 'Themes', 'blt-activity-log' ),
			'core'       => __( 'WordPress updates', 'blt-activity-log' ),
			'setting'    => __( 'Settings', 'blt-activity-log' ),
			'user'       => __( 'Users', 'blt-activity-log' ),
			'session'    => __( 'Sign-ins', 'blt-activity-log' ),
			'system'     => __( 'BLT Activity Log', 'blt-activity-log' ),
			'other'      => __( 'Other', 'blt-activity-log' ),
		);

		/**
		 * Register categories for entries written by other plugins.
		 *
		 * @param array<string,string> $categories Category key => label.
		 */
		$filtered = apply_filters( 'blt_activity_log_categories', $categories );

		return is_array( $filtered ) ? $filtered : $categories;
	}

	/**
	 * A category's label.
	 *
	 * @param string $category Category key.
	 * @return string
	 */
	public static function category_label( $category ) {
		$categories = self::categories();

		return isset( $categories[ $category ] ) ? (string) $categories[ $category ] : self::humanize( $category );
	}

	/**
	 * The actions each built-in category records, with their labels.
	 *
	 * @param string $category Category key.
	 * @return array<string,string> Action key => label.
	 */
	public static function actions( $category ) {
		$created   = __( 'Created', 'blt-activity-log' );
		$updated   = __( 'Updated', 'blt-activity-log' );
		$deleted   = __( 'Deleted', 'blt-activity-log' );
		$installed = __( 'Installed', 'blt-activity-log' );
		$auto_on   = __( 'Auto-updates enabled', 'blt-activity-log' );
		$auto_off  = __( 'Auto-updates disabled', 'blt-activity-log' );
		$external  = __( 'Changed outside WordPress', 'blt-activity-log' );

		$map = array(
			'post'       => array(
				'created'      => $created,
				'updated'      => $updated,
				'published'    => __( 'Published', 'blt-activity-log' ),
				'unpublished'  => __( 'Unpublished', 'blt-activity-log' ),
				'scheduled'    => __( 'Scheduled', 'blt-activity-log' ),
				'made_private' => __( 'Made private', 'blt-activity-log' ),
				'trashed'      => __( 'Trashed', 'blt-activity-log' ),
				'restored'     => __( 'Restored', 'blt-activity-log' ),
				'deleted'      => $deleted,
			),
			'media'      => array(
				'uploaded' => __( 'Uploaded', 'blt-activity-log' ),
				'updated'  => $updated,
				'deleted'  => $deleted,
			),
			'term'       => array(
				'created' => $created,
				'updated' => $updated,
				'deleted' => $deleted,
			),
			'appearance' => array(
				'menu_created'           => __( 'Menu created', 'blt-activity-log' ),
				'menu_updated'           => __( 'Menu updated', 'blt-activity-log' ),
				'menu_deleted'           => __( 'Menu deleted', 'blt-activity-log' ),
				'menu_locations_changed' => __( 'Menu locations changed', 'blt-activity-log' ),
				'widgets_changed'        => __( 'Widgets changed', 'blt-activity-log' ),
				'customizer_published'   => __( 'Customizer published', 'blt-activity-log' ),
			),
			'plugin'     => array(
				'installed'             => $installed,
				'updated'               => $updated,
				'activated'             => __( 'Activated', 'blt-activity-log' ),
				'deactivated'           => __( 'Deactivated', 'blt-activity-log' ),
				'deleted'               => $deleted,
				'auto_updates_enabled'  => $auto_on,
				'auto_updates_disabled' => $auto_off,
				'changed_externally'    => $external,
			),
			'theme'      => array(
				'installed'             => $installed,
				'updated'               => $updated,
				'switched'              => __( 'Switched', 'blt-activity-log' ),
				'deleted'               => $deleted,
				'auto_updates_enabled'  => $auto_on,
				'auto_updates_disabled' => $auto_off,
				'changed_externally'    => $external,
			),
			'core'       => array(
				'updated'            => $updated,
				'changed_externally' => $external,
			),
			'setting'    => array(
				'updated'                      => $updated,
				'deleted'                      => $deleted,
				'admin_email_change_requested' => __( 'Admin email change requested', 'blt-activity-log' ),
			),
			'user'       => array(
				'created'              => $created,
				'updated'              => $updated,
				'password_changed'     => __( 'Password changed', 'blt-activity-log' ),
				'password_reset'       => __( 'Password reset', 'blt-activity-log' ),
				'role_changed'         => __( 'Role changed', 'blt-activity-log' ),
				'deleted'              => $deleted,
				'app_password_created' => __( 'Application password created', 'blt-activity-log' ),
				'app_password_revoked' => __( 'Application password revoked', 'blt-activity-log' ),
			),
			'session'    => array(
				'logged_in'    => __( 'Signed in', 'blt-activity-log' ),
				'logged_out'   => __( 'Signed out', 'blt-activity-log' ),
				'login_failed' => __( 'Failed sign-in', 'blt-activity-log' ),
			),
			'system'     => array(
				'logging_started' => __( 'Logging started', 'blt-activity-log' ),
				'log_cleared'     => __( 'Log cleared', 'blt-activity-log' ),
				'exported'        => __( 'Exported', 'blt-activity-log' ),
			),
		);

		return isset( $map[ $category ] ) ? $map[ $category ] : array();
	}

	/**
	 * An action's label.
	 *
	 * @param string $category Category key.
	 * @param string $action   Action key.
	 * @return string
	 */
	public static function action_label( $category, $action ) {
		$actions = self::actions( $category );

		return isset( $actions[ $action ] ) ? $actions[ $action ] : self::humanize( $action );
	}

	/**
	 * Who did it, as a person reads it.
	 *
	 * @param object|array $row Stored row.
	 * @return string
	 */
	public static function actor_label( $row ) {
		$user_id = (int) self::field( $row, 'user_id' );
		$login   = (string) self::field( $row, 'user_login' );

		if ( $user_id > 0 && '' !== $login ) {
			return $login;
		}

		return 'cli' === self::field( $row, 'context' ) ? __( 'WP-CLI', 'blt-activity-log' ) : __( 'System', 'blt-activity-log' );
	}

	/**
	 * A role's display name, translated, falling back to its tidied slug.
	 *
	 * @param string $slug Role slug.
	 * @return string
	 */
	public static function role_label( $slug ) {
		$slug = (string) $slug;

		if ( '' === $slug ) {
			return '';
		}

		$names = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();

		if ( isset( $names[ $slug ] ) && function_exists( 'translate_user_role' ) ) {
			return (string) translate_user_role( $names[ $slug ] );
		}

		return self::humanize( $slug );
	}

	/**
	 * The entry's source: the screen or channel it came from.
	 *
	 * @param string $context Context key.
	 * @return string
	 */
	public static function context_label( $context ) {
		$labels = array(
			'cli'    => __( 'WP-CLI', 'blt-activity-log' ),
			'cron'   => __( 'WP-Cron', 'blt-activity-log' ),
			'rest'   => __( 'REST API', 'blt-activity-log' ),
			'ajax'   => __( 'AJAX', 'blt-activity-log' ),
			'xmlrpc' => __( 'XML-RPC', 'blt-activity-log' ),
			'login'  => __( 'Sign-in page', 'blt-activity-log' ),
			'admin'  => __( 'Admin screen', 'blt-activity-log' ),
			'front'  => __( 'Front end', 'blt-activity-log' ),
		);

		return isset( $labels[ $context ] ) ? $labels[ $context ] : self::humanize( $context );
	}

	/**
	 * What kind of thing the entry is about, in lower case ("page", "plugin").
	 *
	 * @param object|array $row Stored row.
	 * @return string
	 */
	public static function type_label( $row ) {
		$type     = (string) self::field( $row, 'object_type' );
		$category = (string) self::field( $row, 'category' );

		if ( 'post' === $category || 'media' === $category ) {
			$object = get_post_type_object( $type );

			if ( $object && isset( $object->labels->singular_name ) ) {
				return self::lower( (string) $object->labels->singular_name );
			}

			return 'media' === $category ? __( 'file', 'blt-activity-log' ) : self::humanize( $type );
		}

		if ( 'term' === $category ) {
			$taxonomy = get_taxonomy( $type );

			if ( $taxonomy && isset( $taxonomy->labels->singular_name ) ) {
				return self::lower( (string) $taxonomy->labels->singular_name );
			}
		}

		$fixed = array(
			'plugin'     => __( 'plugin', 'blt-activity-log' ),
			'theme'      => __( 'theme', 'blt-activity-log' ),
			'core'       => __( 'WordPress', 'blt-activity-log' ),
			'user'       => __( 'user', 'blt-activity-log' ),
			'option'     => __( 'setting', 'blt-activity-log' ),
			'nav_menu'   => __( 'menu', 'blt-activity-log' ),
			'sidebars'   => __( 'widgets', 'blt-activity-log' ),
			'theme_mods' => __( 'theme settings', 'blt-activity-log' ),
		);

		return isset( $fixed[ $type ] ) ? $fixed[ $type ] : self::humanize( $type );
	}

	/**
	 * The item an entry is about, as shown in the table.
	 *
	 * @param object|array $row Stored row.
	 * @return string
	 */
	public static function item_label( $row ) {
		$label = (string) self::field( $row, 'object_label' );
		$type  = (string) self::field( $row, 'object_type' );
		$key   = (string) self::field( $row, 'object_key' );

		if ( 'option' === $type ) {
			return self::field_label( '' !== $key ? $key : $label );
		}

		if ( 'sidebars' === $type ) {
			return __( 'Widgets', 'blt-activity-log' );
		}

		if ( '' !== $label ) {
			return $label;
		}

		if ( '' !== $key ) {
			return $key;
		}

		$category = (string) self::field( $row, 'category' );

		if ( 'post' === $category || 'media' === $category || 'term' === $category ) {
			return __( '(no title)', 'blt-activity-log' );
		}

		return '';
	}

	/**
	 * The sentence at the top of an entry: "shane updated page “About Us”".
	 *
	 * @param object|array $row Stored row.
	 * @return string
	 */
	public static function headline( $row ) {
		$category = (string) self::field( $row, 'category' );
		$action   = (string) self::field( $row, 'action' );
		$details  = self::details( $row );
		$actor    = self::actor_label( $row );
		$type     = self::type_label( $row );
		$item     = self::item_label( $row );

		if ( 'other' === $category || ! self::has_template( $category, $action ) ) {
			if ( '' !== (string) ( isset( $details['summary'] ) ? $details['summary'] : '' ) ) {
				return (string) $details['summary'];
			}

			/* translators: 1: who, 2: action, 3: item. */
			return sprintf( __( '%1$s: %2$s %3$s', 'blt-activity-log' ), $actor, self::action_label( $category, $action ), $item );
		}

		// A visitor who registers has no signed-in user; say so plainly.
		if ( 'user' === $category && 'created' === $action && (int) self::field( $row, 'user_id' ) <= 0 ) {
			/* translators: %s: the new account's username. */
			return sprintf( __( '%s registered an account', 'blt-activity-log' ), $item );
		}

		return sprintf( self::template( $category, $action ), $actor, $type, $item );
	}

	/**
	 * A field's label.
	 *
	 * @param string $field Field key, option name or key path.
	 * @return string
	 */
	public static function field_label( $field ) {
		$field = (string) $field;

		$known = array(
			// Posts.
			'post_title'                 => __( 'Title', 'blt-activity-log' ),
			'post_name'                  => __( 'Slug', 'blt-activity-log' ),
			'post_status'                => __( 'Status', 'blt-activity-log' ),
			'post_author'                => __( 'Author', 'blt-activity-log' ),
			'post_parent'                => __( 'Parent', 'blt-activity-log' ),
			'menu_order'                 => __( 'Order', 'blt-activity-log' ),
			'post_excerpt'               => __( 'Excerpt', 'blt-activity-log' ),
			'comment_status'             => __( 'Comments', 'blt-activity-log' ),
			'ping_status'                => __( 'Pingbacks', 'blt-activity-log' ),
			'post_password'              => __( 'Password', 'blt-activity-log' ),
			'post_date'                  => __( 'Date', 'blt-activity-log' ),
			// Media.
			'title'                      => __( 'Title', 'blt-activity-log' ),
			'caption'                    => __( 'Caption', 'blt-activity-log' ),
			'description'                => __( 'Description', 'blt-activity-log' ),
			'alt_text'                   => __( 'Alt text', 'blt-activity-log' ),
			'file'                       => __( 'File', 'blt-activity-log' ),
			'mime_type'                  => __( 'File type', 'blt-activity-log' ),
			'file_size'                  => __( 'File size', 'blt-activity-log' ),
			// Terms.
			'name'                       => __( 'Name', 'blt-activity-log' ),
			'slug'                       => __( 'Slug', 'blt-activity-log' ),
			'parent'                     => __( 'Parent', 'blt-activity-log' ),
			// Users.
			'user_email'                 => __( 'Email', 'blt-activity-log' ),
			'display_name'               => __( 'Display name', 'blt-activity-log' ),
			'user_nicename'              => __( 'Profile URL name', 'blt-activity-log' ),
			'user_url'                   => __( 'Website', 'blt-activity-log' ),
			'first_name'                 => __( 'First name', 'blt-activity-log' ),
			'last_name'                  => __( 'Last name', 'blt-activity-log' ),
			'nickname'                   => __( 'Nickname', 'blt-activity-log' ),
			'role'                       => __( 'Role', 'blt-activity-log' ),
			'content_reassigned_to'      => __( 'Content reassigned to', 'blt-activity-log' ),
			'application_name'           => __( 'Application', 'blt-activity-log' ),
			// Appearance.
			'menu_items'                 => __( 'Menu items', 'blt-activity-log' ),
			'menu_name'                  => __( 'Menu name', 'blt-activity-log' ),
			'theme'                      => __( 'Theme', 'blt-activity-log' ),
			// The log itself.
			'entries_removed'            => __( 'Entries removed', 'blt-activity-log' ),
			'entries_exported'           => __( 'Entries exported', 'blt-activity-log' ),
			'scope'                      => __( 'Scope', 'blt-activity-log' ),
			'filters'                    => __( 'Filters', 'blt-activity-log' ),
			// Options WordPress shows on its own screens.
			'blogname'                   => __( 'Site title', 'blt-activity-log' ),
			'blogdescription'            => __( 'Tagline', 'blt-activity-log' ),
			'site_icon'                  => __( 'Site icon', 'blt-activity-log' ),
			'siteurl'                    => __( 'WordPress address', 'blt-activity-log' ),
			'home'                       => __( 'Site address', 'blt-activity-log' ),
			'admin_email'                => __( 'Administration email address', 'blt-activity-log' ),
			'new_admin_email'            => __( 'New administration email address', 'blt-activity-log' ),
			'users_can_register'         => __( 'Anyone can register', 'blt-activity-log' ),
			'default_role'               => __( 'New user default role', 'blt-activity-log' ),
			'timezone_string'            => __( 'Timezone', 'blt-activity-log' ),
			'gmt_offset'                 => __( 'Timezone offset', 'blt-activity-log' ),
			'date_format'                => __( 'Date format', 'blt-activity-log' ),
			'time_format'                => __( 'Time format', 'blt-activity-log' ),
			'start_of_week'              => __( 'Week starts on', 'blt-activity-log' ),
			'WPLANG'                     => __( 'Site language', 'blt-activity-log' ),
			'show_on_front'              => __( 'Your homepage displays', 'blt-activity-log' ),
			'page_on_front'              => __( 'Homepage', 'blt-activity-log' ),
			'page_for_posts'             => __( 'Posts page', 'blt-activity-log' ),
			'posts_per_page'             => __( 'Blog pages show at most', 'blt-activity-log' ),
			'blog_public'                => __( 'Search engine visibility', 'blt-activity-log' ),
			'permalink_structure'        => __( 'Permalink structure', 'blt-activity-log' ),
			'category_base'              => __( 'Category base', 'blt-activity-log' ),
			'tag_base'                   => __( 'Tag base', 'blt-activity-log' ),
			'default_comment_status'     => __( 'Allow comments on new posts', 'blt-activity-log' ),
			'comment_moderation'         => __( 'Comments must be manually approved', 'blt-activity-log' ),
			'wp_page_for_privacy_policy' => __( 'Privacy policy page', 'blt-activity-log' ),
			'mailserver_pass'            => __( 'Mail server password', 'blt-activity-log' ),
		);

		if ( isset( $known[ $field ] ) ) {
			return $known[ $field ];
		}

		if ( 1 === preg_match( '/^(meta|location|sidebar|theme_mod)\[(.+)\]$/', $field, $m ) ) {
			switch ( $m[1] ) {
				case 'meta':
					/* translators: %s: a custom field name. */
					return sprintf( __( 'Custom field “%s”', 'blt-activity-log' ), $m[2] );
				case 'location':
					/* translators: %s: a menu location name. */
					return sprintf( __( 'Menu location “%s”', 'blt-activity-log' ), $m[2] );
				case 'sidebar':
					/* translators: %s: a widget area name. */
					return sprintf( __( 'Widget area “%s”', 'blt-activity-log' ), $m[2] );
				default:
					/* translators: %s: a theme setting name. */
					return sprintf( __( 'Theme setting “%s”', 'blt-activity-log' ), $m[2] );
			}
		}

		return $field;
	}

	/**
	 * A stored value as it should read in the Before / After table.
	 *
	 * @param string $field Field key.
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function value_label( $field, $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		switch ( $field ) {
			case 'post_status':
				$status = get_post_status_object( $value );

				return $status && isset( $status->label ) ? (string) $status->label : $value;

			case 'post_author':
				$user = ctype_digit( $value ) ? get_userdata( (int) $value ) : false;

				return $user ? (string) $user->user_login : $value;

			case 'post_parent':
			case 'page_on_front':
			case 'page_for_posts':
			case 'wp_page_for_privacy_policy':
				$post = ctype_digit( $value ) ? get_post( (int) $value ) : null;

				return $post ? (string) $post->post_title : $value;

			case 'file_size':
				return ctype_digit( $value ) ? (string) size_format( (int) $value, 1 ) : $value;

			case 'comment_status':
			case 'ping_status':
				return 'open' === $value ? __( 'Open', 'blt-activity-log' ) : ( 'closed' === $value ? __( 'Closed', 'blt-activity-log' ) : $value );

			case 'post_date':
				return self::site_time( $value );
		}

		return $value;
	}

	/**
	 * A short plain-text summary of what an entry changed, for the table.
	 *
	 * @param object|array $row Stored row.
	 * @return string
	 */
	public static function summary( $row ) {
		$details = self::details( $row );
		$parts   = array();

		if ( ! empty( $details['versions'] ) && is_array( $details['versions'] ) ) {
			$parts[] = self::versions_text( $details['versions'] ) . self::method_suffix( $details );
		}

		$status = self::status_text( $row, $details );

		if ( '' !== $status ) {
			$parts[] = $status;
		}

		if ( ! empty( $details['changes'] ) && is_array( $details['changes'] ) ) {
			$labels = array();

			foreach ( $details['changes'] as $change ) {
				if ( is_array( $change ) && isset( $change['field'] ) && 'post_status' !== $change['field'] ) {
					$labels[] = self::field_label( (string) $change['field'] );
				}
			}

			$labels = array_values( array_unique( $labels ) );

			if ( count( $labels ) > self::SUMMARY_FIELDS ) {
				$extra  = count( $labels ) - self::SUMMARY_FIELDS;
				$labels = array_slice( $labels, 0, self::SUMMARY_FIELDS );

				/* translators: %d: number of further fields changed. */
				$labels[] = sprintf( __( '+%d more', 'blt-activity-log' ), $extra );
			}

			if ( ! empty( $labels ) ) {
				$parts[] = implode( ', ', $labels );
			}
		}

		if ( ! empty( $details['content'] ) && is_array( $details['content'] ) ) {
			$parts[] = self::content_short( $details['content'] );
		}

		if ( ! empty( $details['bricks'] ) && is_array( $details['bricks'] ) ) {
			$parts[] = self::bricks_short( $details['bricks'] );
		}

		if ( ! empty( $details['terms'] ) && is_array( $details['terms'] ) ) {
			foreach ( array_keys( $details['terms'] ) as $taxonomy ) {
				$parts[] = self::taxonomy_label( (string) $taxonomy );
			}
		}

		if ( empty( $parts ) && ! empty( $details['summary'] ) ) {
			$parts[] = (string) $details['summary'];
		}

		return implode( ', ', array_filter( $parts, 'strlen' ) );
	}

	/**
	 * The "1.4.2 → 1.4.3" text for a version range.
	 *
	 * @param array $versions `from` and `to`.
	 * @return string
	 */
	public static function versions_text( array $versions ) {
		$from = isset( $versions['from'] ) ? (string) $versions['from'] : '';
		$to   = isset( $versions['to'] ) ? (string) $versions['to'] : '';

		if ( '' === $from ) {
			return $to;
		}

		if ( '' === $to ) {
			return $from;
		}

		return $from . ' → ' . $to;
	}

	/**
	 * " (automatic)" and its kin, for entries that say how they happened.
	 *
	 * @param array $details Entry details.
	 * @return string
	 */
	public static function method_suffix( array $details ) {
		if ( empty( $details['method'] ) ) {
			return '';
		}

		$labels = array(
			'screen'    => __( 'from the admin screen', 'blt-activity-log' ),
			'upload'    => __( 'uploaded', 'blt-activity-log' ),
			'automatic' => __( 'automatic', 'blt-activity-log' ),
			'cli'       => __( 'WP-CLI', 'blt-activity-log' ),
			'external'  => __( 'outside WordPress', 'blt-activity-log' ),
		);

		$method = (string) $details['method'];

		return ' (' . ( isset( $labels[ $method ] ) ? $labels[ $method ] : $method ) . ')';
	}

	/**
	 * "+2 blocks, −1 block" for a content summary.
	 *
	 * @param array $content Content summary.
	 * @return string
	 */
	public static function content_short( array $content ) {
		$added   = isset( $content['blocks_added'] ) ? array_sum( array_map( 'intval', (array) $content['blocks_added'] ) ) : 0;
		$removed = isset( $content['blocks_removed'] ) ? array_sum( array_map( 'intval', (array) $content['blocks_removed'] ) ) : 0;
		$bits    = array();

		if ( $added > 0 ) {
			/* translators: %d: number of blocks added. */
			$bits[] = '+' . sprintf( _n( '%d block', '%d blocks', $added, 'blt-activity-log' ), $added );
		}

		if ( $removed > 0 ) {
			/* translators: %d: number of blocks removed. */
			$bits[] = '−' . sprintf( _n( '%d block', '%d blocks', $removed, 'blt-activity-log' ), $removed );
		}

		$label = __( 'content', 'blt-activity-log' );

		return empty( $bits ) ? $label : $label . ' (' . implode( ', ', $bits ) . ')';
	}

	/**
	 * "Bricks (+1 element)" for a Bricks element summary.
	 *
	 * @param array $bricks Bricks summary.
	 * @return string
	 */
	public static function bricks_short( array $bricks ) {
		$added   = isset( $bricks['elements_added'] ) ? array_sum( array_map( 'intval', (array) $bricks['elements_added'] ) ) : 0;
		$removed = isset( $bricks['elements_removed'] ) ? array_sum( array_map( 'intval', (array) $bricks['elements_removed'] ) ) : 0;
		$bits    = array();

		if ( $added > 0 ) {
			/* translators: %d: number of Bricks elements added. */
			$bits[] = '+' . sprintf( _n( '%d element', '%d elements', $added, 'blt-activity-log' ), $added );
		}

		if ( $removed > 0 ) {
			/* translators: %d: number of Bricks elements removed. */
			$bits[] = '−' . sprintf( _n( '%d element', '%d elements', $removed, 'blt-activity-log' ), $removed );
		}

		return empty( $bits ) ? 'Bricks' : 'Bricks (' . implode( ', ', $bits ) . ')';
	}

	/**
	 * "Added 2 Paragraph and 1 Image, removed 1 Heading" for a content summary.
	 *
	 * @param array $content Content summary.
	 * @return string '' when no block moved.
	 */
	public static function content_long( array $content ) {
		return self::moves(
			isset( $content['blocks_added'] ) ? (array) $content['blocks_added'] : array(),
			isset( $content['blocks_removed'] ) ? (array) $content['blocks_removed'] : array(),
			array( __CLASS__, 'block_title' )
		);
	}

	/**
	 * "Added 1 Section and 2 Heading" for a Bricks element summary.
	 *
	 * @param array $bricks Bricks summary.
	 * @return string '' when no element moved.
	 */
	public static function bricks_long( array $bricks ) {
		return self::moves(
			isset( $bricks['elements_added'] ) ? (array) $bricks['elements_added'] : array(),
			isset( $bricks['elements_removed'] ) ? (array) $bricks['elements_removed'] : array(),
			array( __CLASS__, 'humanize' )
		);
	}

	/**
	 * A block's title: "core/paragraph" becomes "Paragraph".
	 *
	 * @param string $name Block name.
	 * @return string
	 */
	public static function block_title( $name ) {
		if ( class_exists( 'WP_Block_Type_Registry' ) ) {
			$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );

			if ( $type && ! empty( $type->title ) ) {
				return (string) $type->title;
			}
		}

		$short = false !== strpos( $name, '/' ) ? substr( $name, strpos( $name, '/' ) + 1 ) : $name;

		return self::humanize( $short );
	}

	/**
	 * A taxonomy's plural label ("Categories"), falling back to its key.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @return string
	 */
	public static function taxonomy_label( $taxonomy ) {
		$object = get_taxonomy( $taxonomy );

		return $object && isset( $object->labels->name ) ? (string) $object->labels->name : self::humanize( $taxonomy );
	}

	/**
	 * Whether a headline template exists for an action.
	 *
	 * @param string $category Category key.
	 * @param string $action   Action key.
	 * @return bool
	 */
	private static function has_template( $category, $action ) {
		return '' !== self::template( $category, $action );
	}

	/**
	 * The translated headline template for an action: %1$s who, %2$s what kind
	 * of thing, %3$s which one.
	 *
	 * @param string $category Category key.
	 * @param string $action   Action key.
	 * @return string '' when the action has no template.
	 */
	private static function template( $category, $action ) {
		$content = array(
			/* translators: 1: who, 2: kind of thing (such as "page"), 3: its name. */
			'created'      => __( '%1$s created %2$s “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 2: kind of thing (such as "page"), 3: its name. */
			'updated'      => __( '%1$s updated %2$s “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 2: kind of thing (such as "page"), 3: its name. */
			'published'    => __( '%1$s published %2$s “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 2: kind of thing (such as "page"), 3: its name. */
			'unpublished'  => __( '%1$s unpublished %2$s “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 2: kind of thing (such as "page"), 3: its name. */
			'scheduled'    => __( '%1$s scheduled %2$s “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 2: kind of thing (such as "page"), 3: its name. */
			'made_private' => __( '%1$s made %2$s “%3$s” private', 'blt-activity-log' ),
			/* translators: 1: who, 2: kind of thing (such as "page"), 3: its name. */
			'trashed'      => __( '%1$s trashed %2$s “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 2: kind of thing (such as "page"), 3: its name. */
			'restored'     => __( '%1$s restored %2$s “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 2: kind of thing (such as "page"), 3: its name. */
			'deleted'      => __( '%1$s deleted %2$s “%3$s”', 'blt-activity-log' ),
		);

		$map = array(
			'post:created'                         => $content['created'],
			'post:updated'                         => $content['updated'],
			'post:published'                       => $content['published'],
			'post:unpublished'                     => $content['unpublished'],
			'post:scheduled'                       => $content['scheduled'],
			'post:made_private'                    => $content['made_private'],
			'post:trashed'                         => $content['trashed'],
			'post:restored'                        => $content['restored'],
			'post:deleted'                         => $content['deleted'],

			/* translators: 1: who, 3: the file's name. */
			'media:uploaded'                       => __( '%1$s uploaded “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the file's name. */
			'media:updated'                        => __( '%1$s edited the details of “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the file's name. */
			'media:deleted'                        => __( '%1$s deleted “%3$s”', 'blt-activity-log' ),

			'term:created'                         => $content['created'],
			'term:updated'                         => $content['updated'],
			'term:deleted'                         => $content['deleted'],

			/* translators: 1: who, 3: the menu's name. */
			'appearance:menu_created'              => __( '%1$s created the menu “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the menu's name. */
			'appearance:menu_updated'              => __( '%1$s updated the menu “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the menu's name. */
			'appearance:menu_deleted'              => __( '%1$s deleted the menu “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who. */
			'appearance:menu_locations_changed'    => __( '%1$s changed menu locations', 'blt-activity-log' ),
			/* translators: 1: who. */
			'appearance:widgets_changed'           => __( '%1$s changed widgets', 'blt-activity-log' ),
			/* translators: 1: who. */
			'appearance:customizer_published'      => __( '%1$s changed theme settings', 'blt-activity-log' ),

			/* translators: 1: who, 3: the plugin's name. */
			'plugin:installed'                     => __( '%1$s installed the plugin “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the plugin's name. */
			'plugin:updated'                       => __( '%1$s updated the plugin “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the plugin's name. */
			'plugin:activated'                     => __( '%1$s activated the plugin “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the plugin's name. */
			'plugin:deactivated'                   => __( '%1$s deactivated the plugin “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the plugin's name. */
			'plugin:deleted'                       => __( '%1$s deleted the plugin “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the plugin's name. */
			'plugin:auto_updates_enabled'          => __( '%1$s turned on auto-updates for “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the plugin's name. */
			'plugin:auto_updates_disabled'         => __( '%1$s turned off auto-updates for “%3$s”', 'blt-activity-log' ),
			/* translators: 3: the plugin's name. */
			'plugin:changed_externally'            => __( 'The plugin “%3$s” changed outside WordPress', 'blt-activity-log' ),

			/* translators: 1: who, 3: the theme's name. */
			'theme:installed'                      => __( '%1$s installed the theme “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the theme's name. */
			'theme:updated'                        => __( '%1$s updated the theme “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the theme's name. */
			'theme:switched'                       => __( '%1$s switched to the theme “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the theme's name. */
			'theme:deleted'                        => __( '%1$s deleted the theme “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the theme's name. */
			'theme:auto_updates_enabled'           => __( '%1$s turned on auto-updates for “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the theme's name. */
			'theme:auto_updates_disabled'          => __( '%1$s turned off auto-updates for “%3$s”', 'blt-activity-log' ),
			/* translators: 3: the theme's name. */
			'theme:changed_externally'             => __( 'The theme “%3$s” changed outside WordPress', 'blt-activity-log' ),

			/* translators: 1: who. */
			'core:updated'                         => __( '%1$s updated WordPress', 'blt-activity-log' ),
			'core:changed_externally'              => __( 'WordPress changed outside WordPress itself', 'blt-activity-log' ),

			/* translators: 1: who, 3: the setting's name. */
			'setting:updated'                      => __( '%1$s changed the setting “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the setting's name. */
			'setting:deleted'                      => __( '%1$s deleted the setting “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who. */
			'setting:admin_email_change_requested' => __( '%1$s requested a change of the administration email address', 'blt-activity-log' ),

			/* translators: 1: who, 3: the account's username. */
			'user:created'                         => __( '%1$s created the user “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the account's username. */
			'user:updated'                         => __( '%1$s updated the user “%3$s”', 'blt-activity-log' ),
			/* translators: 3: the account's username. */
			'user:password_changed'                => __( 'The password of “%3$s” was changed', 'blt-activity-log' ),
			/* translators: 3: the account's username. */
			'user:password_reset'                  => __( 'The password of “%3$s” was reset', 'blt-activity-log' ),
			/* translators: 1: who, 3: the account's username. */
			'user:role_changed'                    => __( '%1$s changed the role of “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the account's username. */
			'user:deleted'                         => __( '%1$s deleted the user “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the account's username. */
			'user:app_password_created'            => __( '%1$s created an application password for “%3$s”', 'blt-activity-log' ),
			/* translators: 1: who, 3: the account's username. */
			'user:app_password_revoked'            => __( '%1$s revoked an application password for “%3$s”', 'blt-activity-log' ),

			/* translators: 1: who. */
			'session:logged_in'                    => __( '%1$s signed in', 'blt-activity-log' ),
			/* translators: 1: who. */
			'session:logged_out'                   => __( '%1$s signed out', 'blt-activity-log' ),
			/* translators: 3: the username that was tried. */
			'session:login_failed'                 => __( 'A sign-in failed for “%3$s”', 'blt-activity-log' ),

			/* translators: 1: who. */
			'system:logging_started'               => __( 'Logging started (%1$s)', 'blt-activity-log' ),
			/* translators: 1: who. */
			'system:log_cleared'                   => __( '%1$s cleared the log', 'blt-activity-log' ),
			/* translators: 1: who. */
			'system:exported'                      => __( '%1$s exported the log', 'blt-activity-log' ),
		);

		$key = $category . ':' . $action;

		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}

	/**
	 * The status transition of a post entry: "Draft → Published".
	 *
	 * @param object|array $row     Stored row.
	 * @param array        $details Decoded details.
	 * @return string
	 */
	private static function status_text( $row, array $details ) {
		if ( 'post' !== self::field( $row, 'category' ) || empty( $details['changes'] ) || ! is_array( $details['changes'] ) ) {
			return '';
		}

		foreach ( $details['changes'] as $change ) {
			if ( ! is_array( $change ) || ! isset( $change['field'] ) || 'post_status' !== $change['field'] ) {
				continue;
			}

			$before = self::value_label( 'post_status', isset( $change['before'] ) ? (string) $change['before'] : '' );
			$after  = self::value_label( 'post_status', isset( $change['after'] ) ? (string) $change['after'] : '' );

			if ( '' === $before ) {
				return $after;
			}

			return '' === $after ? $before : $before . ' → ' . $after;
		}

		return '';
	}

	/**
	 * Read a field from a stored row, whether it is an object or an array.
	 *
	 * @param object|array $row   Stored row.
	 * @param string       $field Field name.
	 * @return mixed
	 */
	private static function field( $row, $field ) {
		if ( is_object( $row ) ) {
			return isset( $row->$field ) ? $row->$field : '';
		}

		return isset( $row[ $field ] ) ? $row[ $field ] : '';
	}

	/**
	 * Describe what was added and removed from two name => count histograms.
	 *
	 * @param array<string,int> $added   Names added, with counts.
	 * @param array<string,int> $removed Names removed, with counts.
	 * @param callable          $title   Maps a name to its display title.
	 * @return string '' when nothing moved.
	 */
	private static function moves( array $added, array $removed, $title ) {
		$lists = array();

		foreach ( array(
			'added'   => $added,
			'removed' => $removed,
		) as $key => $histogram ) {
			$items = array();

			foreach ( $histogram as $name => $count ) {
				$items[] = (int) $count . ' ' . call_user_func( $title, (string) $name );
			}

			if ( ! empty( $items ) ) {
				$lists[ $key ] = wp_sprintf( '%l', $items );
			}
		}

		if ( isset( $lists['added'], $lists['removed'] ) ) {
			/* translators: 1: what was added, such as "2 Paragraph and 1 Image". 2: what was removed. */
			return sprintf( __( 'Added %1$s, removed %2$s', 'blt-activity-log' ), $lists['added'], $lists['removed'] );
		}

		if ( isset( $lists['added'] ) ) {
			/* translators: %s: what was added, such as "2 Paragraph and 1 Image". */
			return sprintf( __( 'Added %s', 'blt-activity-log' ), $lists['added'] );
		}

		if ( isset( $lists['removed'] ) ) {
			/* translators: %s: what was removed, such as "1 Heading". */
			return sprintf( __( 'Removed %s', 'blt-activity-log' ), $lists['removed'] );
		}

		return '';
	}

	/**
	 * "some_key" becomes "Some key".
	 *
	 * @param string $key Machine key.
	 * @return string
	 */
	private static function humanize( $key ) {
		return self::upper_first( trim( str_replace( array( '_', '-' ), ' ', (string) $key ) ) );
	}

	/**
	 * Lower-case a string, multibyte-aware.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Upper-case the first letter of a string, multibyte-aware.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function upper_first( $text ) {
		if ( '' === $text ) {
			return '';
		}

		if ( function_exists( 'mb_substr' ) ) {
			return mb_strtoupper( mb_substr( $text, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $text, 1, null, 'UTF-8' );
		}

		return ucfirst( $text );
	}
}
