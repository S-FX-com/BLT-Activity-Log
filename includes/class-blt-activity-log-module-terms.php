<?php
/**
 * Module: categories, tags and other taxonomy terms.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records terms created, edited and deleted, with name, slug, parent and
 * description changes.
 *
 * Navigation menus are terms in the `nav_menu` taxonomy; they belong to the
 * Appearance module and are skipped here.
 */
class BLT_Activity_Log_Module_Terms extends BLT_Activity_Log_Module_Base {

	/**
	 * Term fields compared before and after an edit.
	 */
	const FIELDS = array( 'name', 'slug', 'parent', 'description' );

	/**
	 * Taxonomies handled elsewhere.
	 */
	const SKIPPED = array( 'nav_menu' );

	/**
	 * Terms as they were just before an edit, keyed by term ID.
	 *
	 * @var array<int,array>
	 */
	private $before = array();

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'term';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return __( 'Categories & tags', 'blt-activity-log' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function description() {
		return __( 'Categories, tags and other terms created, edited or deleted.', 'blt-activity-log' );
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
		$this->listen( 'created_term', 'on_created', 10, 3 );
		$this->listen( 'edit_terms', 'on_before_edit', 10, 2 );
		$this->listen( 'edited_term', 'on_edited', 10, 3 );
		$this->listen( 'pre_delete_term', 'on_before_delete', 10, 2 );
	}

	/**
	 * A term was created.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function on_created( $term_id, $tt_id = 0, $taxonomy = '' ) {
		unset( $tt_id );

		$term = $this->term( $term_id, $taxonomy );

		if ( ! $term ) {
			return;
		}

		$after = get_object_vars( $term );

		// A term with no parent has parent 0; that is "none", not a value that was set.
		if ( empty( $after['parent'] ) ) {
			$after['parent'] = '';
		}

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'term',
				'action'       => 'created',
				'object_type'  => (string) $taxonomy,
				'object_id'    => (int) $term_id,
				'object_label' => (string) $term->name,
				'details'      => array(
					'changes' => BLT_Activity_Log_Diff::fields( array(), $after, array( 'name', 'slug', 'parent' ) ),
				),
			)
		);
	}

	/**
	 * A term is about to be edited. Keep it as it is.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function on_before_edit( $term_id, $taxonomy = '' ) {
		$term = $this->term( $term_id, $taxonomy );

		if ( $term ) {
			$this->before[ (int) $term_id ] = get_object_vars( $term );
		}
	}

	/**
	 * A term was edited.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function on_edited( $term_id, $tt_id = 0, $taxonomy = '' ) {
		unset( $tt_id );

		$term = $this->term( $term_id, $taxonomy );

		if ( ! $term || ! isset( $this->before[ (int) $term_id ] ) ) {
			return;
		}

		$before = $this->before[ (int) $term_id ];

		unset( $this->before[ (int) $term_id ] );

		$changes = BLT_Activity_Log_Diff::fields( $before, get_object_vars( $term ), self::FIELDS );

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'term',
				'action'       => 'updated',
				'object_type'  => (string) $taxonomy,
				'object_id'    => (int) $term_id,
				'object_label' => (string) $term->name,
				'details'      => empty( $changes ) ? array() : array( 'changes' => $changes ),
				'absorb'       => array( 'created', 'updated' ),
			)
		);
	}

	/**
	 * A term is about to be deleted. Read its name while it exists.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function on_before_delete( $term_id, $taxonomy = '' ) {
		$term = $this->term( $term_id, $taxonomy );

		if ( ! $term ) {
			return;
		}

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'term',
				'action'       => 'deleted',
				'object_type'  => (string) $taxonomy,
				'object_id'    => (int) $term_id,
				'object_label' => (string) $term->name,
				'details'      => array(
					'changes' => array(
						array(
							'field'  => 'slug',
							'before' => (string) $term->slug,
							'after'  => '',
						),
					),
				),
			)
		);
	}

	/**
	 * The term, when it is one this module logs.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @return WP_Term|null
	 */
	private function term( $term_id, $taxonomy ) {
		if ( in_array( $taxonomy, self::SKIPPED, true ) ) {
			return null;
		}

		// Taxonomies with no screen of their own (a block theme's `wp_theme`, `wp_template_part_area`,
		// `post_format`) are WordPress's bookkeeping, not something a person edited.
		$object = get_taxonomy( (string) $taxonomy );

		if ( ! $object || empty( $object->show_ui ) ) {
			return null;
		}

		$term = get_term( (int) $term_id, (string) $taxonomy );

		return $term instanceof WP_Term ? $term : null;
	}
}
