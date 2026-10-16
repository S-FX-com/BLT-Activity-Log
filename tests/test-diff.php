<?php
/**
 * Diff: scalar equality, key paths, lists, histograms.
 *
 * @package Blt_Activity_Log
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Activity_Log_Diff.
 */
class Test_Diff extends TestCase {

	/**
	 * Reset shimmed state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
		BLT_Activity_Log_Redactor::reset();
	}

	/**
	 * Scalars compare as strings.
	 *
	 * @return void
	 */
	public function test_scalars_are_equal_as_strings() {
		$this->assertTrue( BLT_Activity_Log_Diff::equal( 1, '1' ) );
		$this->assertTrue( BLT_Activity_Log_Diff::equal( 1.0, '1' ) );
		$this->assertTrue( BLT_Activity_Log_Diff::equal( true, '1' ) );
		$this->assertTrue( BLT_Activity_Log_Diff::equal( false, '' ) );
		$this->assertTrue( BLT_Activity_Log_Diff::equal( null, '' ) );
		$this->assertFalse( BLT_Activity_Log_Diff::equal( 1, '2' ) );
		$this->assertFalse( BLT_Activity_Log_Diff::equal( '0', '' ) );
		$this->assertSame( array(), BLT_Activity_Log_Diff::values( 'posts_per_page', 10, '10' ) );
	}

	/**
	 * Identical structures give an empty diff.
	 *
	 * @return void
	 */
	public function test_identical_inputs_give_an_empty_diff() {
		$value = array(
			'a' => array( 'b' => array( 'c' => 1 ) ),
			'l' => array( 'x', 'y' ),
		);

		$this->assertSame( array(), BLT_Activity_Log_Diff::values( 'opt', $value, $value ) );
	}

	/**
	 * A changed scalar is one change with both values as strings.
	 *
	 * @return void
	 */
	public function test_scalar_change() {
		$this->assertSame(
			array(
				array(
					'field'  => 'blogname',
					'before' => 'Old',
					'after'  => 'New',
				),
			),
			BLT_Activity_Log_Diff::values( 'blogname', 'Old', 'New' )
		);
	}

	/**
	 * Associative arrays are walked by key path.
	 *
	 * @return void
	 */
	public function test_nested_key_paths() {
		$changes = BLT_Activity_Log_Diff::values(
			'opt',
			array(
				'a' => array(
					'b' => 1,
					'c' => 2,
				),
				'd' => 'same',
			),
			array(
				'a' => array(
					'b' => 1,
					'c' => 3,
				),
				'd' => 'same',
			)
		);

		$this->assertCount( 1, $changes );
		$this->assertSame( 'opt[a][c]', $changes[0]['field'] );
		$this->assertSame( '2', $changes[0]['before'] );
		$this->assertSame( '3', $changes[0]['after'] );
	}

	/**
	 * A key added or removed is a change against an empty value.
	 *
	 * @return void
	 */
	public function test_added_and_removed_keys() {
		$changes = BLT_Activity_Log_Diff::values(
			'opt',
			array( 'gone' => 'x' ),
			array( 'new' => 'y' )
		);

		$by_field = array();

		foreach ( $changes as $change ) {
			$by_field[ $change['field'] ] = $change;
		}

		$this->assertSame( '', $by_field['opt[new]']['before'] );
		$this->assertSame( 'y', $by_field['opt[new]']['after'] );
		$this->assertSame( 'x', $by_field['opt[gone]']['before'] );
		$this->assertSame( '', $by_field['opt[gone]']['after'] );
	}

	/**
	 * The walk stops at three key levels; deeper arrays are compared whole.
	 *
	 * @return void
	 */
	public function test_depth_limit_of_three() {
		$changes = BLT_Activity_Log_Diff::values(
			'o',
			array( 'a' => array( 'b' => array( 'c' => array( 'd' => 1 ) ) ) ),
			array( 'a' => array( 'b' => array( 'c' => array( 'd' => 2 ) ) ) )
		);

		$this->assertCount( 1, $changes );
		$this->assertSame( 'o[a][b][c]', $changes[0]['field'], 'Three levels of keys, then the rest as one value.' );
		$this->assertSame( '{"d":1}', $changes[0]['before'] );
		$this->assertSame( '{"d":2}', $changes[0]['after'] );
	}

	/**
	 * A list with the same members in another order is "order changed".
	 *
	 * @return void
	 */
	public function test_reordered_list_reports_order_changed() {
		$this->assertSame(
			array(
				array(
					'field'         => 'o[menu]',
					'order_changed' => true,
				),
			),
			BLT_Activity_Log_Diff::values( 'o', array( 'menu' => array( 'a', 'b', 'c' ) ), array( 'menu' => array( 'c', 'a', 'b' ) ) )
		);
	}

	/**
	 * An option that did not exist yet compares as an empty map, so its keys are
	 * reported one by one (a first Customizer publish adds the theme's mods).
	 *
	 * @return void
	 */
	public function test_an_empty_array_is_walked_like_a_map() {
		$changes = BLT_Activity_Log_Diff::values( 'theme_mod', array(), array( 'header_textcolor' => 'ff0000', 'background' => 'fff' ) );

		$this->assertSame( array( 'theme_mod[header_textcolor]', 'theme_mod[background]' ), array_column( $changes, 'field' ) );
		$this->assertSame( '', $changes[0]['before'] );
		$this->assertSame( 'ff0000', $changes[0]['after'] );

		$removed = BLT_Activity_Log_Diff::values( 'opt', array( 'a' => 1 ), array() );

		$this->assertSame( 'opt[a]', $removed[0]['field'] );
		$this->assertSame( '', $removed[0]['after'] );
	}

	/**
	 * A map that appears under a key, or disappears from it, is walked like one
	 * that changes: each leaf is its own change and a secret in it is named.
	 *
	 * @return void
	 */
	public function test_a_map_that_appears_or_disappears_is_walked() {
		$added = BLT_Activity_Log_Diff::values( 'o', array( 'a' => 1 ), array( 'a' => 1, 'nested' => array( 'level' => 2, 'client_secret' => 'x' ) ) );

		$this->assertSame( array( 'o[nested][level]', 'o[nested][client_secret]' ), array_column( $added, 'field' ) );
		$this->assertSame( '', $added[0]['before'] );
		$this->assertSame( '2', $added[0]['after'] );

		$removed = BLT_Activity_Log_Diff::values( 'o', array( 'nested' => array( 'level' => 2 ) ), array() );

		$this->assertSame( 'o[nested][level]', $removed[0]['field'] );
		$this->assertSame( '2', $removed[0]['before'] );
		$this->assertSame( '', $removed[0]['after'] );

		$deleted = BLT_Activity_Log_Diff::values( 'opt', array( 'color' => 'green' ), '' );

		$this->assertSame( array( 'opt[color]' ), array_column( $deleted, 'field' ) );
		$this->assertSame( 'green', $deleted[0]['before'] );
		$this->assertSame( '', $deleted[0]['after'] );

		// A list is not a map: it stays one change.
		$list = BLT_Activity_Log_Diff::values( 'opt', '', array( 'a', 'b' ) );

		$this->assertSame( array( 'opt' ), array_column( $list, 'field' ) );
	}

	/**
	 * An empty list against a populated list is one change, not a walk.
	 *
	 * @return void
	 */
	public function test_an_empty_array_against_a_list_is_one_change() {
		$changes = BLT_Activity_Log_Diff::values( 'sidebar', array(), array( 'search-2' ) );

		$this->assertCount( 1, $changes );
		$this->assertSame( 'sidebar', $changes[0]['field'] );
		$this->assertSame( '[]', $changes[0]['before'] );
		$this->assertSame( '["search-2"]', $changes[0]['after'] );
	}

	/**
	 * A list whose members changed is compared as one value.
	 *
	 * @return void
	 */
	public function test_changed_list_is_one_change() {
		$changes = BLT_Activity_Log_Diff::values( 'list', array( 'a', 'b' ), array( 'a', 'c' ) );

		$this->assertCount( 1, $changes );
		$this->assertSame( '["a","b"]', $changes[0]['before'] );
		$this->assertSame( '["a","c"]', $changes[0]['after'] );
	}

	/**
	 * Secrets inside a compared structure are masked in the stored text but a
	 * change to one still registers.
	 *
	 * @return void
	 */
	public function test_secret_inside_structure_is_masked_but_still_a_change() {
		$changes = BLT_Activity_Log_Diff::values( 'opt', array( 'list' => array( array( 'api_key' => 'a' ) ) ), array( 'list' => array( array( 'api_key' => 'b' ) ) ) );

		$this->assertNotEmpty( $changes, 'A secret changing is still a change.' );
		$this->assertStringNotContainsString( '"a"', $changes[0]['before'] );
		$this->assertStringContainsString( BLT_Activity_Log_Redactor::MASK, $changes[0]['before'] );
	}

	/**
	 * Field comparison over a fixed list of keys.
	 *
	 * @return void
	 */
	public function test_fields_compares_named_keys_only() {
		$changes = BLT_Activity_Log_Diff::fields(
			array(
				'post_title'  => 'About',
				'menu_order'  => 0,
				'ignored'     => 'a',
			),
			array(
				'post_title'  => 'About Us',
				'menu_order'  => '0',
				'ignored'     => 'b',
			),
			array( 'post_title', 'menu_order' )
		);

		$this->assertSame(
			array(
				array(
					'field'  => 'post_title',
					'before' => 'About',
					'after'  => 'About Us',
				),
			),
			$changes
		);
	}

	/**
	 * Block counts are recursive and ignore the whitespace between blocks.
	 *
	 * @return void
	 */
	public function test_block_histogram_nested_and_whitespace_freeform_ignored() {
		$blocks = array(
			array(
				'blockName'   => 'core/group',
				'innerHTML'   => '',
				'innerBlocks' => array(
					array(
						'blockName'   => 'core/paragraph',
						'innerHTML'   => '<p>a</p>',
						'innerBlocks' => array(),
					),
					array(
						'blockName'   => null,
						'innerHTML'   => "\n\n",
						'innerBlocks' => array(),
					),
					array(
						'blockName'   => 'core/paragraph',
						'innerHTML'   => '<p>b</p>',
						'innerBlocks' => array(),
					),
				),
			),
			array(
				'blockName'   => null,
				'innerHTML'   => "\n",
				'innerBlocks' => array(),
			),
			array(
				'blockName'   => null,
				'innerHTML'   => '<p>classic content</p>',
				'innerBlocks' => array(),
			),
		);

		$this->assertSame(
			array(
				'core/freeform'  => 1,
				'core/group'     => 1,
				'core/paragraph' => 2,
			),
			BLT_Activity_Log_Diff::block_histogram( $blocks )
		);
	}

	/**
	 * What a histogram gained and lost.
	 *
	 * @return void
	 */
	public function test_histogram_delta() {
		$delta = BLT_Activity_Log_Diff::histogram_delta(
			array(
				'core/paragraph' => 3,
				'core/heading'   => 1,
				'core/list'      => 2,
			),
			array(
				'core/paragraph' => 5,
				'core/image'     => 1,
				'core/list'      => 2,
			)
		);

		$this->assertSame(
			array(
				'core/image'     => 1,
				'core/paragraph' => 2,
			),
			$delta['added']
		);
		$this->assertSame( array( 'core/heading' => 1 ), $delta['removed'] );
	}

	/**
	 * Identical histograms move nothing.
	 *
	 * @return void
	 */
	public function test_histogram_delta_of_equal_histograms_is_empty() {
		$h = array( 'core/paragraph' => 3 );

		$this->assertSame(
			array(
				'added'   => array(),
				'removed' => array(),
			),
			BLT_Activity_Log_Diff::histogram_delta( $h, $h )
		);
	}

	/**
	 * Bricks elements are counted by name, flat or nested.
	 *
	 * @return void
	 */
	public function test_elements_histogram() {
		$flat = array(
			array(
				'id'   => 'a',
				'name' => 'section',
			),
			array(
				'id'   => 'b',
				'name' => 'heading',
			),
			array(
				'id'   => 'c',
				'name' => 'heading',
			),
			array( 'id' => 'd' ),
		);

		$this->assertSame(
			array(
				'heading' => 2,
				'section' => 1,
			),
			BLT_Activity_Log_Diff::elements_histogram( $flat )
		);

		$nested = array(
			array(
				'name'     => 'section',
				'children' => array(
					array( 'name' => 'heading' ),
				),
			),
		);

		$this->assertSame(
			array(
				'heading' => 1,
				'section' => 1,
			),
			BLT_Activity_Log_Diff::elements_histogram( $nested )
		);

		$ids_only = array(
			array(
				'name'     => 'section',
				'children' => array( 'abc', 'def' ),
			),
		);

		$this->assertSame( array( 'section' => 1 ), BLT_Activity_Log_Diff::elements_histogram( $ids_only ), 'Child ids are not elements.' );
	}

	/**
	 * Term names added and removed.
	 *
	 * @return void
	 */
	public function test_names() {
		$this->assertSame(
			array(
				'added'   => array( 'News' ),
				'removed' => array( 'Uncategorized' ),
			),
			BLT_Activity_Log_Diff::names( array( 'Uncategorized', 'Events' ), array( 'Events', 'News' ) )
		);
	}

	/**
	 * List detection.
	 *
	 * @return void
	 */
	public function test_is_list() {
		$this->assertTrue( BLT_Activity_Log_Diff::is_list( array() ) );
		$this->assertTrue( BLT_Activity_Log_Diff::is_list( array( 'a', 'b' ) ) );
		$this->assertFalse( BLT_Activity_Log_Diff::is_list( array( 1 => 'a' ) ) );
		$this->assertFalse( BLT_Activity_Log_Diff::is_list( array( 'k' => 'a' ) ) );
	}
}
