<?php
/**
 * Module: media library.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records uploads, edits to an attachment's title, caption, description and
 * alt text, and deletions.
 *
 * Edits are read from `attachment_updated`, not `edit_attachment`: only the
 * former hands over the post as it was before the save. Alt text is post
 * meta, saved separately, so it is folded into the same entry.
 */
class BLT_Activity_Log_Module_Media extends BLT_Activity_Log_Module_Base {

	/**
	 * Post meta key holding an image's alt text.
	 */
	const ALT_KEY = '_wp_attachment_image_alt';

	/**
	 * Alt text seen just before an update, keyed by attachment ID.
	 *
	 * @var array<int,mixed>
	 */
	private $alt_before = array();

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'media';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return __( 'Media', 'blt-activity-log' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function description() {
		return __( 'Files uploaded to the media library, edits to their details, and deletions.', 'blt-activity-log' );
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
		$this->listen( 'add_attachment', 'on_added', 10, 1 );
		$this->listen( 'attachment_updated', 'on_updated', 10, 3 );
		$this->listen( 'delete_attachment', 'on_deleted', 10, 2 );
		$this->listen( 'update_post_meta', 'on_alt_before', 10, 4 );
		$this->listen( 'added_post_meta', 'on_alt_added', 10, 4 );
		$this->listen( 'updated_post_meta', 'on_alt_updated', 10, 4 );
	}

	/**
	 * A file was uploaded.
	 *
	 * @param int $post_id Attachment ID.
	 * @return void
	 */
	public function on_added( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$path = (string) get_attached_file( $post_id );
		$size = ( '' !== $path && file_exists( $path ) ) ? (int) filesize( $path ) : 0;

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'media',
				'action'       => 'uploaded',
				'object_type'  => 'attachment',
				'object_id'    => (int) $post_id,
				'object_label' => (string) $post->post_title,
				'details'      => array(
					'changes' => array(
						array(
							'field'  => 'file',
							'before' => '',
							'after'  => wp_basename( $path ),
						),
						array(
							'field'  => 'mime_type',
							'before' => '',
							'after'  => (string) $post->post_mime_type,
						),
						array(
							'field'  => 'file_size',
							'before' => '',
							'after'  => (string) $size,
						),
					),
				),
				'absorb'       => array( 'updated' ),
			)
		);
	}

	/**
	 * An attachment's details were edited.
	 *
	 * @param int          $post_id     Attachment ID.
	 * @param WP_Post|null $post_after  Attachment after.
	 * @param WP_Post|null $post_before Attachment before.
	 * @return void
	 */
	public function on_updated( $post_id, $post_after = null, $post_before = null ) {
		if ( ! $post_after instanceof WP_Post || ! $post_before instanceof WP_Post ) {
			return;
		}

		// Post columns carry different meanings here, so they get media names.
		$map = array(
			'title'       => 'post_title',
			'caption'     => 'post_excerpt',
			'description' => 'post_content',
		);

		$changes = array();

		foreach ( $map as $field => $column ) {
			if ( ! BLT_Activity_Log_Diff::equal( $post_before->$column, $post_after->$column ) ) {
				$changes[] = array(
					'field'  => $field,
					'before' => BLT_Activity_Log_Diff::to_string( $post_before->$column ),
					'after'  => BLT_Activity_Log_Diff::to_string( $post_after->$column ),
				);
			}
		}

		$this->fold( $post_after, $changes );
	}

	/**
	 * An attachment is about to be deleted. Read its name while it exists.
	 *
	 * @param int          $post_id Attachment ID.
	 * @param WP_Post|null $post    Attachment.
	 * @return void
	 */
	public function on_deleted( $post_id, $post = null ) {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'media',
				'action'       => 'deleted',
				'object_type'  => 'attachment',
				'object_id'    => (int) $post_id,
				'object_label' => (string) $post->post_title,
				'details'      => array(
					'changes' => array(
						array(
							'field'  => 'file',
							'before' => wp_basename( (string) get_attached_file( $post_id ) ),
							'after'  => '',
						),
					),
				),
			)
		);
	}

	/**
	 * Remember the alt text just before it is updated.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 * @return void
	 */
	public function on_alt_before( $meta_id, $object_id, $meta_key, $meta_value = null ) {
		unset( $meta_id, $meta_value );

		if ( self::ALT_KEY === $meta_key ) {
			$this->alt_before[ (int) $object_id ] = get_post_meta( $object_id, self::ALT_KEY, true );
		}
	}

	/**
	 * Alt text was added.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value.
	 * @return void
	 */
	public function on_alt_added( $meta_id, $object_id, $meta_key, $meta_value = null ) {
		unset( $meta_id );

		if ( self::ALT_KEY === $meta_key ) {
			$this->record_alt( (int) $object_id, '', $meta_value );
		}
	}

	/**
	 * Alt text was updated.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 * @return void
	 */
	public function on_alt_updated( $meta_id, $object_id, $meta_key, $meta_value = null ) {
		unset( $meta_id );

		if ( self::ALT_KEY !== $meta_key ) {
			return;
		}

		$old_value = array_key_exists( (int) $object_id, $this->alt_before ) ? $this->alt_before[ (int) $object_id ] : '';

		unset( $this->alt_before[ (int) $object_id ] );

		$this->record_alt( (int) $object_id, $old_value, $meta_value );
	}

	/**
	 * Fold an alt text change into the attachment's entry.
	 *
	 * @param int   $post_id Attachment ID.
	 * @param mixed $old_value     Alt text before.
	 * @param mixed $new_value     Alt text after.
	 * @return void
	 */
	private function record_alt( $post_id, $old_value, $new_value ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type || BLT_Activity_Log_Diff::equal( $old_value, $new_value ) ) {
			return;
		}

		$this->fold(
			$post,
			array(
				array(
					'field'  => 'alt_text',
					'before' => BLT_Activity_Log_Diff::to_string( $old_value ),
					'after'  => BLT_Activity_Log_Diff::to_string( $new_value ),
				),
			)
		);
	}

	/**
	 * Fold field changes into the attachment's `updated` entry.
	 *
	 * @param WP_Post $post    Attachment.
	 * @param array   $changes Change items.
	 * @return void
	 */
	private function fold( WP_Post $post, array $changes ) {
		if ( empty( $changes ) ) {
			return;
		}

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'media',
				'action'       => 'updated',
				'object_type'  => 'attachment',
				'object_id'    => (int) $post->ID,
				'object_label' => (string) $post->post_title,
				'details'      => array( 'changes' => $changes ),
				'absorb'       => array( 'uploaded', 'updated' ),
			)
		);
	}
}
