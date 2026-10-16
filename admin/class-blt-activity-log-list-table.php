<?php
/**
 * The log, as a WP_List_Table.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The log table. It renders inside a `.blt-card`, where the design system
 * strips its own border and shadow so the card supplies them once.
 *
 * Sorting, paging and the filters are all query arguments, so every state of
 * the table is a URL that can be shared and bookmarked.
 */
class BLT_Activity_Log_List_Table extends WP_List_Table {

	/**
	 * Normalized request arguments.
	 *
	 * @var array
	 */
	private $args;

	/**
	 * Constructor.
	 *
	 * @param array $args Arguments from BLT_Activity_Log_Query::normalize_args().
	 */
	public function __construct( array $args ) {
		parent::__construct(
			array(
				'singular' => 'entry',
				'plural'   => 'entries',
				'ajax'     => false,
			)
		);

		$this->args = $args;
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'when'     => __( 'When', 'blt-activity-log' ),
			'user'     => __( 'User', 'blt-activity-log' ),
			'activity' => __( 'Activity', 'blt-activity-log' ),
			'item'     => __( 'Item', 'blt-activity-log' ),
			'summary'  => __( 'Summary', 'blt-activity-log' ),
			'source'   => __( 'Source', 'blt-activity-log' ),
		);
	}

	/**
	 * Sortable columns. The keys are the whitelist in Query::SORTABLE.
	 *
	 * @return array<string,array>
	 */
	protected function get_sortable_columns() {
		return array(
			'when'     => array( 'when', true ),
			'user'     => array( 'user', false ),
			'activity' => array( 'activity', false ),
		);
	}

	/**
	 * The column that collapses last on a narrow screen.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'item';
	}

	/**
	 * Load one page of entries for the current filters.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = max( 1, min( BLT_Activity_Log_Query::MAX_PER_PAGE, (int) $this->get_items_per_page( BLT_Activity_Log_Admin::PER_PAGE_OPTION, BLT_Activity_Log_Query::DEFAULT_PER_PAGE ) ) );

		$this->args['per_page'] = $per_page;
		$this->args['paged']    = max( 1, (int) $this->get_pagenum() );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'item' );
		$this->items           = BLT_Activity_Log_Query::find( $this->args );

		$this->set_pagination_args(
			array(
				'total_items' => BLT_Activity_Log_Query::count( $this->args ),
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Shown when a page has no rows.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No activity matches these filters.', 'blt-activity-log' );
	}

	/**
	 * The arguments carried on the link to an entry, so Back returns to the
	 * same filtered, sorted page.
	 *
	 * @return array<string,mixed>
	 */
	private function carried_args() {
		return BLT_Activity_Log_Admin::state_args( $this->args );
	}

	/**
	 * Render one cell.
	 *
	 * @param object $item        Stored row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'when':
				return '<span title="' . esc_attr( $item->created_at . ' UTC' ) . '">' . esc_html( BLT_Activity_Log_Formatter::site_time( $item->created_at ) ) . '</span>'
					. '<br /><span class="blt-text-muted">' . esc_html( BLT_Activity_Log_Formatter::relative_time( $item->created_at ) ) . '</span>';

			case 'user':
				$role = BLT_Activity_Log_Formatter::role_label( (string) $item->user_role );

				return '<span class="blt-activity-log-user">' . esc_html( BLT_Activity_Log_Formatter::actor_label( $item ) ) . '</span>'
					. ( '' !== $role ? '<br /><span class="blt-text-muted">' . esc_html( $role ) . '</span>' : '' );

			case 'activity':
				return '<span class="blt-badge">' . esc_html( BLT_Activity_Log_Formatter::category_label( (string) $item->category ) ) . '</span><br />'
					. '<span class="blt-activity-log-action">' . esc_html( BLT_Activity_Log_Formatter::action_label( (string) $item->category, (string) $item->action ) ) . '</span>';

			case 'item':
				$label = BLT_Activity_Log_Formatter::item_label( $item );
				$url   = BLT_Activity_Log_Admin::page_url( array_merge( $this->carried_args(), array( 'entry' => (int) $item->id ) ) );
				$type  = BLT_Activity_Log_Formatter::type_label( $item );

				return '<a href="' . esc_url( $url ) . '"><strong>' . esc_html( '' !== $label ? $label : '—' ) . '</strong></a>'
					. ( '' !== $type ? '<br /><span class="blt-text-muted">' . esc_html( $type ) . '</span>' : '' );

			case 'summary':
				$summary = BLT_Activity_Log_Formatter::summary( $item );
				$more    = (int) $item->occurrences > 1
					? ' <span class="blt-text-muted" title="' . esc_attr__( 'Saved more than once, merged into this entry', 'blt-activity-log' ) . '">&times;' . esc_html( number_format_i18n( (int) $item->occurrences ) ) . '</span>'
					: '';

				return esc_html( $summary ) . $more;

			case 'source':
				$ip = (string) $item->ip;

				return esc_html( BLT_Activity_Log_Formatter::context_label( (string) $item->context ) )
					. ( '' !== $ip ? '<br /><span class="blt-text-muted">' . esc_html( $ip ) . '</span>' : '' );
		}

		return '';
	}
}
