<?php
/**
 * Formatter: the sentences and labels a person reads.
 *
 * @package Blt_Activity_Log
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for the parts of BLT_Activity_Log_Formatter that need no WordPress.
 */
class Test_Formatter extends TestCase {

	/**
	 * A stored row with sensible defaults.
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	private function row( array $overrides = array() ) {
		return array_merge(
			array(
				'user_id'      => 7,
				'user_login'   => 'shane',
				'category'     => 'post',
				'action'       => 'updated',
				'object_type'  => 'page',
				'object_id'    => 12,
				'object_key'   => '',
				'object_label' => 'About Us',
				'context'      => 'admin',
				'details'      => '',
			),
			$overrides
		);
	}

	/**
	 * Reset shimmed state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * Details decode from JSON, and junk decodes to nothing.
	 *
	 * @return void
	 */
	public function test_details_decoding() {
		$this->assertSame( array( 'a' => 1 ), BLT_Activity_Log_Formatter::details( $this->row( array( 'details' => '{"a":1}' ) ) ) );
		$this->assertSame( array(), BLT_Activity_Log_Formatter::details( $this->row( array( 'details' => '' ) ) ) );
		$this->assertSame( array(), BLT_Activity_Log_Formatter::details( $this->row( array( 'details' => 'not json' ) ) ) );
		$this->assertSame( array(), BLT_Activity_Log_Formatter::details( $this->row( array( 'details' => '"a string"' ) ) ) );
		$this->assertSame( array( 'b' => 2 ), BLT_Activity_Log_Formatter::details( (object) array( 'details' => '{"b":2}' ) ), 'Objects work as well as arrays.' );
	}

	/**
	 * Details of any shape come out in the documented one: what does not fit is
	 * dropped, what does is kept, and nothing is left to trip a screen.
	 *
	 * @return void
	 */
	public function test_details_are_normalized_to_the_documented_shape() {
		$odd = BLT_Activity_Log_Formatter::normalize_details(
			array(
				'changes'         => array(
					1,
					'a',
					array( 'field' => array( 'x' ) ),
					array( 'field' => '' ),
					array(
						'field'  => 'f',
						'before' => array( 'k' => 1 ),
						'after'  => 7,
						'edited' => 'yes',
					),
				),
				'summary'         => array( 'x' ),
				'method'          => 'automatic',
				'changes_omitted' => 'many',
				'versions'        => array(
					'from' => array(),
					'to'   => '2.0',
				),
				'terms'           => array(
					'category' => 'nope',
					'post_tag' => array(
						'added'   => array( 'A', array( 'x' ), 3 ),
						'removed' => 'z',
					),
				),
				'content'         => array(
					'chars'       => array( '10', 'x' ),
					'blocks_added' => array(
						'core/image' => '2',
						'core/list'  => 'many',
					),
					'revision_id' => 'abc',
				),
				'bricks'          => 'no',
			)
		);

		$this->assertSame(
			array(
				array(
					'field'  => 'f',
					'before' => '{"k":1}',
					'after'  => '7',
					'edited' => true,
				),
			),
			$odd['changes']
		);
		$this->assertArrayNotHasKey( 'summary', $odd );
		$this->assertSame( 'automatic', $odd['method'] );
		$this->assertSame( 0, $odd['changes_omitted'] );
		$this->assertSame(
			array(
				'from' => '',
				'to'   => '2.0',
			),
			$odd['versions']
		);
		$this->assertSame(
			array(
				'post_tag' => array(
					'added'   => array( 'A', '3' ),
					'removed' => array(),
				),
			),
			$odd['terms']
		);
		$this->assertSame( array( 10, 0 ), $odd['content']['chars'] );
		$this->assertSame( array( 'core/image' => 2 ), $odd['content']['blocks_added'] );
		$this->assertArrayNotHasKey( 'revision_id', $odd['content'] );
		$this->assertSame( array(), $odd['bricks'] );

		foreach ( array( null, 'text', 5, array(), array( 1, 2 ) ) as $junk ) {
			$this->assertIsArray( BLT_Activity_Log_Formatter::normalize_details( $junk ) );
		}
	}

	/**
	 * What happened to a value that is never stored (a password) is kept, so the
	 * detail screen can say "set", "changed" or "removed".
	 *
	 * @return void
	 */
	public function test_a_change_keeps_its_note() {
		$clean = BLT_Activity_Log_Formatter::normalize_details(
			array(
				'changes' => array(
					array(
						'field'    => 'post_password',
						'redacted' => true,
						'note'     => 'changed',
					),
					array(
						'field' => 'x',
						'note'  => array( 'nope' ),
					),
				),
			)
		);

		$this->assertSame( 'changed', $clean['changes'][0]['note'] );
		$this->assertArrayNotHasKey( 'note', $clean['changes'][1] );
	}

	/**
	 * `post_date` is stored in site time, so showing it must not shift it.
	 *
	 * @return void
	 */
	public function test_a_post_date_is_not_converted_as_if_it_were_utc() {
		$this->assertSame( BLT_Activity_Log_Formatter::local_time( '2026-10-20 09:00:00' ), BLT_Activity_Log_Formatter::value_label( 'post_date', '2026-10-20 09:00:00' ) );
		$this->assertSame( 'not a date', BLT_Activity_Log_Formatter::local_time( 'not a date' ) );
	}

	/**
	 * A well-formed structure passes through unchanged.
	 *
	 * @return void
	 */
	public function test_well_formed_details_are_unchanged() {
		$details = array(
			'changes'  => array(
				array(
					'field'  => 'post_title',
					'before' => 'a',
					'after'  => 'b',
				),
				array(
					'field'    => 'secret',
					'redacted' => true,
				),
			),
			'terms'    => array(
				'category' => array(
					'added'   => array( 'News' ),
					'removed' => array(),
				),
			),
			'content'  => array(
				'chars'        => array( 5, 9 ),
				'blocks_added' => array( 'core/image' => 1 ),
				'revision_id'  => 12,
			),
			'versions' => array(
				'from' => '1',
				'to'   => '2',
			),
			'method'   => 'manual',
		);

		$this->assertSame( $details, BLT_Activity_Log_Formatter::normalize_details( $details ) );
	}

	/**
	 * "1.4.2 → 1.4.3".
	 *
	 * @return void
	 */
	public function test_versions_text() {
		$this->assertSame( '1.4.2 → 1.4.3', BLT_Activity_Log_Formatter::versions_text( array( 'from' => '1.4.2', 'to' => '1.4.3' ) ) );
		$this->assertSame( '1.0', BLT_Activity_Log_Formatter::versions_text( array( 'from' => '', 'to' => '1.0' ) ), 'An install has no "from".' );
		$this->assertSame( '2.0', BLT_Activity_Log_Formatter::versions_text( array( 'from' => '2.0', 'to' => '' ) ), 'A delete has no "to".' );
	}

	/**
	 * The way an install or update happened.
	 *
	 * @return void
	 */
	public function test_method_suffix() {
		$this->assertSame( ' (automatic)', BLT_Activity_Log_Formatter::method_suffix( array( 'method' => 'automatic' ) ) );
		$this->assertSame( ' (WP-CLI)', BLT_Activity_Log_Formatter::method_suffix( array( 'method' => 'cli' ) ) );
		$this->assertSame( '', BLT_Activity_Log_Formatter::method_suffix( array() ) );
		$this->assertSame( ' (future_method)', BLT_Activity_Log_Formatter::method_suffix( array( 'method' => 'future_method' ) ), 'An unknown method is shown as it is.' );
	}

	/**
	 * "+2 blocks, −1 block" with the real minus sign.
	 *
	 * @return void
	 */
	public function test_content_short() {
		$this->assertSame(
			'content (+3 blocks, −1 block)',
			BLT_Activity_Log_Formatter::content_short(
				array(
					'blocks_added'   => array( 'core/paragraph' => 2, 'core/image' => 1 ),
					'blocks_removed' => array( 'core/heading' => 1 ),
				)
			)
		);
		$this->assertSame( 'content (+1 block)', BLT_Activity_Log_Formatter::content_short( array( 'blocks_added' => array( 'core/list' => 1 ) ) ) );
		$this->assertSame( 'content', BLT_Activity_Log_Formatter::content_short( array( 'chars' => array( 10, 12 ) ) ) );
	}

	/**
	 * "Added 2 Paragraph and 1 Image, removed 1 Heading".
	 *
	 * @return void
	 */
	public function test_content_long() {
		$this->assertSame(
			'Added 2 Paragraph and 1 Image, removed 1 Heading',
			BLT_Activity_Log_Formatter::content_long(
				array(
					'blocks_added'   => array( 'core/paragraph' => 2, 'core/image' => 1 ),
					'blocks_removed' => array( 'core/heading' => 1 ),
				)
			)
		);
		$this->assertSame( 'Removed 1 Heading', BLT_Activity_Log_Formatter::content_long( array( 'blocks_removed' => array( 'core/heading' => 1 ) ) ) );
		$this->assertSame( 'Added 1 Image', BLT_Activity_Log_Formatter::content_long( array( 'blocks_added' => array( 'core/image' => 1 ) ) ) );
		$this->assertSame( '', BLT_Activity_Log_Formatter::content_long( array( 'chars' => array( 1, 2 ) ) ) );
	}

	/**
	 * Bricks element wording.
	 *
	 * @return void
	 */
	public function test_bricks_summaries() {
		$this->assertSame( 'Bricks (+1 element, −2 elements)', BLT_Activity_Log_Formatter::bricks_short( array( 'elements_added' => array( 'section' => 1 ), 'elements_removed' => array( 'heading' => 2 ) ) ) );
		$this->assertSame( 'Bricks', BLT_Activity_Log_Formatter::bricks_short( array( 'elements_added' => array(), 'elements_removed' => array() ) ), 'A settings-only Bricks save.' );
		$this->assertSame( 'Added 1 Section, removed 2 Heading', BLT_Activity_Log_Formatter::bricks_long( array( 'elements_added' => array( 'section' => 1 ), 'elements_removed' => array( 'heading' => 2 ) ) ) );
	}

	/**
	 * A block's title falls back to a tidied name.
	 *
	 * @return void
	 */
	public function test_block_title_fallback() {
		$this->assertSame( 'Paragraph', BLT_Activity_Log_Formatter::block_title( 'core/paragraph' ) );
		$this->assertSame( 'Social links', BLT_Activity_Log_Formatter::block_title( 'core/social-links' ) );
		$this->assertSame( 'Freeform', BLT_Activity_Log_Formatter::block_title( 'core/freeform' ) );
	}

	/**
	 * The one-line summary of a plugin update.
	 *
	 * @return void
	 */
	public function test_summary_of_a_version_change() {
		$row = $this->row(
			array(
				'category' => 'plugin',
				'action'   => 'updated',
				'details'  => '{"versions":{"from":"1.4.2","to":"1.4.3"},"method":"automatic"}',
			)
		);

		$this->assertSame( '1.4.2 → 1.4.3 (automatic)', BLT_Activity_Log_Formatter::summary( $row ) );
	}

	/**
	 * The one-line summary of a post edit, in the spec's example shape.
	 *
	 * @return void
	 */
	public function test_summary_of_a_post_edit() {
		$row = $this->row(
			array(
				'details' => wp_json_encode(
					array(
						'changes' => array(
							array( 'field' => 'post_title', 'before' => 'About', 'after' => 'About Us' ),
						),
						'content' => array(
							'chars'          => array( 100, 200 ),
							'blocks_added'   => array( 'core/paragraph' => 2 ),
							'blocks_removed' => array( 'core/heading' => 1 ),
						),
						'terms'   => array( 'category' => array( 'added' => array( 'News' ), 'removed' => array() ) ),
					)
				),
			)
		);

		$this->assertSame( 'Title, content (+2 blocks, −1 block), Categories', BLT_Activity_Log_Formatter::summary( $row ) );
	}

	/**
	 * A long field list is shortened.
	 *
	 * @return void
	 */
	public function test_summary_shortens_a_long_field_list() {
		$changes = array();

		foreach ( array( 'post_title', 'post_name', 'post_excerpt', 'menu_order', 'ping_status' ) as $field ) {
			$changes[] = array( 'field' => $field, 'before' => 'a', 'after' => 'b' );
		}

		$row = $this->row( array( 'details' => wp_json_encode( array( 'changes' => $changes ) ) ) );

		$this->assertSame( 'Title, Slug, Excerpt, +2 more', BLT_Activity_Log_Formatter::summary( $row ) );
	}

	/**
	 * A status change reads "Draft → Published".
	 *
	 * @return void
	 */
	public function test_summary_of_a_status_change() {
		$row = $this->row(
			array(
				'action'  => 'published',
				'details' => '{"changes":[{"field":"post_status","before":"draft","after":"publish"}]}',
			)
		);

		$this->assertSame( 'Draft → Published', BLT_Activity_Log_Formatter::summary( $row ) );
	}

	/**
	 * An entry with nothing to summarize gives an empty line.
	 *
	 * @return void
	 */
	public function test_summary_of_nothing() {
		$this->assertSame( '', BLT_Activity_Log_Formatter::summary( $this->row( array( 'category' => 'session', 'action' => 'logged_in' ) ) ) );
	}

	/**
	 * An outside plugin's own sentence is used when it gave one.
	 *
	 * @return void
	 */
	public function test_external_summary_is_shown() {
		$row = $this->row( array( 'category' => 'other', 'action' => 'waf_rule_deployed', 'details' => '{"summary":"Deployed WAF rule."}' ) );

		$this->assertSame( 'Deployed WAF rule.', BLT_Activity_Log_Formatter::summary( $row ) );
		$this->assertSame( 'Deployed WAF rule.', BLT_Activity_Log_Formatter::headline( $row ) );
	}

	/**
	 * Headlines name who did what to which kind of thing.
	 *
	 * @return void
	 */
	public function test_headlines() {
		$this->assertSame( 'shane updated page “About Us”', BLT_Activity_Log_Formatter::headline( $this->row() ) );
		$this->assertSame(
			'shane updated the plugin “Akismet”',
			BLT_Activity_Log_Formatter::headline( $this->row( array( 'category' => 'plugin', 'object_type' => 'plugin', 'object_label' => 'Akismet' ) ) )
		);
		$this->assertSame( 'shane signed in', BLT_Activity_Log_Formatter::headline( $this->row( array( 'category' => 'session', 'action' => 'logged_in', 'object_type' => 'user' ) ) ) );
	}

	/**
	 * Who did it when there is no signed-in user.
	 *
	 * @return void
	 */
	public function test_actor_labels() {
		$this->assertSame( 'shane', BLT_Activity_Log_Formatter::actor_label( $this->row() ) );
		$this->assertSame( 'System', BLT_Activity_Log_Formatter::actor_label( $this->row( array( 'user_id' => 0, 'user_login' => '', 'context' => 'cron' ) ) ) );
		$this->assertSame( 'WP-CLI', BLT_Activity_Log_Formatter::actor_label( $this->row( array( 'user_id' => 0, 'user_login' => '', 'context' => 'cli' ) ) ) );
		$this->assertSame( 'gone-user', BLT_Activity_Log_Formatter::actor_label( $this->row( array( 'user_login' => 'gone-user' ) ) ), 'A deleted user still reads by the login snapshot.' );
	}

	/**
	 * A visitor registering has no actor, and the headline says so.
	 *
	 * @return void
	 */
	public function test_self_registration_headline() {
		$row = $this->row( array( 'category' => 'user', 'action' => 'created', 'object_type' => 'user', 'object_label' => 'newbie', 'user_id' => 0, 'user_login' => '' ) );

		$this->assertSame( 'newbie registered an account', BLT_Activity_Log_Formatter::headline( $row ) );
	}

	/**
	 * An item with no title still reads as something.
	 *
	 * @return void
	 */
	public function test_item_label_fallbacks() {
		$this->assertSame( '(no title)', BLT_Activity_Log_Formatter::item_label( $this->row( array( 'object_label' => '' ) ) ) );
		$this->assertSame( 'akismet/akismet.php', BLT_Activity_Log_Formatter::item_label( $this->row( array( 'category' => 'plugin', 'object_label' => '', 'object_key' => 'akismet/akismet.php' ) ) ) );
		$this->assertSame( 'Site title', BLT_Activity_Log_Formatter::item_label( $this->row( array( 'category' => 'setting', 'object_type' => 'option', 'object_key' => 'blogname', 'object_label' => 'blogname' ) ) ), 'An option reads by its screen label.' );
		$this->assertSame( 'Widgets', BLT_Activity_Log_Formatter::item_label( $this->row( array( 'category' => 'appearance', 'object_type' => 'sidebars', 'object_label' => 'sidebars_widgets' ) ) ) );
	}

	/**
	 * The kind of thing, lower case.
	 *
	 * @return void
	 */
	public function test_type_labels() {
		$this->assertSame( 'page', BLT_Activity_Log_Formatter::type_label( $this->row() ) );
		$this->assertSame( 'category', BLT_Activity_Log_Formatter::type_label( $this->row( array( 'category' => 'term', 'object_type' => 'category' ) ) ) );
		$this->assertSame( 'plugin', BLT_Activity_Log_Formatter::type_label( $this->row( array( 'category' => 'plugin', 'object_type' => 'plugin' ) ) ) );
		$this->assertSame( 'Event', BLT_Activity_Log_Formatter::type_label( $this->row( array( 'object_type' => 'event' ) ) ), 'An unregistered type is tidied, not lost.' );
	}

	/**
	 * Field labels, including the key-path forms.
	 *
	 * @return void
	 */
	public function test_field_labels() {
		$this->assertSame( 'Title', BLT_Activity_Log_Formatter::field_label( 'post_title' ) );
		$this->assertSame( 'Custom field “subtitle”', BLT_Activity_Log_Formatter::field_label( 'meta[subtitle]' ) );
		$this->assertSame( 'Menu location “primary”', BLT_Activity_Log_Formatter::field_label( 'location[primary]' ) );
		$this->assertSame( 'Widget area “sidebar-1”', BLT_Activity_Log_Formatter::field_label( 'sidebar[sidebar-1]' ) );
		$this->assertSame( 'Theme setting “header_textcolor”', BLT_Activity_Log_Formatter::field_label( 'theme_mod[header_textcolor]' ) );
		$this->assertSame( 'some_plugin_option', BLT_Activity_Log_Formatter::field_label( 'some_plugin_option' ), 'An unknown option shows its name.' );
	}

	/**
	 * Stored values read in words.
	 *
	 * @return void
	 */
	public function test_value_labels() {
		$this->assertSame( 'Published', BLT_Activity_Log_Formatter::value_label( 'post_status', 'publish' ) );
		$this->assertSame( 'Open', BLT_Activity_Log_Formatter::value_label( 'comment_status', 'open' ) );
		$this->assertSame( 'Closed', BLT_Activity_Log_Formatter::value_label( 'ping_status', 'closed' ) );
		$this->assertSame( '', BLT_Activity_Log_Formatter::value_label( 'post_title', '' ) );
		$this->assertSame( 'Whatever', BLT_Activity_Log_Formatter::value_label( 'post_title', 'Whatever' ) );
		$this->assertSame( '7', BLT_Activity_Log_Formatter::value_label( 'post_author', '7' ), 'A user that no longer exists shows its id.' );
	}

	/**
	 * Categories and actions have labels, and unknown ones are tidied.
	 *
	 * @return void
	 */
	public function test_category_and_action_labels() {
		$this->assertSame( 'Posts & pages', BLT_Activity_Log_Formatter::category_label( 'post' ) );
		$this->assertSame( 'Blt secure', BLT_Activity_Log_Formatter::category_label( 'blt_secure' ) );
		$this->assertSame( 'Made private', BLT_Activity_Log_Formatter::action_label( 'post', 'made_private' ) );
		$this->assertSame( 'Changed outside WordPress', BLT_Activity_Log_Formatter::action_label( 'plugin', 'changed_externally' ) );
		$this->assertSame( 'Waf rule deployed', BLT_Activity_Log_Formatter::action_label( 'blt_secure', 'waf_rule_deployed' ) );
	}

	/**
	 * Other plugins can register categories.
	 *
	 * @return void
	 */
	public function test_categories_are_filterable() {
		add_filter(
			'blt_activity_log_categories',
			function ( $categories ) {
				$categories['blt_secure'] = 'BLT Secure';

				return $categories;
			}
		);

		$this->assertSame( 'BLT Secure', BLT_Activity_Log_Formatter::category_label( 'blt_secure' ) );
	}

	/**
	 * Every action a built-in module can record has a label and, where it
	 * reads as a sentence, a headline.
	 *
	 * @return void
	 */
	public function test_every_built_in_action_is_labelled_and_has_a_headline() {
		foreach ( array( 'post', 'media', 'term', 'appearance', 'plugin', 'theme', 'core', 'setting', 'user', 'session', 'system' ) as $category ) {
			$actions = BLT_Activity_Log_Formatter::actions( $category );

			$this->assertNotEmpty( $actions, $category );

			foreach ( $actions as $action => $label ) {
				$this->assertNotSame( '', $label, "$category:$action" );

				$headline = BLT_Activity_Log_Formatter::headline( $this->row( array( 'category' => $category, 'action' => $action, 'object_type' => 'page' ) ) );

				$this->assertNotSame( '', $headline, "$category:$action" );
				$this->assertStringNotContainsString( '%', $headline, "$category:$action has an unfilled placeholder." );
			}
		}
	}

	/**
	 * Every action the modules actually emit exists in the label map.
	 *
	 * Greps the module sources for `'action' => '...'` so a new action added to
	 * a module without a label fails here, not in front of a site owner.
	 *
	 * @return void
	 */
	public function test_every_emitted_action_is_known_to_the_formatter() {
		$map = array(
			'posts'      => 'post',
			'media'      => 'media',
			'terms'      => 'term',
			'appearance' => 'appearance',
			'plugins'    => 'plugin',
			'themes'     => 'theme',
			'core'       => 'core',
			'settings'   => 'setting',
			'users'      => 'user',
			'sessions'   => 'session',
		);

		foreach ( $map as $file => $category ) {
			$source = (string) file_get_contents( BLT_ACTIVITY_LOG_DIR . 'includes/class-blt-activity-log-module-' . $file . '.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			preg_match_all( "/'action'\s*=>\s*'([a-z_]+)'/", $source, $literal );

			foreach ( array_unique( $literal[1] ) as $action ) {
				$this->assertArrayHasKey( $action, BLT_Activity_Log_Formatter::actions( $category ), "$category:$action is emitted but has no label." );
			}
		}
	}
}
