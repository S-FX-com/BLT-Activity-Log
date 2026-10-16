<?php
/**
 * Status_Map: every (before, after, is_update) transition.
 *
 * @package Blt_Activity_Log
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Activity_Log_Status_Map.
 */
class Test_Status_Map extends TestCase {

	/**
	 * Every transition the spec names, plus the edges around them.
	 *
	 * @return array<string,array{0:?string,1:string,2:bool,3:?string}>
	 */
	public function transitions() {
		return array(
			'new post, published at once'      => array( null, 'publish', false, 'created' ),
			'new post, saved as draft'         => array( null, 'draft', false, 'created' ),
			'auto-draft to draft'              => array( 'auto-draft', 'draft', true, 'created' ),
			'auto-draft to publish'            => array( 'auto-draft', 'publish', true, 'created' ),
			'auto-draft to pending'            => array( 'auto-draft', 'pending', true, 'created' ),
			'status "new" to draft'            => array( 'new', 'draft', true, 'created' ),
			'auto-draft saved as auto-draft'   => array( 'auto-draft', 'auto-draft', true, null ),
			'new auto-draft'                   => array( null, 'auto-draft', false, null ),
			'draft to publish'                 => array( 'draft', 'publish', true, 'published' ),
			'pending to publish'               => array( 'pending', 'publish', true, 'published' ),
			'future to publish (cron)'         => array( 'future', 'publish', true, 'published' ),
			'private to publish'               => array( 'private', 'publish', true, 'published' ),
			'publish to publish'               => array( 'publish', 'publish', true, 'updated' ),
			'draft to draft'                   => array( 'draft', 'draft', true, 'updated' ),
			'draft to pending'                 => array( 'draft', 'pending', true, 'updated' ),
			'pending to draft'                 => array( 'pending', 'draft', true, 'updated' ),
			'publish to draft'                 => array( 'publish', 'draft', true, 'unpublished' ),
			'publish to pending'               => array( 'publish', 'pending', true, 'unpublished' ),
			'future to draft'                  => array( 'future', 'draft', true, 'unpublished' ),
			'private to draft'                 => array( 'private', 'draft', true, 'unpublished' ),
			'publish to future'                => array( 'publish', 'future', true, 'scheduled' ),
			'draft to future'                  => array( 'draft', 'future', true, 'scheduled' ),
			'future to future'                 => array( 'future', 'future', true, 'updated' ),
			'publish to private'               => array( 'publish', 'private', true, 'made_private' ),
			'draft to private'                 => array( 'draft', 'private', true, 'made_private' ),
			'publish to trash'                 => array( 'publish', 'trash', true, 'trashed' ),
			'draft to trash'                   => array( 'draft', 'trash', true, 'trashed' ),
			'trash to draft'                   => array( 'trash', 'draft', true, 'restored' ),
			'trash to publish'                 => array( 'trash', 'publish', true, 'restored' ),
			'trash to trash (edited in trash)' => array( 'trash', 'trash', true, 'updated' ),
		);
	}

	/**
	 * @dataProvider transitions
	 *
	 * @param string|null $before    Status before.
	 * @param string      $after     Status after.
	 * @param bool        $is_update Update flag.
	 * @param string|null $expected  Expected action.
	 * @return void
	 */
	public function test_transition( $before, $after, $is_update, $expected ) {
		$this->assertSame( $expected, BLT_Activity_Log_Status_Map::action( $before, $after, $is_update ) );
	}

	/**
	 * Every action the map can return is a known action key.
	 *
	 * @return void
	 */
	public function test_returned_actions_are_declared() {
		foreach ( $this->transitions() as $row ) {
			if ( null !== $row[3] ) {
				$this->assertContains( $row[3], BLT_Activity_Log_Status_Map::ACTIONS );
			}
		}
	}
}
