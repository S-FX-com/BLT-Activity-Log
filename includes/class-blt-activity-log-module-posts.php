<?php
/**
 * Module: posts, pages and other content types.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records content created, edited, published, unpublished, scheduled, made
 * private, trashed, restored and deleted, with a field-level diff and a
 * summary of what changed inside the content.
 *
 * Status changes are read from one hook only, `wp_after_insert_post`. Hooking
 * `trashed_post` or `transition_post_status` as well would log every trash
 * and publish twice.
 */
class BLT_Activity_Log_Module_Posts extends BLT_Activity_Log_Module_Base {

	/**
	 * Fields compared between the post before and after a save.
	 */
	const FIELDS = array(
		'post_title',
		'post_name',
		'post_status',
		'post_author',
		'post_parent',
		'menu_order',
		'post_excerpt',
		'comment_status',
		'ping_status',
		'post_password',
		'post_date',
	);

	/**
	 * Types always covered even though they have no admin screen of their own.
	 */
	const EXTRA_TYPES = array( 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_block', 'wp_navigation', 'bricks_template' );

	/**
	 * Types never covered: they have a module of their own, or are not content.
	 */
	const EXCLUDED_TYPES = array(
		'attachment',
		'nav_menu_item',
		'revision',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		// Not content: WooCommerce orders and ACF field definitions are non-goals (CLAUDE.md section 11).
		// A site that wants them back adds them with the blt_activity_log_post_types filter.
		'shop_order',
		'shop_order_placehold',
		'shop_order_refund',
		'acf-field-group',
		'acf-field',
		'acf-post-type',
		'acf-taxonomy',
		'acf-ui-options-page',
	);

	/**
	 * Relationships added in this request, by `{object}:{taxonomy}`, so an append can tell what was new.
	 *
	 * @var array<string,int[]>
	 */
	private $new_relationships = array();

	/**
	 * Private meta keys tracked besides every public key.
	 */
	const PRIVATE_META = array( '_thumbnail_id', '_wp_page_template' );

	/**
	 * The covered post types, once `init` has run.
	 *
	 * @var string[]|null
	 */
	private static $types = null;

	/**
	 * Meta values seen just before an update or delete, keyed `{post}:{key}`.
	 *
	 * @var array<string,mixed>
	 */
	private $meta_before = array();

	/**
	 * Posts this request is deleting, keyed by post ID.
	 *
	 * WordPress removes a post's meta and term links after `before_delete_post`
	 * has fired. Those removals are part of the delete, not edits, and must not
	 * turn into an `updated` entry of their own.
	 *
	 * @var array<int,bool>
	 */
	private $deleting = array();

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'post';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return __( 'Posts & pages', 'blt-activity-log' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function description() {
		return __( 'Content created, edited, published, scheduled, trashed, restored or deleted, with the fields that changed.', 'blt-activity-log' );
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
		$this->listen( 'wp_after_insert_post', 'on_after_insert', 20, 4 );
		$this->listen( 'before_delete_post', 'on_before_delete', 10, 2 );
		$this->listen( 'added_term_relationship', 'on_term_relationship', 10, 3 );
		$this->listen( 'set_object_terms', 'on_terms', 10, 6 );
		$this->listen( 'update_post_meta', 'on_meta_before', 10, 4 );
		$this->listen( 'delete_post_meta', 'on_meta_before_delete', 10, 4 );
		$this->listen( 'added_post_meta', 'on_meta_added', 10, 4 );
		$this->listen( 'updated_post_meta', 'on_meta_updated', 10, 4 );
		$this->listen( 'deleted_post_meta', 'on_meta_deleted', 10, 4 );
	}

	/**
	 * The post types this module covers.
	 *
	 * @return string[]
	 */
	public static function types() {
		if ( null !== self::$types ) {
			return self::$types;
		}

		$types = array_merge( array_values( get_post_types( array( 'show_ui' => true ) ) ), self::EXTRA_TYPES );
		$types = array_diff( $types, self::EXCLUDED_TYPES, (array) BLT_Activity_Log_Settings::get( 'excluded_post_types', array() ) );

		/**
		 * Filter the post types that are logged.
		 *
		 * @param string[] $types Post type slugs.
		 */
		$types = apply_filters( 'blt_activity_log_post_types', array_values( array_unique( $types ) ) );
		$types = is_array( $types ) ? array_values( $types ) : array();

		// Types register on `init`. Until it has finished (`wp_loaded`), an answer would be partial and
		// must not be kept for the rest of the request.
		if ( did_action( 'wp_loaded' ) ) {
			self::$types = $types;
		}

		return $types;
	}

	/**
	 * Forget the cached type list (tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$types = null;
	}

	/**
	 * A post save: created, updated, or a status change.
	 *
	 * @param int          $post_id     Post ID.
	 * @param WP_Post      $post        Post after the save.
	 * @param bool         $update      Whether this was an update.
	 * @param WP_Post|null $post_before Post before the save.
	 * @return void
	 */
	public function on_after_insert( $post_id, $post = null, $update = false, $post_before = null ) {
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::types(), true ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$before_status = $post_before instanceof WP_Post ? $post_before->post_status : null;
		$action        = BLT_Activity_Log_Status_Map::action( $before_status, $post->post_status, (bool) $update );

		if ( null === $action ) {
			return;
		}

		$after  = get_object_vars( $post );
		$before = $post_before instanceof WP_Post ? get_object_vars( $post_before ) : array();

		$details = array();

		if ( 'created' === $action ) {
			$details['changes'] = BLT_Activity_Log_Diff::fields( array(), $after, array( 'post_status', 'post_author' ) );
		} else {
			$keys = self::FIELDS;

			// WordPress stamps the date itself on first publish; only a date
			// moved on an already published or scheduled post is an edit.
			if ( ! in_array( (string) $before_status, array( 'publish', 'future' ), true ) ) {
				$keys = array_diff( $keys, array( 'post_date' ) );
			}

			$changes = BLT_Activity_Log_Diff::fields( $before, $after, $keys );
			$changes = $this->hide_password( $changes, $before, $after );
			$changes = $this->ignore_trash_slug( $changes );

			if ( ! empty( $changes ) ) {
				$details['changes'] = $changes;
			}

			$content = $this->content_summary( (string) ( isset( $before['post_content'] ) ? $before['post_content'] : '' ), (string) $post->post_content, (int) $post_id );

			if ( ! empty( $content ) ) {
				$details['content'] = $content;
			}
		}

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'post',
				'action'       => $action,
				'object_type'  => $post->post_type,
				'object_id'    => (int) $post_id,
				'object_label' => (string) $post->post_title,
				'details'      => $details,
				// A term or meta hook that fired just before this one belongs to the same entry.
				'absorb'       => 'updated' === $action ? BLT_Activity_Log_Logger::FOLD_ACTIONS : array( 'updated' ),
			)
		);
	}

	/**
	 * A permanent delete. The title and type are read before the row goes.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post being deleted.
	 * @return void
	 */
	public function on_before_delete( $post_id, $post = null ) {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post instanceof WP_Post || 'auto-draft' === $post->post_status || ! in_array( $post->post_type, self::types(), true ) ) {
			return;
		}

		$this->deleting[ (int) $post_id ] = true;

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'post',
				'action'       => 'deleted',
				'object_type'  => $post->post_type,
				'object_id'    => (int) $post_id,
				'object_label' => (string) $post->post_title,
				'details'      => array(
					'changes' => array(
						array(
							'field'  => 'post_status',
							'before' => (string) $post->post_status,
							'after'  => '',
						),
					),
				),
			)
		);
	}

	/**
	 * Terms assigned to a post.
	 *
	 * @param int    $object_id   Object ID.
	 * @param array  $terms       Terms as passed.
	 * @param int[]  $tt_ids      Term taxonomy IDs set.
	 * @param string $taxonomy    Taxonomy.
	 * @param bool   $append      Whether the terms were appended.
	 * @param int[]  $old_tt_ids  Term taxonomy IDs before.
	 * @return void
	 */
	public function on_terms( $object_id, $terms = array(), $tt_ids = array(), $taxonomy = '', $append = false, $old_tt_ids = array() ) {
		unset( $terms );

		$relationships = isset( $this->new_relationships[ $object_id . ':' . $taxonomy ] ) ? $this->new_relationships[ $object_id . ':' . $taxonomy ] : array();

		unset( $this->new_relationships[ $object_id . ':' . $taxonomy ] );

		if ( $this->is_front_request() || $this->is_autosave() ) {
			return;
		}

		$post = $this->loggable_post( $object_id );

		// Another kind of object can share the number (a user, say, with a member type).
		if ( ! $post || isset( $this->deleting[ (int) $object_id ] ) || ! is_object_in_taxonomy( $post->post_type, (string) $taxonomy ) ) {
			return;
		}

		$old_value = array_map( 'intval', (array) $old_tt_ids );
		$set       = array_map( 'intval', (array) $tt_ids );

		if ( $append ) {
			// WordPress hands over no "before" when appending; what it actually attached is what is new.
			$added   = array_values( array_intersect( $set, $relationships ) );
			$removed = array();
		} else {
			$added   = array_diff( $set, $old_value );
			$removed = array_diff( $old_value, $set );
		}

		if ( empty( $added ) && empty( $removed ) ) {
			return;
		}

		$this->fold(
			$post,
			array(
				'terms' => array(
					(string) $taxonomy => array(
						'added'   => $this->term_names( $added, (string) $taxonomy ),
						'removed' => $this->term_names( $removed, (string) $taxonomy ),
					),
				),
			)
		);
	}

	/**
	 * A term was attached to an object that did not have it.
	 *
	 * @param int    $object_id Object ID.
	 * @param int    $tt_id     Term taxonomy ID.
	 * @param string $taxonomy  Taxonomy.
	 * @return void
	 */
	public function on_term_relationship( $object_id, $tt_id = 0, $taxonomy = '' ) {
		$this->new_relationships[ $object_id . ':' . $taxonomy ][] = (int) $tt_id;
	}

	/**
	 * Whether this request is an autosave, which is never logged.
	 *
	 * @return bool
	 */
	private function is_autosave() {
		return defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE;
	}

	/**
	 * Remember a meta value just before it is updated.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 * @return void
	 */
	public function on_meta_before( $meta_id, $object_id, $meta_key, $meta_value = null ) {
		unset( $meta_id, $meta_value );

		if ( $this->watches_meta( (int) $object_id, (string) $meta_key ) ) {
			$this->meta_before[ $object_id . ':' . $meta_key ] = get_post_meta( $object_id, $meta_key, true );
		}
	}

	/**
	 * Remember a meta value just before it is deleted.
	 *
	 * @param int[]  $meta_ids   Meta IDs.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value filter given to the delete.
	 * @return void
	 */
	public function on_meta_before_delete( $meta_ids, $object_id, $meta_key, $meta_value = null ) {
		unset( $meta_ids, $meta_value );

		if ( $this->watches_meta( (int) $object_id, (string) $meta_key ) ) {
			$this->meta_before[ $object_id . ':' . $meta_key ] = get_post_meta( $object_id, $meta_key, true );
		}
	}

	/**
	 * A meta value was added.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value.
	 * @return void
	 */
	public function on_meta_added( $meta_id, $object_id, $meta_key, $meta_value = null ) {
		unset( $meta_id );

		$this->record_meta( (int) $object_id, (string) $meta_key, '', $meta_value );
	}

	/**
	 * A meta value was updated.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 * @return void
	 */
	public function on_meta_updated( $meta_id, $object_id, $meta_key, $meta_value = null ) {
		unset( $meta_id );

		$slot      = $object_id . ':' . $meta_key;
		$old_value = array_key_exists( $slot, $this->meta_before ) ? $this->meta_before[ $slot ] : '';

		unset( $this->meta_before[ $slot ] );

		$this->record_meta( (int) $object_id, (string) $meta_key, $old_value, $meta_value );
	}

	/**
	 * A meta value was deleted.
	 *
	 * @param int[]  $meta_ids   Meta IDs.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value filter given to the delete.
	 * @return void
	 */
	public function on_meta_deleted( $meta_ids, $object_id, $meta_key, $meta_value = null ) {
		unset( $meta_ids, $meta_value );

		$slot      = $object_id . ':' . $meta_key;
		$old_value = array_key_exists( $slot, $this->meta_before ) ? $this->meta_before[ $slot ] : '';

		unset( $this->meta_before[ $slot ] );

		$this->record_meta( (int) $object_id, (string) $meta_key, $old_value, '' );
	}

	/**
	 * The Bricks meta keys, read from Bricks' own constants when it is active.
	 *
	 * @return string[]
	 */
	public static function bricks_keys() {
		$keys = array();

		foreach ( array( 'BRICKS_DB_PAGE_CONTENT', 'BRICKS_DB_PAGE_HEADER', 'BRICKS_DB_PAGE_FOOTER' ) as $constant ) {
			if ( defined( $constant ) ) {
				$keys[] = (string) constant( $constant );
			}
		}

		return $keys;
	}

	/**
	 * Whether a meta change on a post is worth tracking.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	private function watches_meta( $post_id, $meta_key ) {
		if ( $this->is_front_request() || $this->is_autosave() ) {
			return false;
		}

		return in_array( $meta_key, self::bricks_keys(), true ) || $this->is_tracked_key( $post_id, $meta_key );
	}

	/**
	 * Whether a key is tracked: every public key, plus a short private list.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	private function is_tracked_key( $post_id, $meta_key ) {
		if ( '' === $meta_key ) {
			return false;
		}

		if ( '_' !== $meta_key[0] ) {
			return true;
		}

		/**
		 * Filter the private meta keys (those starting with an underscore)
		 * that are tracked on posts. Every public key is always tracked.
		 *
		 * @param string[] $keys    Private keys. Default `_thumbnail_id`, `_wp_page_template`.
		 * @param int      $post_id Post ID.
		 */
		$keys = apply_filters( 'blt_activity_log_tracked_meta_keys', self::PRIVATE_META, $post_id );

		return is_array( $keys ) && in_array( $meta_key, $keys, true );
	}

	/**
	 * Record one meta change against its post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $old_value      Value before.
	 * @param mixed  $new_value      Value after.
	 * @return void
	 */
	private function record_meta( $post_id, $meta_key, $old_value, $new_value ) {
		if ( ! $this->watches_meta( $post_id, $meta_key ) || isset( $this->deleting[ $post_id ] ) ) {
			return;
		}

		$post = $this->loggable_post( $post_id );

		if ( ! $post ) {
			return;
		}

		if ( in_array( $meta_key, self::bricks_keys(), true ) ) {
			if ( BLT_Activity_Log_Diff::equal( $old_value, $new_value ) ) {
				return;
			}

			$delta = BLT_Activity_Log_Diff::histogram_delta(
				BLT_Activity_Log_Diff::elements_histogram( is_array( $old_value ) ? $old_value : array() ),
				BLT_Activity_Log_Diff::elements_histogram( is_array( $new_value ) ? $new_value : array() )
			);

			// The Bricks JSON itself is never stored, only the element counts.
			$this->fold(
				$post,
				array(
					'bricks' => array(
						'elements_added'   => $delta['added'],
						'elements_removed' => $delta['removed'],
					),
				)
			);

			return;
		}

		if ( BLT_Activity_Log_Diff::equal( $old_value, $new_value ) ) {
			return;
		}

		$this->fold(
			$post,
			array(
				'changes' => array(
					array(
						'field'  => 'meta[' . $meta_key . ']',
						'before' => BLT_Activity_Log_Diff::to_string( $old_value ),
						'after'  => BLT_Activity_Log_Diff::to_string( $new_value ),
					),
				),
			)
		);
	}

	/**
	 * Fold partial details into the entry for a post, creating an `updated` one
	 * when this request has not saved the post yet.
	 *
	 * @param WP_Post $post    Post.
	 * @param array   $details Partial details.
	 * @return void
	 */
	private function fold( WP_Post $post, array $details ) {
		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'post',
				'action'       => 'updated',
				'object_type'  => $post->post_type,
				'object_id'    => (int) $post->ID,
				'object_label' => (string) $post->post_title,
				'details'      => $details,
				'absorb'       => BLT_Activity_Log_Logger::FOLD_ACTIONS,
			)
		);
	}

	/**
	 * The post, when it is one this module logs.
	 *
	 * Auto-drafts (a new post screen being opened) and revisions are not.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post|null
	 */
	private function loggable_post( $post_id ) {
		global $wpdb;

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		// While a post is being created, terms and meta are written after its row is updated but before the
		// cache is cleared, so get_post() still answers "auto-draft". The row knows better.
		if ( 'auto-draft' === $post->post_status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the live status of one row, past a stale cache.
			$status = $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", (int) $post_id ) );

			if ( null === $status || 'auto-draft' === $status ) {
				return null;
			}

			$post->post_status = (string) $status;
		}

		return in_array( $post->post_type, self::types(), true ) ? $post : null;
	}

	/**
	 * Names of terms by term taxonomy ID.
	 *
	 * @param int[]  $tt_ids   Term taxonomy IDs.
	 * @param string $taxonomy Taxonomy.
	 * @return string[]
	 */
	private function term_names( array $tt_ids, $taxonomy ) {
		$names = array();

		foreach ( $tt_ids as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, $taxonomy );

			if ( $term instanceof WP_Term ) {
				$names[] = (string) $term->name;
			}
		}

		return $names;
	}

	/**
	 * Drop a slug change that is only WordPress adding or removing `__trashed`.
	 *
	 * Trashing a post renames its slug to free it up, and restoring renames it
	 * back. That is an implementation detail of the trash, not something anyone
	 * edited, so it is left out of the entry.
	 *
	 * @param array $changes Changes from Diff::fields().
	 * @return array
	 */
	private function ignore_trash_slug( array $changes ) {
		return array_values(
			array_filter(
				$changes,
				function ( $change ) {
					if ( 'post_name' !== $change['field'] ) {
						return true;
					}

					$before = (string) $change['before'];
					$after  = (string) $change['after'];

					return ! (
						$before . '__trashed' === $after
						|| $after . '__trashed' === $before
					);
				}
			)
		);
	}

	/**
	 * Replace a post_password change with a note that never carries the value.
	 *
	 * @param array $changes Changes from Diff::fields().
	 * @param array $before  Post fields before.
	 * @param array $after   Post fields after.
	 * @return array
	 */
	private function hide_password( array $changes, array $before, array $after ) {
		$changes = array_values(
			array_filter(
				$changes,
				function ( $change ) {
					return 'post_password' !== $change['field'];
				}
			)
		);

		$old_value = isset( $before['post_password'] ) ? (string) $before['post_password'] : '';
		$new_value = isset( $after['post_password'] ) ? (string) $after['post_password'] : '';

		if ( $old_value === $new_value ) {
			return $changes;
		}

		if ( '' === $old_value ) {
			$note = 'set';
		} elseif ( '' === $new_value ) {
			$note = 'removed';
		} else {
			$note = 'changed';
		}

		$changes[] = array(
			'field'    => 'post_password',
			'redacted' => true,
			'note'     => $note,
		);

		return $changes;
	}

	/**
	 * What changed inside the post content, without storing the content.
	 *
	 * @param string $before  Content before.
	 * @param string $after   Content after.
	 * @param int    $post_id Post ID.
	 * @return array
	 */
	private function content_summary( $before, $after, $post_id ) {
		if ( $before === $after ) {
			return array();
		}

		$delta = BLT_Activity_Log_Diff::histogram_delta(
			BLT_Activity_Log_Diff::block_histogram( parse_blocks( $before ) ),
			BLT_Activity_Log_Diff::block_histogram( parse_blocks( $after ) )
		);

		$summary = array(
			'chars'  => array( BLT_Activity_Log_Redactor::length( $before ), BLT_Activity_Log_Redactor::length( $after ) ),
			// Fingerprints, not content: they let a save that undoes an earlier one be recognized exactly.
			'hashes' => array( substr( md5( $before ), 0, 16 ), substr( md5( $after ), 0, 16 ) ),
		);

		if ( ! empty( $delta['added'] ) ) {
			$summary['blocks_added'] = $delta['added'];
		}

		if ( ! empty( $delta['removed'] ) ) {
			$summary['blocks_removed'] = $delta['removed'];
		}

		$revision_id = $this->latest_revision_id( $post_id );

		if ( $revision_id > 0 ) {
			$summary['revision_id'] = $revision_id;
		}

		return $summary;
	}

	/**
	 * The newest revision of a post, so the detail screen can link to core's
	 * Compare revisions screen. 0 when revisions are off or none exist.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	private function latest_revision_id( $post_id ) {
		if ( ! function_exists( 'wp_get_post_revisions' ) || 0 === (int) wp_revisions_to_keep( get_post( $post_id ) ) ) {
			return 0;
		}

		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'check_enabled'  => false,
			)
		);

		return empty( $revisions ) ? 0 : (int) reset( $revisions );
	}
}
