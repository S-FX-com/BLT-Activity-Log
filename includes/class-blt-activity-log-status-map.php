<?php
/**
 * Post status transition to action key. WordPress-free.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps `(status before, status after, is_update)` to the action recorded for
 * a post save.
 *
 * Status changes are read from one hook only (`wp_after_insert_post`), so
 * this is the single place that decides what a transition means.
 */
class BLT_Activity_Log_Status_Map {

	/**
	 * Statuses that mean "this post did not exist before this save".
	 */
	const NEW_STATUSES = array( '', 'new', 'auto-draft' );

	/**
	 * Statuses a visitor, or the schedule, can see.
	 */
	const LIVE_STATUSES = array( 'publish', 'future', 'private' );

	/**
	 * Every action a post save can map to.
	 */
	const ACTIONS = array( 'created', 'updated', 'published', 'unpublished', 'scheduled', 'made_private', 'trashed', 'restored' );

	/**
	 * The action for a post save.
	 *
	 * @param string|null $before    Status before the save (null when none).
	 * @param string      $after     Status after the save.
	 * @param bool        $is_update Whether WordPress considered the save an update.
	 * @return string|null Action key, or null when nothing should be logged
	 *                     (a save that leaves the post an auto-draft).
	 */
	public static function action( $before, $after, $is_update ) {
		$before = (string) $before;
		$after  = (string) $after;

		if ( 'auto-draft' === $after ) {
			return null;
		}

		if ( ! $is_update || in_array( $before, self::NEW_STATUSES, true ) ) {
			return 'created';
		}

		if ( 'trash' === $after && 'trash' !== $before ) {
			return 'trashed';
		}

		if ( 'trash' === $before && 'trash' !== $after ) {
			return 'restored';
		}

		if ( $before === $after ) {
			return 'updated';
		}

		if ( 'future' === $after ) {
			return 'scheduled';
		}

		if ( 'publish' === $after ) {
			return 'published';
		}

		if ( 'private' === $after ) {
			return 'made_private';
		}

		if ( in_array( $before, self::LIVE_STATUSES, true ) && in_array( $after, array( 'draft', 'pending' ), true ) ) {
			return 'unpublished';
		}

		return 'updated';
	}
}
