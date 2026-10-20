<?php
/**
 * Coalescer: merge rules for repeated saves.
 *
 * @package Blt_Activity_Log
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Activity_Log_Coalescer.
 */
class Test_Coalescer extends TestCase {

	/**
	 * Shorthand for a change item.
	 *
	 * @param string $field  Field.
	 * @param string $before Before.
	 * @param string $after  After.
	 * @return array
	 */
	private function change( $field, $before, $after ) {
		return array(
			'field'  => $field,
			'before' => $before,
			'after'  => $after,
		);
	}

	/**
	 * Earliest "before" and latest "after" survive.
	 *
	 * @return void
	 */
	public function test_earliest_before_and_latest_after_are_kept() {
		$merged = BLT_Activity_Log_Coalescer::merge_changes(
			array( $this->change( 'post_title', 'A', 'B' ) ),
			array( $this->change( 'post_title', 'B', 'C' ) )
		);

		$this->assertSame( array( $this->change( 'post_title', 'A', 'C' ) ), $merged );
	}

	/**
	 * A field changed and changed back is dropped.
	 *
	 * @return void
	 */
	public function test_a_field_changed_and_changed_back_is_dropped() {
		$merged = BLT_Activity_Log_Coalescer::merge_changes(
			array( $this->change( 'post_title', 'A', 'B' ) ),
			array( $this->change( 'post_title', 'B', 'A' ) )
		);

		$this->assertSame( array(), $merged );
	}

	/**
	 * Distinct fields from both saves are all kept, in order.
	 *
	 * @return void
	 */
	public function test_distinct_fields_are_all_kept() {
		$merged = BLT_Activity_Log_Coalescer::merge_changes(
			array( $this->change( 'post_title', 'A', 'B' ) ),
			array( $this->change( 'post_excerpt', '', 'x' ) )
		);

		$this->assertSame( array( 'post_title', 'post_excerpt' ), array_column( $merged, 'field' ) );
	}

	/**
	 * A redacted field stays on record: its values cannot prove a revert.
	 *
	 * @return void
	 */
	public function test_redacted_fields_are_kept() {
		$merged = BLT_Activity_Log_Coalescer::merge_changes(
			array(
				array(
					'field'    => 'mailserver_pass',
					'redacted' => true,
				),
			),
			array(
				array(
					'field'    => 'mailserver_pass',
					'redacted' => true,
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'field'    => 'mailserver_pass',
					'redacted' => true,
				),
			),
			$merged
		);
	}

	/**
	 * A shortened value cannot prove it was reverted, so it stays.
	 *
	 * @return void
	 */
	public function test_truncated_values_are_never_dropped_as_reverted() {
		$first  = array(
			'field'     => 'post_excerpt',
			'before'    => 'same-prefix',
			'after'     => 'x',
			'truncated' => array( 'before' => 5000 ),
		);
		$second = array(
			'field'  => 'post_excerpt',
			'before' => 'x',
			'after'  => 'same-prefix',
		);

		$merged = BLT_Activity_Log_Coalescer::merge_changes( array( $first ), array( $second ) );

		$this->assertCount( 1, $merged );
		$this->assertSame( array( 'before' => 5000 ), $merged[0]['truncated'] );
	}

	/**
	 * Only a repeated `updated` merges, and only into `updated` or `created`.
	 *
	 * @return void
	 */
	public function test_created_absorbs_a_following_updated() {
		$this->assertTrue( BLT_Activity_Log_Coalescer::can_absorb( 'created', 'updated' ) );
		$this->assertTrue( BLT_Activity_Log_Coalescer::can_absorb( 'updated', 'updated' ) );
		$this->assertFalse( BLT_Activity_Log_Coalescer::can_absorb( 'published', 'updated' ), 'An update after a publish is its own entry.' );
		$this->assertFalse( BLT_Activity_Log_Coalescer::can_absorb( 'trashed', 'updated' ) );
		$this->assertFalse( BLT_Activity_Log_Coalescer::can_absorb( 'updated', 'trashed' ) );
		$this->assertFalse( BLT_Activity_Log_Coalescer::can_absorb( 'created', 'created' ) );
	}

	/**
	 * Merging a new save into a stored row: count, time, details.
	 *
	 * @return void
	 */
	public function test_merge_row_into_a_created_entry() {
		$result = BLT_Activity_Log_Coalescer::merge_row(
			array(
				'action'      => 'created',
				'occurrences' => 1,
				'details'     => array( 'changes' => array( $this->change( 'post_status', '', 'draft' ) ) ),
			),
			array(
				'details'      => array( 'changes' => array( $this->change( 'post_title', '', 'Hello' ) ) ),
				'object_label' => 'Hello',
				'created_at'   => '2026-10-16 10:05:00',
			)
		);

		$this->assertSame( 2, $result['occurrences'] );
		$this->assertSame( '2026-10-16 10:05:00', $result['last_at'] );
		$this->assertSame( 'Hello', $result['object_label'] );
		$this->assertFalse( $result['drop'], 'A created entry is never deleted by a merge.' );
		$this->assertSame( array( 'post_status', 'post_title' ), array_column( $result['details']['changes'], 'field' ) );
	}

	/**
	 * A stored `updated` that merging leaves empty is deleted, not kept blank.
	 *
	 * @return void
	 */
	public function test_merge_row_drops_an_updated_that_reverted_completely() {
		$result = BLT_Activity_Log_Coalescer::merge_row(
			array(
				'action'      => 'updated',
				'occurrences' => 1,
				'details'     => array( 'changes' => array( $this->change( 'post_title', 'A', 'B' ) ) ),
			),
			array(
				'details'    => array( 'changes' => array( $this->change( 'post_title', 'B', 'A' ) ) ),
				'created_at' => '2026-10-16 10:01:00',
			)
		);

		$this->assertTrue( $result['drop'] );
	}

	/**
	 * The occurrence count is capped at the column's maximum.
	 *
	 * @return void
	 */
	public function test_occurrences_stop_at_the_column_maximum() {
		$result = BLT_Activity_Log_Coalescer::merge_row(
			array(
				'action'      => 'updated',
				'occurrences' => 65535,
				'details'     => array( 'changes' => array( $this->change( 'a', '1', '2' ) ) ),
			),
			array( 'details' => array( 'changes' => array( $this->change( 'b', '1', '2' ) ) ) )
		);

		$this->assertSame( 65535, $result['occurrences'] );
	}

	/**
	 * A save that changed nothing is not an entry; a status change always is.
	 *
	 * @return void
	 */
	public function test_an_empty_diff_is_not_an_entry() {
		$this->assertTrue( BLT_Activity_Log_Coalescer::is_empty_update( array( 'action' => 'updated', 'details' => array() ) ) );
		$this->assertTrue( BLT_Activity_Log_Coalescer::is_empty_update( array( 'action' => 'updated', 'details' => array( 'changes' => array() ) ) ) );
		$this->assertFalse( BLT_Activity_Log_Coalescer::is_empty_update( array( 'action' => 'updated', 'details' => array( 'changes' => array( $this->change( 'a', '1', '2' ) ) ) ) ) );
		$this->assertFalse( BLT_Activity_Log_Coalescer::is_empty_update( array( 'action' => 'published', 'details' => array() ) ), 'Only an `updated` can be empty.' );
		$this->assertFalse( BLT_Activity_Log_Coalescer::is_empty_update( array( 'action' => 'updated', 'details' => array( 'terms' => array( 'category' => array( 'added' => array( 'News' ), 'removed' => array() ) ) ) ) ) );
	}

	/**
	 * Content summaries: first length, last length, net block movement.
	 *
	 * @return void
	 */
	public function test_content_summaries_net_out() {
		$merged = BLT_Activity_Log_Coalescer::merge_details(
			array(
				'content' => array(
					'chars'          => array( 100, 150 ),
					'blocks_added'   => array( 'core/paragraph' => 2 ),
					'blocks_removed' => array( 'core/heading' => 1 ),
					'revision_id'    => 10,
				),
			),
			array(
				'content' => array(
					'chars'          => array( 150, 160 ),
					'blocks_added'   => array( 'core/image' => 1 ),
					'blocks_removed' => array( 'core/paragraph' => 1 ),
					'revision_id'    => 11,
				),
			)
		);

		$this->assertSame( array( 100, 160 ), $merged['content']['chars'] );
		$this->assertSame(
			array(
				'core/image'     => 1,
				'core/paragraph' => 1,
			),
			$merged['content']['blocks_added']
		);
		$this->assertSame( array( 'core/heading' => 1 ), $merged['content']['blocks_removed'] );
		$this->assertSame( 11, $merged['content']['revision_id'] );
	}

	/**
	 * Content edited and edited back leaves no content change.
	 *
	 * @return void
	 */
	public function test_content_edited_and_reverted_is_dropped() {
		$merged = BLT_Activity_Log_Coalescer::merge_details(
			array(
				'content' => array(
					'chars'        => array( 100, 110 ),
					'hashes'       => array( 'aaaa', 'bbbb' ),
					'blocks_added' => array( 'core/paragraph' => 1 ),
				),
			),
			array(
				'content' => array(
					'chars'          => array( 110, 100 ),
					'hashes'         => array( 'bbbb', 'aaaa' ),
					'blocks_removed' => array( 'core/paragraph' => 1 ),
				),
			)
		);

		$this->assertArrayNotHasKey( 'content', $merged );
	}

	/**
	 * Two same-length edits are two edits, not an edit and its undoing: "Monday" to "Friday" to "Sunday".
	 * Only the fingerprints can tell, so without them nothing is dropped either.
	 *
	 * @return void
	 */
	public function test_same_length_edits_are_not_mistaken_for_a_revert() {
		$merged = BLT_Activity_Log_Coalescer::merge_details(
			array( 'content' => array( 'chars' => array( 100, 100 ), 'hashes' => array( 'aaaa', 'bbbb' ) ) ),
			array( 'content' => array( 'chars' => array( 100, 100 ), 'hashes' => array( 'bbbb', 'cccc' ) ) )
		);

		$this->assertSame( array( 100, 100 ), $merged['content']['chars'] );
		$this->assertSame( array( 'aaaa', 'cccc' ), $merged['content']['hashes'], 'First before, last after.' );

		// Rows written without fingerprints are never dropped on a guess from the length.
		$legacy = BLT_Activity_Log_Coalescer::merge_details(
			array( 'content' => array( 'chars' => array( 100, 100 ) ) ),
			array( 'content' => array( 'chars' => array( 100, 100 ) ) )
		);

		$this->assertArrayHasKey( 'content', $legacy );
		$this->assertTrue( BLT_Activity_Log_Coalescer::has_content( $legacy ) );
	}

	/**
	 * How a change was made does not make an otherwise empty update worth keeping.
	 *
	 * @return void
	 */
	public function test_method_and_via_alone_are_not_content() {
		$this->assertFalse( BLT_Activity_Log_Coalescer::has_content( array( 'via' => 'customizer' ) ) );
		$this->assertFalse( BLT_Activity_Log_Coalescer::has_content( array( 'method' => 'automatic' ) ) );
		$this->assertTrue( BLT_Activity_Log_Coalescer::has_content( array( 'summary' => 'x' ) ) );
	}

	/**
	 * A term added then removed cancels out; others stay.
	 *
	 * @return void
	 */
	public function test_terms_added_then_removed_cancel() {
		$merged = BLT_Activity_Log_Coalescer::merge_details(
			array(
				'terms' => array(
					'category' => array(
						'added'   => array( 'News' ),
						'removed' => array( 'Uncategorized' ),
					),
				),
			),
			array(
				'terms' => array(
					'category' => array(
						'added'   => array( 'Events' ),
						'removed' => array( 'News' ),
					),
				),
			)
		);

		$this->assertSame(
			array(
				'category' => array(
					'added'   => array( 'Events' ),
					'removed' => array( 'Uncategorized' ),
				),
			),
			$merged['terms']
		);
	}

	/**
	 * Version ranges: earliest "from", latest "to"; a round trip disappears.
	 *
	 * @return void
	 */
	public function test_version_ranges() {
		$merged = BLT_Activity_Log_Coalescer::merge_details(
			array( 'versions' => array( 'from' => '1.0', 'to' => '1.1' ) ),
			array( 'versions' => array( 'from' => '1.1', 'to' => '1.2' ) )
		);

		$this->assertSame( array( 'from' => '1.0', 'to' => '1.2' ), $merged['versions'] );

		$round_trip = BLT_Activity_Log_Coalescer::merge_details(
			array( 'versions' => array( 'from' => '1.0', 'to' => '1.1' ) ),
			array( 'versions' => array( 'from' => '1.1', 'to' => '1.0' ) )
		);

		$this->assertArrayNotHasKey( 'versions', $round_trip );
	}

	/**
	 * Within one request a status change takes over a plain `updated`.
	 *
	 * @return void
	 */
	public function test_buffered_merge_upgrades_the_action() {
		$merged = BLT_Activity_Log_Coalescer::merge_buffered(
			array(
				'action'       => 'updated',
				'object_label' => 'Old title',
				'details'      => array( 'changes' => array( $this->change( 'meta[x]', '', '1' ) ) ),
			),
			array(
				'action'       => 'published',
				'object_label' => 'New title',
				'details'      => array( 'changes' => array( $this->change( 'post_status', 'draft', 'publish' ) ) ),
			)
		);

		$this->assertSame( 'published', $merged['action'] );
		$this->assertSame( 'New title', $merged['object_label'] );
		$this->assertSame( array( 'meta[x]', 'post_status' ), array_column( $merged['details']['changes'], 'field' ) );
	}

	/**
	 * A created entry keeps its action when a plain update folds into it.
	 *
	 * @return void
	 */
	public function test_buffered_merge_keeps_a_more_specific_action() {
		$merged = BLT_Activity_Log_Coalescer::merge_buffered(
			array(
				'action'  => 'created',
				'details' => array(),
			),
			array(
				'action'  => 'updated',
				'details' => array( 'changes' => array( $this->change( 'meta[x]', '', '1' ) ) ),
			)
		);

		$this->assertSame( 'created', $merged['action'] );
	}

	/**
	 * A role change that fires first is a detail of the account creation that
	 * follows, the same as a plain update is.
	 *
	 * @return void
	 */
	public function test_buffered_merge_takes_the_later_action_over_a_role_change() {
		$role = array(
			'action'  => 'role_changed',
			'details' => array( 'changes' => array( $this->change( 'role', '', 'editor' ) ) ),
		);

		$merged = BLT_Activity_Log_Coalescer::merge_buffered( $role, array( 'action' => 'created', 'details' => array() ) );

		$this->assertSame( 'created', $merged['action'] );

		// Between two detail actions the earlier one stays.
		$merged = BLT_Activity_Log_Coalescer::merge_buffered( $role, array( 'action' => 'updated', 'details' => array() ) );

		$this->assertSame( 'role_changed', $merged['action'] );
	}

	/**
	 * Flag-only changes (reordered, edited) merge into one.
	 *
	 * @return void
	 */
	public function test_flag_only_changes_merge() {
		foreach ( array( 'order_changed', 'edited' ) as $flag ) {
			$item = array(
				'field' => 'menu_items',
				$flag   => true,
			);

			$this->assertSame( array( $item ), BLT_Activity_Log_Coalescer::merge_changes( array( $item ), array( $item ) ), $flag );
		}
	}

	/**
	 * A save_post callback that saves the post again fires its hook first, and the outer save reports a
	 * stale before and after. The change is still Orig to Generated, not dropped.
	 *
	 * @return void
	 */
	public function test_a_nested_save_is_put_in_time_order() {
		$inner = array( 'field' => 'post_title', 'before' => 'User title', 'after' => 'Generated title' );
		$outer = array( 'field' => 'post_title', 'before' => 'Orig title', 'after' => 'User title' );

		$merged = BLT_Activity_Log_Coalescer::merge_changes( array( $inner ), array( $outer ) );

		$this->assertCount( 1, $merged );
		$this->assertSame( 'Orig title', $merged[0]['before'] );
		$this->assertSame( 'Generated title', $merged[0]['after'] );

		// The ordinary order, and a genuine revert, are untouched.
		$this->assertSame( 'Generated title', BLT_Activity_Log_Coalescer::merge_changes( array( $outer ), array( $inner ) )[0]['after'] );
		$this->assertSame( array(), BLT_Activity_Log_Coalescer::merge_changes( array( array( 'field' => 'f', 'before' => 'A', 'after' => 'B' ) ), array( array( 'field' => 'f', 'before' => 'B', 'after' => 'A' ) ) ) );
	}

	/**
	 * The same content save reported twice is one change; the blocks are not doubled.
	 *
	 * @return void
	 */
	public function test_the_same_content_save_reported_twice_counts_once() {
		$save = array(
			'chars'        => array( 100, 150 ),
			'hashes'       => array( 'aaaa', 'bbbb' ),
			'blocks_added' => array( 'core/image' => 1 ),
		);

		$merged = BLT_Activity_Log_Coalescer::merge_details( array( 'content' => $save ), array( 'content' => $save ) );

		$this->assertSame( array( 'core/image' => 1 ), $merged['content']['blocks_added'] );
		$this->assertSame( array( 100, 150 ), $merged['content']['chars'] );

		// Two different saves still add up.
		$next = array(
			'chars'        => array( 150, 200 ),
			'hashes'       => array( 'bbbb', 'cccc' ),
			'blocks_added' => array( 'core/image' => 1 ),
		);

		$both = BLT_Activity_Log_Coalescer::merge_details( array( 'content' => $save ), array( 'content' => $next ) );

		$this->assertSame( array( 'core/image' => 2 ), $both['content']['blocks_added'] );
	}

	/**
	 * Nested content saves arrive inner first; the content summary is put in order as well.
	 *
	 * @return void
	 */
	public function test_nested_content_saves_are_put_in_time_order() {
		$inner = array( 'chars' => array( 120, 130 ), 'hashes' => array( 'user', 'generated' ) );
		$outer = array( 'chars' => array( 100, 120 ), 'hashes' => array( 'orig', 'user' ) );

		$merged = BLT_Activity_Log_Coalescer::merge_details( array( 'content' => $inner ), array( 'content' => $outer ) );

		$this->assertSame( array( 100, 130 ), $merged['content']['chars'] );
		$this->assertSame( array( 'orig', 'generated' ), $merged['content']['hashes'] );
	}
}
