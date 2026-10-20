<?php
/**
 * Admin controller: menu, screens, settings, clear log and CSV export.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the wp-admin experience: one page under Tools with a Log tab and a
 * Settings tab. Every filter state, tab and entry is its own URL, and nothing
 * on the screen needs JavaScript.
 */
class BLT_Activity_Log_Admin {

	const MENU_SLUG       = 'blt-activity-log';
	const PER_PAGE_OPTION = 'blt_activity_log_per_page';

	const NONCE_SETTINGS = 'blt_activity_log_settings';
	const NONCE_CLEAR    = 'blt_activity_log_clear';
	const NONCE_EXPORT   = 'blt_activity_log_export';
	const NONCE_DUMP     = 'blt_activity_log_dump';

	/**
	 * Distinct users above which the User filter becomes a text field.
	 */
	const USER_SELECT_LIMIT = 200;

	/**
	 * Largest export, in rows. Filterable.
	 */
	const EXPORT_LIMIT = 50000;

	/**
	 * Rows read per export batch.
	 */
	const EXPORT_BATCH = 1000;

	/**
	 * Rows read per dump batch. Smaller than the export's: a dump reads every
	 * column, and an entry's details can run to 64 KB.
	 */
	const DUMP_BATCH = 250;

	/**
	 * Singleton instance.
	 *
	 * @var BLT_Activity_Log_Admin|null
	 */
	private static $instance = null;

	/**
	 * Hook suffix of the page, for asset gating.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * The shared instance.
	 *
	 * @return BLT_Activity_Log_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_blt_activity_log_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_blt_activity_log_dump', array( $this, 'handle_dump' ) );
		add_filter( 'plugin_action_links_' . BLT_ACTIVITY_LOG_BASENAME, array( $this, 'plugin_action_links' ) );
		add_filter( 'set_screen_option_' . self::PER_PAGE_OPTION, array( $this, 'set_screen_option' ), 10, 3 );
	}

	/**
	 * Add the page under Tools.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->page_hook = (string) add_management_page(
			__( 'BLT Activity Log', 'blt-activity-log' ),
			__( 'BLT Activity Log', 'blt-activity-log' ),
			BLT_Activity_Log_Install::CAP,
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		add_action( 'load-' . $this->page_hook, array( $this, 'on_load' ) );
	}

	/**
	 * Runs when the page is about to render: screen options, and a nudge to
	 * cron if it has not pruned lately. The pruning itself never happens here.
	 *
	 * @return void
	 */
	public function on_load() {
		if ( 'settings' !== $this->current_tab() ) {
			add_screen_option(
				'per_page',
				array(
					'label'   => __( 'Entries per page', 'blt-activity-log' ),
					'default' => BLT_Activity_Log_Query::DEFAULT_PER_PAGE,
					'option'  => self::PER_PAGE_OPTION,
				)
			);
		}

		BLT_Activity_Log_Pruner::maybe_request_overdue();
	}

	/**
	 * Save the Screen Options per-page value.
	 *
	 * @param mixed  $value  Value so far (false when unhandled).
	 * @param string $option Option name.
	 * @param mixed  $input  Submitted value.
	 * @return int
	 */
	public function set_screen_option( $value, $option, $input ) {
		unset( $value, $option );

		return max( 1, min( BLT_Activity_Log_Query::MAX_PER_PAGE, (int) $input ) );
	}

	/**
	 * "View log" and "Settings" on the Plugins row.
	 *
	 * @param array<int,string> $links Existing links.
	 * @return array<int,string>
	 */
	public function plugin_action_links( $links ) {
		$mine = array(
			'<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'View log', 'blt-activity-log' ) . '</a>',
		);

		if ( current_user_can( 'manage_options' ) ) {
			$mine[] = '<a href="' . esc_url( self::page_url( array( 'tab' => 'settings' ) ) ) . '">' . esc_html__( 'Settings', 'blt-activity-log' ) . '</a>';
		}

		return array_merge( $mine, $links );
	}

	/**
	 * Load the design system and this screen's own assets, on this page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( '' === $this->page_hook || $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'blt-activity-log-design-system',
			BLT_ACTIVITY_LOG_URL . 'assets/css/blt-design-system.css',
			array(),
			BLT_ACTIVITY_LOG_VERSION
		);

		wp_enqueue_style(
			'blt-activity-log-admin',
			BLT_ACTIVITY_LOG_URL . 'admin/assets/blt-activity-log-admin.css',
			array( 'blt-activity-log-design-system' ),
			BLT_ACTIVITY_LOG_VERSION
		);

		wp_enqueue_script(
			'blt-activity-log-admin',
			BLT_ACTIVITY_LOG_URL . 'admin/assets/blt-activity-log-admin.js',
			array(),
			BLT_ACTIVITY_LOG_VERSION,
			true
		);
	}

	/**
	 * The URL of the page, with optional query arguments.
	 *
	 * @param array<string,mixed> $args Extra query arguments.
	 * @return string
	 */
	public static function page_url( array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::MENU_SLUG ), $args ),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * The arguments that make a log view reproducible as a URL: the filters,
	 * a non-default sort and the page. Defaults are left out.
	 *
	 * @param array $args Normalized arguments.
	 * @return array<string,mixed>
	 */
	public static function state_args( array $args ) {
		$state = array();

		foreach ( array( 'activity', 'from', 'to', 's' ) as $key ) {
			if ( '' !== (string) $args[ $key ] ) {
				$state[ $key ] = $args[ $key ];
			}
		}

		if ( '' !== (string) $args['user'] ) {
			$state['user'] = 0 === strpos( $args['user'], 'login:' ) ? substr( $args['user'], 6 ) : $args['user'];
		}

		if ( 'when' !== $args['orderby'] || 'desc' !== $args['order'] ) {
			$state['orderby'] = $args['orderby'];
			$state['order']   = $args['order'];
		}

		if ( $args['paged'] > 1 ) {
			$state['paged'] = $args['paged'];
		}

		return $state;
	}

	/**
	 * Which tab is open. Settings is for `manage_options` only.
	 *
	 * @return string `log` or `settings`.
	 */
	public function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'log';

		return ( 'settings' === $tab && current_user_can( 'manage_options' ) ) ? 'settings' : 'log';
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( BLT_Activity_Log_Install::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view the activity log.', 'blt-activity-log' ) );
		}

		if ( 'settings' === $this->current_tab() ) {
			$this->render_settings();

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$entry_id = isset( $_GET['entry'] ) ? absint( wp_unslash( $_GET['entry'] ) ) : 0;

		if ( $entry_id > 0 ) {
			$this->render_entry( $entry_id );

			return;
		}

		$this->render_log();
	}

	/**
	 * The Log tab.
	 *
	 * @return void
	 */
	private function render_log() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only filters, normalized by Query::normalize_args().
		$args  = BLT_Activity_Log_Query::normalize_args( wp_unslash( $_GET ) );
		$table = new BLT_Activity_Log_List_Table( $args );

		$table->prepare_items();

		$total      = (int) $table->get_pagination_arg( 'total_items' );
		$summary    = BLT_Activity_Log_Query::summary();
		$state      = self::state_args( $args );
		$filtered   = ! empty( array_intersect_key( $state, array_flip( array( 'activity', 'user', 'from', 'to', 's' ) ) ) );
		$activities = $this->activity_options();
		$users      = BLT_Activity_Log_Query::distinct_users( self::USER_SELECT_LIMIT + 1 );
		$presets    = $this->date_presets( $state );
		$can_export = current_user_can( 'manage_options' );

		$export_url = $can_export ? wp_nonce_url(
			add_query_arg(
				array_merge(
					array_intersect_key( $state, array_flip( array( 'activity', 'user', 'from', 'to', 's' ) ) ),
					array( 'action' => 'blt_activity_log_export' )
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_EXPORT
		) : '';

		$tab = 'log';
		$sub = __( 'Log', 'blt-activity-log' );

		require BLT_ACTIVITY_LOG_DIR . 'admin/views/log.php';
	}

	/**
	 * One entry's detail screen.
	 *
	 * @param int $entry_id Entry ID.
	 * @return void
	 */
	private function render_entry( $entry_id ) {
		$row = BLT_Activity_Log_Query::get( $entry_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only filters, normalized by Query::normalize_args().
		$back_args = self::state_args( BLT_Activity_Log_Query::normalize_args( wp_unslash( $_GET ) ) );
		$back_url  = self::page_url( $back_args );

		$tab = 'log';
		$sub = __( 'Entry', 'blt-activity-log' );

		if ( ! $row ) {
			require BLT_ACTIVITY_LOG_DIR . 'admin/views/entry-missing.php';

			return;
		}

		$details = BLT_Activity_Log_Formatter::details( $row );
		$links   = $this->entry_links( $row );

		require BLT_ACTIVITY_LOG_DIR . 'admin/views/entry.php';
	}

	/**
	 * The Settings tab.
	 *
	 * @return void
	 */
	private function render_settings() {
		$settings = BLT_Activity_Log_Settings::all();
		$summary  = BLT_Activity_Log_Query::summary();
		$state    = BLT_Activity_Log_Settings::state();
		$modules  = BLT_Activity_Log_Modules::toggleable();
		$locked   = BLT_Activity_Log_Settings::retention_locked();

		$update_url = class_exists( 'BLT_Family_Updates' ) ? BLT_Family_Updates::check_now_url( 'blt-activity-log' ) : '';
		$last_check = ( '' !== $update_url && isset( $GLOBALS['blt_activity_log_update_checker'] ) )
			? BLT_Family_Updates::last_check_time( $GLOBALS['blt_activity_log_update_checker'] )
			: 0;

		$clear    = $this->clear_preview();
		$dump_url = wp_nonce_url( admin_url( 'admin-post.php?action=blt_activity_log_dump' ), self::NONCE_DUMP );

		$tab = 'settings';
		$sub = __( 'Settings', 'blt-activity-log' );

		require BLT_ACTIVITY_LOG_DIR . 'admin/views/settings.php';
	}

	/**
	 * What the Clear log card should show: the form, or the preview with the
	 * exact number of entries that would go.
	 *
	 * @return array{stage:string,scope:string,before:string,count:int}
	 */
	private function clear_preview() {
		$none = array(
			'stage'  => 'form',
			'scope'  => 'all',
			'before' => '',
			'count'  => 0,
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only: deleting needs a POST with its own nonce.
		if ( empty( $_GET['clear'] ) || 'preview' !== sanitize_key( wp_unslash( $_GET['clear'] ) ) ) {
			return $none;
		}

		$scope  = ( isset( $_GET['clear_scope'] ) && 'before' === sanitize_key( wp_unslash( $_GET['clear_scope'] ) ) ) ? 'before' : 'all';
		$before = isset( $_GET['clear_before'] ) ? sanitize_text_field( wp_unslash( $_GET['clear_before'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'before' === $scope ) {
			$cutoff = $this->local_day_start( $before );

			if ( null === $cutoff ) {
				return $none;
			}

			return array(
				'stage'  => 'preview',
				'scope'  => 'before',
				'before' => $before,
				'count'  => BLT_Activity_Log_Query::count_before( $cutoff ),
			);
		}

		$summary = BLT_Activity_Log_Query::summary();

		return array(
			'stage'  => 'preview',
			'scope'  => 'all',
			'before' => '',
			'count'  => $summary['total'],
		);
	}

	/**
	 * The activity choices for the filter: one group per category, with the
	 * whole category first and then each action.
	 *
	 * @return array<string,array{label:string,actions:array<string,string>}>
	 */
	private function activity_options() {
		$present = array();

		foreach ( BLT_Activity_Log_Query::activity_pairs() as $pair ) {
			$present[ (string) $pair->category ][] = (string) $pair->action;
		}

		$enabled = array( 'system' );

		foreach ( BLT_Activity_Log_Modules::all() as $module ) {
			if ( BLT_Activity_Log_Modules::is_enabled( $module ) ) {
				$enabled[] = $module->id();
			}
		}

		$options = array();

		foreach ( BLT_Activity_Log_Formatter::categories() as $category => $label ) {
			if ( ! isset( $present[ $category ] ) && ! in_array( $category, $enabled, true ) ) {
				continue;
			}

			$actions = BLT_Activity_Log_Formatter::actions( $category );

			foreach ( isset( $present[ $category ] ) ? $present[ $category ] : array() as $action ) {
				if ( ! isset( $actions[ $action ] ) ) {
					$actions[ $action ] = BLT_Activity_Log_Formatter::action_label( $category, $action );
				}
			}

			$options[ $category ] = array(
				'label'   => $label,
				'actions' => $actions,
			);
		}

		return $options;
	}

	/**
	 * The Today / Yesterday / Last 7 days / Last 30 days links.
	 *
	 * Dates are the site's, so "today" is the site's today.
	 *
	 * @param array $state Current filters as URL arguments.
	 * @return array<string,string> Label => URL.
	 */
	private function date_presets( array $state ) {
		$base  = $state;
		$today = current_datetime();

		unset( $base['from'], $base['to'], $base['paged'] );

		$make = function ( DateTimeImmutable $from, DateTimeImmutable $to ) use ( $base ) {
			return self::page_url(
				array_merge(
					$base,
					array(
						'from' => $from->format( 'Y-m-d' ),
						'to'   => $to->format( 'Y-m-d' ),
					)
				)
			);
		};

		return array(
			__( 'Today', 'blt-activity-log' )        => $make( $today, $today ),
			__( 'Yesterday', 'blt-activity-log' )    => $make( $today->modify( '-1 day' ), $today->modify( '-1 day' ) ),
			__( 'Last 7 days', 'blt-activity-log' )  => $make( $today->modify( '-6 days' ), $today ),
			__( 'Last 30 days', 'blt-activity-log' ) => $make( $today->modify( '-29 days' ), $today ),
		);
	}

	/**
	 * Links on an entry's detail screen: who, the item, and related activity.
	 *
	 * @param object $row Stored row.
	 * @return array{user_url:string,item_url:string,item_gone:bool,item_activity:string,user_activity:string,revision_url:string}
	 */
	private function entry_links( $row ) {
		$links = array(
			'user_url'      => '',
			'item_url'      => '',
			'item_gone'     => false,
			'item_activity' => '',
			'user_activity' => self::page_url( array( 'user' => (int) $row->user_id > 0 ? (string) (int) $row->user_id : 'none' ) ),
			'revision_url'  => '',
		);

		if ( (int) $row->user_id > 0 && get_userdata( (int) $row->user_id ) && current_user_can( 'edit_user', (int) $row->user_id ) ) {
			$links['user_url'] = get_edit_user_link( (int) $row->user_id );
		}

		if ( '' !== (string) $row->object_label ) {
			$links['item_activity'] = self::page_url(
				array(
					'activity' => (string) $row->category,
					's'        => (string) $row->object_label,
				)
			);
		}

		$id   = null !== $row->object_id ? (int) $row->object_id : 0;
		$type = (string) $row->object_type;

		switch ( (string) $row->category ) {
			case 'post':
			case 'media':
				$post = $id ? get_post( $id ) : null;

				if ( $post && 'deleted' !== $row->action && current_user_can( 'edit_post', $id ) ) {
					$links['item_url'] = (string) get_edit_post_link( $id, 'raw' );
				} else {
					$links['item_gone'] = ! $post;
				}

				$details = BLT_Activity_Log_Formatter::details( $row );

				if ( ! empty( $details['content']['revision_id'] ) ) {
					// Passed by reference, so it has to be a variable.
					$revision_id = (int) $details['content']['revision_id'];
					$revision    = wp_get_post_revision( $revision_id );

					if ( $revision && current_user_can( 'read_post', $revision->ID ) ) {
						$links['revision_url'] = admin_url( 'revision.php?revision=' . (int) $revision->ID );
					}
				}
				break;

			case 'term':
				$term = $id ? get_term( $id, $type ) : null;

				if ( $term instanceof WP_Term && current_user_can( 'edit_term', $id ) ) {
					$links['item_url'] = (string) get_edit_term_link( $id, $type );
				} else {
					$links['item_gone'] = ! ( $term instanceof WP_Term );
				}
				break;

			case 'user':
				if ( $id && get_userdata( $id ) ) {
					$links['item_url'] = current_user_can( 'edit_user', $id ) ? (string) get_edit_user_link( $id ) : '';
				} elseif ( $id ) {
					$links['item_gone'] = true;
				}
				break;

			case 'plugin':
				$links['item_url'] = current_user_can( 'activate_plugins' ) ? admin_url( 'plugins.php' ) : '';
				break;

			case 'theme':
				$links['item_url'] = current_user_can( 'switch_themes' ) ? admin_url( 'themes.php' ) : '';
				break;

			case 'appearance':
				if ( 'nav_menu' === $type && $id ) {
					$menu = wp_get_nav_menu_object( $id );

					if ( $menu && current_user_can( 'edit_theme_options' ) ) {
						$links['item_url'] = admin_url( 'nav-menus.php?action=edit&menu=' . $id );
					} else {
						$links['item_gone'] = ! $menu;
					}
				}
				break;
		}

		return $links;
	}

	/**
	 * Handle every form this screen posts.
	 *
	 * @return void
	 */
	public function handle_post() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Each branch verifies its own nonce before doing anything.
		if ( isset( $_POST['blt_activity_log_settings_submit'] ) ) {
			$this->handle_settings();
		} elseif ( isset( $_POST['blt_activity_log_clear_preview'] ) ) {
			$this->handle_clear_preview();
		} elseif ( isset( $_POST['blt_activity_log_clear_confirm'] ) ) {
			$this->handle_clear_confirm();
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Save the settings. Saving writes a `setting:updated` entry through the
	 * system module, so changing the settings is itself on the record.
	 *
	 * @return void
	 */
	private function handle_settings() {
		$this->require_manage_options();

		check_admin_referer( self::NONCE_SETTINGS, 'blt_activity_log_nonce' );

		$raw = isset( $_POST['blt_activity_log'] ) && is_array( $_POST['blt_activity_log'] )
			? wp_unslash( $_POST['blt_activity_log'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field in Settings::save().
			: array();

		BLT_Activity_Log_Settings::save( $raw );

		// A retention value the form could not use (zero, text, a negative, over a year) is not saved as typed; say so.
		$notice = 'saved';
		$days   = BLT_Activity_Log_Settings::retention_days();

		if ( isset( $raw['retention_days'] ) && ! BLT_Activity_Log_Settings::retention_locked() ) {
			$posted = ( is_scalar( $raw['retention_days'] ) && is_numeric( $raw['retention_days'] ) ) ? (int) $raw['retention_days'] : 0;

			if ( $posted !== $days ) {
				$notice = 'saved_retention';
			}
		}

		$this->redirect(
			array(
				'tab' => 'settings',
				'n'   => $days,
			),
			$notice
		);
	}

	/**
	 * Step one of clearing the log: validate, then redirect to a preview that
	 * shows the exact count. Nothing is deleted.
	 *
	 * @return void
	 */
	private function handle_clear_preview() {
		$this->require_manage_options();

		check_admin_referer( self::NONCE_CLEAR, 'blt_activity_log_clear_nonce' );

		$scope  = ( isset( $_POST['clear_scope'] ) && 'before' === sanitize_key( wp_unslash( $_POST['clear_scope'] ) ) ) ? 'before' : 'all';
		$before = isset( $_POST['clear_before'] ) ? sanitize_text_field( wp_unslash( $_POST['clear_before'] ) ) : '';

		if ( 'before' === $scope && null === $this->local_day_start( $before ) ) {
			$this->redirect( array( 'tab' => 'settings' ), 'clear_bad_date' );
		}

		$args = array(
			'tab'         => 'settings',
			'clear'       => 'preview',
			'clear_scope' => $scope,
		);

		if ( 'before' === $scope ) {
			$args['clear_before'] = $before;
		}

		wp_safe_redirect( self::page_url( $args ) . '#blt-activity-log-clear' );
		exit;
	}

	/**
	 * Step two: delete, then record that the log was cleared, so a cleared log
	 * is never silently empty.
	 *
	 * @return void
	 */
	private function handle_clear_confirm() {
		$this->require_manage_options();

		check_admin_referer( self::NONCE_CLEAR, 'blt_activity_log_clear_nonce' );

		$scope  = ( isset( $_POST['clear_scope'] ) && 'before' === sanitize_key( wp_unslash( $_POST['clear_scope'] ) ) ) ? 'before' : 'all';
		$before = isset( $_POST['clear_before'] ) ? sanitize_text_field( wp_unslash( $_POST['clear_before'] ) ) : '';

		if ( empty( $_POST['clear_confirm'] ) ) {
			$this->redirect( array( 'tab' => 'settings' ), 'clear_unconfirmed' );
		}

		$budget = BLT_Activity_Log_Pruner::budget();

		if ( 'before' === $scope ) {
			$cutoff = $this->local_day_start( $before );

			if ( null === $cutoff ) {
				$this->redirect( array( 'tab' => 'settings' ), 'clear_bad_date' );
			}

			$result = BLT_Activity_Log_Pruner::delete( 'cutoff', $cutoff, $budget );
			$mode   = 'cutoff';
			$value  = $cutoff;
		} else {
			// Everything up to now. Entries written after this moment (including
			// the `log_cleared` entry below) are never touched by a continuation.
			$value  = BLT_Activity_Log_Query::max_id();
			$result = BLT_Activity_Log_Pruner::delete( 'max_id', $value, $budget );
			$mode   = 'max_id';
		}

		if ( $result['more'] ) {
			BLT_Activity_Log_Pruner::schedule_continue( $mode, $value );
		}

		BLT_Activity_Log_Logger::write_now(
			array(
				'category'     => 'system',
				'action'       => 'log_cleared',
				'object_type'  => 'log',
				'object_key'   => 'entries',
				'object_label' => 'BLT Activity Log',
				'details'      => array(
					'changes' => array(
						array(
							'field'  => 'entries_removed',
							'before' => '',
							'after'  => (string) $result['deleted'],
						),
						array(
							'field'  => 'scope',
							'before' => '',
							'after'  => 'before' === $scope ? 'before ' . $before : 'all',
						),
					),
				),
			)
		);

		$this->redirect(
			array(
				'tab' => 'settings',
				'n'   => (int) $result['deleted'],
			),
			$result['more'] ? 'cleared_partial' : 'cleared'
		);
	}

	/**
	 * Stream the filtered log as CSV.
	 *
	 * Keyset pagination on `id` in batches keeps memory flat however large the
	 * log is. Above the row cap the export is refused with a notice asking for
	 * narrower filters, rather than quietly truncated.
	 *
	 * @return void
	 */
	public function handle_export() {
		$this->require_manage_options();

		check_admin_referer( self::NONCE_EXPORT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked above; normalized by Query::normalize_args().
		$args  = BLT_Activity_Log_Query::normalize_args( wp_unslash( $_GET ) );
		$state = self::state_args( $args );
		$total = BLT_Activity_Log_Query::count( $args );

		/**
		 * Filter the most rows one CSV export may contain.
		 *
		 * @param int $limit Default 50000.
		 */
		$limit = max( 1, (int) apply_filters( 'blt_activity_log_export_limit', self::EXPORT_LIMIT ) );

		if ( $total > $limit ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'blt_notice' => 'export_too_large',
						'n'          => $total,
						'limit'      => $limit,
					),
					self::page_url( array_intersect_key( $state, array_flip( array( 'activity', 'user', 'from', 'to', 's' ) ) ) )
				)
			);
			exit;
		}

		// Recorded before the first byte goes out, so a download that is cancelled halfway is still on the record.
		// The file is everything up to the newest entry as of now, which also leaves out this entry itself.
		$upto = BLT_Activity_Log_Query::max_id();

		BLT_Activity_Log_Logger::write_now(
			array(
				'category'     => 'system',
				'action'       => 'exported',
				'object_type'  => 'log',
				'object_key'   => 'entries',
				'object_label' => 'BLT Activity Log',
				'details'      => array(
					'changes' => array(
						array(
							'field'  => 'entries_exported',
							'before' => '',
							'after'  => (string) $total,
						),
						array(
							'field'  => 'filters',
							'before' => '',
							'after'  => empty( $state ) ? '' : http_build_query( array_intersect_key( $state, array_flip( array( 'activity', 'user', 'from', 'to', 's' ) ) ) ),
						),
					),
				),
			)
		);

		$this->begin_csv( 'blt-activity-log' );

		echo BLT_Activity_Log_Csv::line( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, escaped cell by cell in Csv::line().
			array(
				'ID',
				'Time (site)',
				'Time (UTC)',
				'User',
				'Role',
				'Category',
				'Action',
				'Object type',
				'Object ID or key',
				'Label',
				'Summary',
				'Context',
				'IP',
			)
		);

		$this->stream_rows(
			$args,
			$upto,
			self::EXPORT_BATCH,
			function ( $row ) {
				return array(
					(int) $row->id,
					BLT_Activity_Log_Formatter::site_time( (string) $row->created_at, 'Y-m-d H:i:s' ),
					(string) $row->created_at,
					(string) $row->user_login,
					(string) $row->user_role,
					(string) $row->category,
					(string) $row->action,
					(string) $row->object_type,
					null !== $row->object_id ? (string) $row->object_id : (string) $row->object_key,
					(string) $row->object_label,
					BLT_Activity_Log_Formatter::summary( $row ),
					(string) $row->context,
					(string) $row->ip,
				);
			}
		);

		exit;
	}

	/**
	 * Stream the whole log as CSV: no filters, no row cap, every column.
	 *
	 * The log keeps at most a year. For a longer record, a site downloads a
	 * dump before entries age out. Unlike the export, which is a view of what
	 * is on screen (summary text, capped), the dump is the stored data itself,
	 * including each entry's full `details` JSON, so it can be kept as an
	 * archive. Secrets are already hidden in what is stored.
	 *
	 * @return void
	 */
	public function handle_dump() {
		$this->require_manage_options();

		check_admin_referer( self::NONCE_DUMP );

		// A big log can outlast the default time limit. It streams in keyset batches, so memory stays flat.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A long download of the owner's own data.
		}

		$args  = BLT_Activity_Log_Query::normalize_args( array() );
		$upto  = BLT_Activity_Log_Query::max_id();
		$total = BLT_Activity_Log_Query::count( $args );

		// Recorded before the first byte goes out, so a download that is cancelled halfway is still on the record.
		// The file is everything up to the newest entry as of now, which also leaves out this entry itself.
		BLT_Activity_Log_Logger::write_now(
			array(
				'category'     => 'system',
				'action'       => 'exported',
				'object_type'  => 'log',
				'object_key'   => 'entries',
				'object_label' => 'BLT Activity Log',
				'details'      => array(
					'changes' => array(
						array(
							'field'  => 'entries_exported',
							'before' => '',
							'after'  => (string) $total,
						),
						array(
							'field'  => 'scope',
							'before' => '',
							'after'  => 'full dump',
						),
					),
				),
			)
		);

		$this->begin_csv( 'blt-activity-log-dump' );

		echo BLT_Activity_Log_Csv::line( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, escaped cell by cell in Csv::line().
			array(
				'ID',
				'Created (UTC)',
				'Last saved (UTC)',
				'Saves merged',
				'User ID',
				'User',
				'Role',
				'Category',
				'Action',
				'Object type',
				'Object ID',
				'Object key',
				'Label',
				'Context',
				'IP',
				'Browser',
				'Details (JSON)',
			)
		);

		$this->stream_rows(
			$args,
			$upto,
			self::DUMP_BATCH,
			function ( $row ) {
				return array(
					(int) $row->id,
					(string) $row->created_at,
					(string) $row->last_at,
					(int) $row->occurrences,
					(int) $row->user_id,
					(string) $row->user_login,
					(string) $row->user_role,
					(string) $row->category,
					(string) $row->action,
					(string) $row->object_type,
					null !== $row->object_id ? (string) $row->object_id : '',
					null !== $row->object_key ? (string) $row->object_key : '',
					(string) $row->object_label,
					(string) $row->context,
					null !== $row->ip ? (string) $row->ip : '',
					null !== $row->user_agent ? (string) $row->user_agent : '',
					null !== $row->details ? (string) $row->details : '',
				);
			}
		);

		exit;
	}

	/**
	 * Start a CSV download: the headers, and no output buffer in the way.
	 *
	 * @param string $basename File name without the date and extension.
	 * @return void
	 */
	private function begin_csv( $basename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $basename . '-' . gmdate( 'Ymd-His' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	/**
	 * Write the matching entries as CSV lines, newest first, in keyset batches.
	 *
	 * Only entries up to `$upto` are written, so the file is one consistent
	 * snapshot however long it takes. If a query fails, a last line says the
	 * file is incomplete (and the error is logged) instead of the file ending as
	 * if it were whole.
	 *
	 * @param array    $args  Normalized query arguments.
	 * @param int      $upto  Highest entry id to include.
	 * @param int      $batch Rows read per query.
	 * @param callable $cells Takes a row, returns the cells of its line.
	 * @return int Rows written.
	 */
	private function stream_rows( array $args, $upto, $batch, callable $cells ) {
		$written   = 0;
		$before_id = (int) $upto + 1;

		do {
			$rows = BLT_Activity_Log_Query::export_batch( $args, $before_id, $batch );

			if ( null === $rows ) {
				/* translators: %d: number of entries written before the error. */
				$message = sprintf( __( 'This file is incomplete: a database error stopped it after %d entries. Download it again.', 'blt-activity-log' ), $written );

				echo BLT_Activity_Log_Csv::line( array( 'ERROR', $message ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, escaped cell by cell in Csv::line().

				BLT_Activity_Log_Logger::report( new RuntimeException( 'export stopped after ' . $written . ' entries' ) );

				return $written;
			}

			foreach ( $rows as $row ) {
				echo BLT_Activity_Log_Csv::line( call_user_func( $cells, $row ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, escaped cell by cell in Csv::line().

				++$written;
				$before_id = (int) $row->id;
			}

			flush();

			$fetched = count( $rows );
		} while ( $fetched >= $batch );

		return $written;
	}

	/**
	 * The notice for the `blt_notice` query argument, if any.
	 *
	 * @return array{type:string,message:string}|null
	 */
	public function notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only, after our own redirect.
		if ( empty( $_GET['blt_notice'] ) ) {
			return null;
		}

		$code  = sanitize_key( wp_unslash( $_GET['blt_notice'] ) );
		$count = isset( $_GET['n'] ) ? absint( wp_unslash( $_GET['n'] ) ) : 0;
		$limit = isset( $_GET['limit'] ) ? absint( wp_unslash( $_GET['limit'] ) ) : self::EXPORT_LIMIT;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		switch ( $code ) {
			case 'saved':
				return array(
					'type'    => 'success',
					'message' => __( 'Settings saved.', 'blt-activity-log' ),
				);

			case 'saved_retention':
				return array(
					'type'    => 'warning',
					/* translators: %s: number of days retention is set to. */
					'message' => sprintf( _n( 'Settings saved. Retention must be a whole number of days from 1 to 365, so it is %s day.', 'Settings saved. Retention must be a whole number of days from 1 to 365, so it is %s days.', $count, 'blt-activity-log' ), number_format_i18n( $count ) ),
				);

			case 'cleared':
				return array(
					'type'    => 'success',
					/* translators: %s: number of entries removed. */
					'message' => sprintf( _n( 'Log cleared: %s entry removed.', 'Log cleared: %s entries removed.', $count, 'blt-activity-log' ), number_format_i18n( $count ) ),
				);

			case 'cleared_partial':
				return array(
					'type'    => 'warning',
					/* translators: %s: number of entries removed so far. */
					'message' => sprintf( __( '%s entries were removed. There are more to remove, so the rest will be cleared in the background within a few minutes.', 'blt-activity-log' ), number_format_i18n( $count ) ),
				);

			case 'clear_unconfirmed':
				return array(
					'type'    => 'error',
					'message' => __( 'Nothing was deleted: tick the box to confirm.', 'blt-activity-log' ),
				);

			case 'clear_bad_date':
				return array(
					'type'    => 'error',
					'message' => __( 'Nothing was deleted: choose a valid date.', 'blt-activity-log' ),
				);

			case 'export_too_large':
				return array(
					'type'    => 'error',
					/* translators: 1: number of matching entries, 2: the most an export can hold. */
					'message' => sprintf( __( '%1$s entries match, which is more than one export can hold (%2$s). Narrow the filters, for example to a shorter date range, and export again.', 'blt-activity-log' ), number_format_i18n( $count ), number_format_i18n( $limit ) ),
				);
		}

		return null;
	}

	/**
	 * The start of a site-local day, as a UTC database time.
	 *
	 * @param string $date `Y-m-d`.
	 * @return string|null Null when the date is not valid.
	 */
	private function local_day_start( $date ) {
		if ( ! BLT_Activity_Log_Query::valid_date( $date ) ) {
			return null;
		}

		$range = BLT_Activity_Log_Query::utc_range( $date, '', BLT_Activity_Log_Query::timezone() );

		return $range['from'];
	}

	/**
	 * Stop anyone but a site administrator.
	 *
	 * @return void
	 */
	private function require_manage_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'blt-activity-log' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirect back to the page with a notice (post / redirect / get).
	 *
	 * @param array<string,mixed> $args   Query arguments.
	 * @param string              $notice Notice code.
	 * @return void
	 */
	private function redirect( array $args, $notice ) {
		wp_safe_redirect( self::page_url( array_merge( $args, array( 'blt_notice' => $notice ) ) ) );
		exit;
	}
}
