<?php
/**
 * Module: menus, widgets and Customizer.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records navigation menus created, edited and deleted, menu locations,
 * widget placement, and theme settings published from the Customizer.
 */
class BLT_Activity_Log_Module_Appearance extends BLT_Activity_Log_Module_Base {

	/**
	 * Theme mods handled by their own action, or not worth a diff.
	 */
	const SKIPPED_MODS = array( 'nav_menu_locations', 'sidebars_widgets' );

	/**
	 * Item signature of each menu at the first moment this request touched it.
	 *
	 * @var array<int,array{sig:string,count:int}>
	 */
	private $menu_before = array();

	/**
	 * Names of nav menus about to be deleted, keyed by term ID.
	 *
	 * @var array<int,string>
	 */
	private $menu_names = array();

	/**
	 * Whether a theme switch is under way in this request.
	 *
	 * @var bool
	 */
	private $switching = false;

	/**
	 * Name of each menu just before this request edited it, keyed by term ID.
	 *
	 * @var array<int,string>
	 */
	private $menu_name_before = array();

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'appearance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return __( 'Menus, widgets & Customizer', 'blt-activity-log' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function description() {
		return __( 'Navigation menus, menu locations, widgets and theme settings changed in the Customizer.', 'blt-activity-log' );
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
		$this->listen( 'update_option_stylesheet', 'on_stylesheet_changed', 10, 0 );
		$this->listen( 'wp_create_nav_menu', 'on_menu_created', 10, 2 );
		$this->listen( 'wp_update_nav_menu', 'on_menu_updated', 10, 2 );
		$this->listen( 'edit_terms', 'on_before_menu_edit', 10, 2 );
		$this->listen( 'pre_delete_term', 'on_before_menu_delete', 10, 2 );
		$this->listen( 'wp_delete_nav_menu', 'on_menu_deleted', 10, 1 );
		$this->listen( 'updated_option', 'on_option_updated', 10, 3 );
		$this->listen( 'added_option', 'on_option_added', 10, 2 );
	}

	/**
	 * A menu was created.
	 *
	 * @param int        $menu_id   Menu term ID.
	 * @param array|null $menu_data Menu data as saved.
	 * @return void
	 */
	public function on_menu_created( $menu_id, $menu_data = null ) {
		unset( $menu_data );

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'appearance',
				'action'       => 'menu_created',
				'object_type'  => 'nav_menu',
				'object_id'    => (int) $menu_id,
				'object_label' => $this->menu_name( $menu_id ),
				'details'      => array(
					'changes' => array(
						array(
							'field'  => 'menu_items',
							'before' => '',
							'after'  => (string) $this->menu_item_count( $menu_id ),
						),
					),
				),
			)
		);
	}

	/**
	 * A menu is about to be edited; keep its name, which is written before the
	 * update hook fires.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function on_before_menu_edit( $term_id, $taxonomy = '' ) {
		if ( 'nav_menu' === $taxonomy && ! isset( $this->menu_name_before[ (int) $term_id ] ) ) {
			$this->menu_name_before[ (int) $term_id ] = $this->menu_name( $term_id );
		}
	}

	/**
	 * A menu was saved.
	 *
	 * WordPress fires this twice for one save from the Menus screen: once when
	 * the menu itself is updated (before its items are) and once after. The
	 * first firing keeps what the menu looked like; every firing compares.
	 *
	 * @param int        $menu_id   Menu term ID.
	 * @param array|null $menu_data Menu data, on the first firing.
	 * @return void
	 */
	public function on_menu_updated( $menu_id, $menu_data = null ) {
		unset( $menu_data );

		$menu_id = (int) $menu_id;
		$now     = $this->menu_state( $menu_id );
		$first   = ! isset( $this->menu_before[ $menu_id ] );

		if ( $first ) {
			$this->menu_before[ $menu_id ] = $now;
		}

		$changes = array();
		$name    = $this->menu_name( $menu_id );

		if ( isset( $this->menu_name_before[ $menu_id ] ) && $this->menu_name_before[ $menu_id ] !== $name ) {
			$changes[] = array(
				'field'  => 'menu_name',
				'before' => $this->menu_name_before[ $menu_id ],
				'after'  => $name,
			);
		}

		$before = $this->menu_before[ $menu_id ];

		if ( ! $first && $before['sig'] !== $now['sig'] ) {
			$change = array( 'field' => 'menu_items' );

			if ( $before['count'] !== $now['count'] ) {
				$change['before'] = (string) $before['count'];
				$change['after']  = (string) $now['count'];
			} else {
				// Same number of items, but renamed, moved or re-linked.
				$change['edited'] = true;
			}

			$changes[] = $change;
		}

		if ( empty( $changes ) ) {
			return;
		}

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'appearance',
				'action'       => 'menu_updated',
				'object_type'  => 'nav_menu',
				'object_id'    => $menu_id,
				'object_label' => $name,
				'details'      => array( 'changes' => $changes ),
			)
		);
	}

	/**
	 * A term is about to be deleted; if it is a menu, keep its name.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function on_before_menu_delete( $term_id, $taxonomy = '' ) {
		if ( 'nav_menu' === $taxonomy ) {
			$this->menu_names[ (int) $term_id ] = $this->menu_name( $term_id );
		}
	}

	/**
	 * A menu was deleted.
	 *
	 * @param int $menu_id Menu term ID.
	 * @return void
	 */
	public function on_menu_deleted( $menu_id ) {
		$menu_id = (int) $menu_id;

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'appearance',
				'action'       => 'menu_deleted',
				'object_type'  => 'nav_menu',
				'object_id'    => $menu_id,
				'object_label' => isset( $this->menu_names[ $menu_id ] ) ? $this->menu_names[ $menu_id ] : '',
			)
		);
	}

	/**
	 * An option was updated: widgets and theme mods are ours.
	 *
	 * @param string $option Option name.
	 * @param mixed  $old_value    Value before.
	 * @param mixed  $new_value    Value after.
	 * @return void
	 */
	public function on_option_updated( $option, $old_value = null, $new_value = null ) {
		$this->handle_option( (string) $option, $old_value, $new_value );
	}

	/**
	 * An option was added: a first publish on a theme adds its theme mods.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return void
	 */
	public function on_option_added( $option, $value = null ) {
		$this->handle_option( (string) $option, array(), $value );
	}

	/**
	 * Route a widget or theme-mod option change.
	 *
	 * @param string $option Option name.
	 * @param mixed  $old_value    Value before.
	 * @param mixed  $new_value    Value after.
	 * @return void
	 */
	private function handle_option( $option, $old_value, $new_value ) {
		// WordPress finishes a theme switch on the NEXT request, firing
		// after_switch_theme, which remaps menu locations and widgets to the new
		// theme. That is WordPress tidying up, not a person editing, and it would
		// otherwise be blamed on whoever happened to load the next page.
		if ( doing_action( 'after_switch_theme' ) ) {
			return;
		}

		if ( 'sidebars_widgets' === $option ) {
			$this->record_widgets( $old_value, $new_value );

			return;
		}

		if ( 0 === strpos( $option, 'theme_mods_' ) && 'theme_mods_' . get_stylesheet() === $option ) {
			$this->record_theme_mods( $old_value, $new_value );
		}
	}

	/**
	 * Widget placement changed.
	 *
	 * @param mixed $old_value Sidebars before.
	 * @param mixed $new_value Sidebars after.
	 * @return void
	 */
	private function record_widgets( $old_value, $new_value ) {
		if ( ! did_action( 'init' ) || ! is_array( $old_value ) || ! is_array( $new_value ) ) {
			return;
		}

		$changes = array();

		foreach ( array_unique( array_merge( array_keys( $old_value ), array_keys( $new_value ) ) ) as $sidebar ) {
			if ( 'array_version' === $sidebar ) {
				continue;
			}

			$before = isset( $old_value[ $sidebar ] ) && is_array( $old_value[ $sidebar ] ) ? array_values( $old_value[ $sidebar ] ) : array();
			$after  = isset( $new_value[ $sidebar ] ) && is_array( $new_value[ $sidebar ] ) ? array_values( $new_value[ $sidebar ] ) : array();

			if ( $before === $after ) {
				continue;
			}

			$changes = array_merge( $changes, BLT_Activity_Log_Diff::values( 'sidebar[' . $sidebar . ']', $before, $after ) );
		}

		// Widgets are listed by widget ID: readable as a plain, comma-joined list.
		foreach ( $changes as $index => $change ) {
			foreach ( array( 'before', 'after' ) as $side ) {
				if ( isset( $change[ $side ] ) ) {
					$decoded = json_decode( (string) $change[ $side ], true );

					if ( is_array( $decoded ) ) {
						$changes[ $index ][ $side ] = implode( ', ', array_map( 'strval', $decoded ) );
					}
				}
			}
		}

		if ( empty( $changes ) ) {
			return;
		}

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'appearance',
				'action'       => 'widgets_changed',
				'object_type'  => 'sidebars',
				'object_key'   => 'sidebars_widgets',
				'object_label' => 'sidebars_widgets',
				'details'      => array( 'changes' => $changes ),
			)
		);
	}

	/**
	 * Theme settings changed: menu locations, or Customizer settings.
	 *
	 * @param mixed $old_value Theme mods before.
	 * @param mixed $new_value Theme mods after.
	 * @return void
	 */
	private function record_theme_mods( $old_value, $new_value ) {
		$old_value = is_array( $old_value ) ? $old_value : array();
		$new_value = is_array( $new_value ) ? $new_value : array();

		// A theme switch rewrites the new theme's mods; the switch is its own entry. Its first write (the new
		// theme's mods, migrated from the old ones) comes after the stylesheet option changes and before the
		// switch_theme action fires, so neither of the other two checks sees it.
		if ( $this->switching || ! did_action( 'init' ) || did_action( 'switch_theme' ) ) {
			return;
		}

		$stylesheet = get_stylesheet();
		$theme      = wp_get_theme( $stylesheet );
		$label      = $theme->exists() ? (string) $theme->get( 'Name' ) : $stylesheet;
		$actor      = BLT_Activity_Log_Context::actor();

		$old_locations = isset( $old_value['nav_menu_locations'] ) && is_array( $old_value['nav_menu_locations'] ) ? $old_value['nav_menu_locations'] : array();
		$new_locations = isset( $new_value['nav_menu_locations'] ) && is_array( $new_value['nav_menu_locations'] ) ? $new_value['nav_menu_locations'] : array();

		$location_changes = array();

		foreach ( array_unique( array_merge( array_keys( $old_locations ), array_keys( $new_locations ) ) ) as $location ) {
			$before = isset( $old_locations[ $location ] ) ? (int) $old_locations[ $location ] : 0;
			$after  = isset( $new_locations[ $location ] ) ? (int) $new_locations[ $location ] : 0;

			if ( $before !== $after ) {
				$location_changes[] = array(
					'field'  => 'location[' . $location . ']',
					'before' => $before ? $this->menu_label( $before ) : '',
					'after'  => $after ? $this->menu_label( $after ) : '',
				);
			}
		}

		if ( ! empty( $location_changes ) ) {
			BLT_Activity_Log_Logger::record(
				array(
					'category'     => 'appearance',
					'action'       => 'menu_locations_changed',
					'object_type'  => 'theme_mods',
					'object_key'   => $stylesheet,
					'object_label' => $label,
					'details'      => array( 'changes' => $location_changes ),
				)
			);
		}

		// Only a signed-in person publishing counts: themes and plugins write
		// their own mods from cron and front-end requests all the time.
		if ( $actor['user_id'] <= 0 ) {
			return;
		}

		$mod_changes = BLT_Activity_Log_Diff::values(
			'theme_mod',
			array_diff_key( $old_value, array_flip( self::SKIPPED_MODS ) ),
			array_diff_key( $new_value, array_flip( self::SKIPPED_MODS ) )
		);

		if ( empty( $mod_changes ) ) {
			return;
		}

		$details = array( 'changes' => $mod_changes );

		if ( did_action( 'customize_save' ) ) {
			$details['via'] = 'customizer';
		}

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'appearance',
				'action'       => 'customizer_published',
				'object_type'  => 'theme_mods',
				'object_key'   => $stylesheet,
				'object_label' => $label,
				'details'      => $details,
				'absorb'       => array( 'customizer_published' ),
			)
		);
	}

	/**
	 * The active theme is being switched: its mods are about to be rewritten as part of it.
	 *
	 * @return void
	 */
	public function on_stylesheet_changed() {
		$this->switching = true;
	}

	/**
	 * A menu's name, falling back to the name captured before it was deleted.
	 *
	 * Deleting a menu removes its term first and only then clears the location
	 * that pointed at it, so the name is no longer there to look up.
	 *
	 * @param int $menu_id Menu term ID.
	 * @return string
	 */
	private function menu_label( $menu_id ) {
		$name = $this->menu_name( $menu_id );

		return ( '' === $name && isset( $this->menu_names[ (int) $menu_id ] ) ) ? $this->menu_names[ (int) $menu_id ] : $name;
	}

	/**
	 * A menu's name, or '' when it is gone.
	 *
	 * @param int $menu_id Menu term ID.
	 * @return string
	 */
	private function menu_name( $menu_id ) {
		$menu = wp_get_nav_menu_object( (int) $menu_id );

		return $menu && isset( $menu->name ) ? (string) $menu->name : '';
	}

	/**
	 * How many items a menu holds.
	 *
	 * @param int $menu_id Menu term ID.
	 * @return int
	 */
	private function menu_item_count( $menu_id ) {
		$items = wp_get_nav_menu_items( (int) $menu_id, array( 'post_status' => 'any' ) );

		return is_array( $items ) ? count( $items ) : 0;
	}

	/**
	 * A menu's item count and a signature of its items.
	 *
	 * @param int $menu_id Menu term ID.
	 * @return array{sig:string,count:int}
	 */
	private function menu_state( $menu_id ) {
		$items = wp_get_nav_menu_items( (int) $menu_id, array( 'post_status' => 'any' ) );
		$rows  = array();

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$rows[] = array(
				(int) $item->ID,
				(string) $item->title,
				(string) $item->url,
				(int) $item->menu_item_parent,
				(int) $item->menu_order,
				(string) $item->target,
				implode( ' ', array_map( 'strval', (array) $item->classes ) ),
			);
		}

		return array(
			'sig'   => md5( BLT_Activity_Log_Redactor::encode( $rows ) ),
			'count' => count( $rows ),
		);
	}
}
