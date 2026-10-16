<?php
/**
 * The Log tab: filters and the table of entries.
 *
 * @package Blt_Activity_Log
 *
 * @var array                          $args       Normalized request arguments.
 * @var BLT_Activity_Log_List_Table    $table      The prepared list table.
 * @var int                            $total      Entries matching the filters.
 * @var array                          $summary    Whole-log summary.
 * @var array                          $state      Filters as URL arguments.
 * @var bool                           $filtered   Whether any filter is set.
 * @var array                          $activities Activity choices by category.
 * @var object[]                       $users      Distinct users seen in the log.
 * @var array<string,string>           $presets    Date preset links.
 * @var bool                           $can_export Whether the viewer may export.
 * @var string                         $export_url Nonced export URL.
 * @var string                         $tab        Open tab.
 * @var string                         $sub        Subtitle.
 * @var BLT_Activity_Log_Admin         $this       The admin controller.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_use_select = count( $users ) <= BLT_Activity_Log_Admin::USER_SELECT_LIMIT;
$blt_reset_url  = BLT_Activity_Log_Admin::page_url();
$blt_started    = BLT_Activity_Log_Settings::state()['started_at'];
$blt_user_text  = 0 === strpos( (string) $args['user'], 'login:' ) ? substr( $args['user'], 6 ) : '';
?>
<div class="wrap blt-ui blt-ui-wide blt-activity-log blt-activity-log-list">
	<?php require BLT_ACTIVITY_LOG_DIR . 'admin/views/header.php'; ?>

	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Activity', 'blt-activity-log' ); ?></h2>
			<p>
				<?php
				if ( $filtered ) {
					printf(
						/* translators: 1: number of entries matching the filters, 2: total entries in the log. */
						esc_html( _n( '%1$s entry matches these filters, out of %2$s in the log.', '%1$s entries match these filters, out of %2$s in the log.', $total, 'blt-activity-log' ) ),
						esc_html( number_format_i18n( $total ) ),
						esc_html( number_format_i18n( $summary['total'] ) )
					);
				} else {
					printf(
						/* translators: %s: number of entries in the log. */
						esc_html( _n( '%s entry in the log.', '%s entries in the log.', $total, 'blt-activity-log' ) ),
						esc_html( number_format_i18n( $total ) )
					);
				}
				?>
			</p>
			<?php if ( $can_export && $total > 0 ) : ?>
				<div class="blt-card-header-badges">
					<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'blt-activity-log' ); ?></a>
				</div>
			<?php endif; ?>
		</div>

		<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>" class="blt-toolbar blt-activity-log-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( BLT_Activity_Log_Admin::MENU_SLUG ); ?>" />
			<?php if ( isset( $state['orderby'] ) ) : ?>
				<input type="hidden" name="orderby" value="<?php echo esc_attr( $state['orderby'] ); ?>" />
				<input type="hidden" name="order" value="<?php echo esc_attr( $state['order'] ); ?>" />
			<?php endif; ?>

			<label class="screen-reader-text" for="blt-al-activity"><?php esc_html_e( 'Activity', 'blt-activity-log' ); ?></label>
			<select id="blt-al-activity" name="activity">
				<option value=""><?php esc_html_e( 'All activity', 'blt-activity-log' ); ?></option>
				<?php foreach ( $activities as $blt_category => $blt_group ) : ?>
					<optgroup label="<?php echo esc_attr( $blt_group['label'] ); ?>">
						<option value="<?php echo esc_attr( $blt_category ); ?>" <?php selected( $args['activity'], $blt_category ); ?>>
							<?php
							/* translators: %s: a category such as "Posts & pages". */
							echo esc_html( sprintf( __( 'All %s', 'blt-activity-log' ), $blt_group['label'] ) );
							?>
						</option>
						<?php foreach ( $blt_group['actions'] as $blt_action => $blt_action_label ) : ?>
							<option value="<?php echo esc_attr( $blt_category . ':' . $blt_action ); ?>" <?php selected( $args['activity'], $blt_category . ':' . $blt_action ); ?>><?php echo esc_html( $blt_action_label ); ?></option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="blt-al-user"><?php esc_html_e( 'User', 'blt-activity-log' ); ?></label>
			<?php if ( $blt_use_select ) : ?>
				<select id="blt-al-user" name="user">
					<option value=""><?php esc_html_e( 'Anyone', 'blt-activity-log' ); ?></option>
					<option value="none" <?php selected( $args['user'], 'none' ); ?>><?php esc_html_e( 'No user (system, cron, WP-CLI)', 'blt-activity-log' ); ?></option>
					<?php foreach ( $users as $blt_user ) : ?>
						<?php
						$blt_account = get_userdata( (int) $blt_user->user_id );
						$blt_login   = (string) $blt_user->user_login;

						if ( $blt_account ) {
							/* translators: 1: a user's display name, 2: their username. */
							$blt_user_label = sprintf( __( '%1$s (%2$s)', 'blt-activity-log' ), $blt_account->display_name, $blt_account->user_login );
						} else {
							/* translators: %s: the username of a user who has since been deleted. */
							$blt_user_label = sprintf( __( '%s (deleted)', 'blt-activity-log' ), $blt_login );
						}
						?>
						<option value="<?php echo esc_attr( (string) (int) $blt_user->user_id ); ?>" <?php selected( $args['user'], (string) (int) $blt_user->user_id ); ?>><?php echo esc_html( $blt_user_label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<input type="text" id="blt-al-user" name="user" value="<?php echo esc_attr( '' !== $blt_user_text ? $blt_user_text : ( 'none' === $args['user'] || ctype_digit( (string) $args['user'] ) ? (string) $args['user'] : '' ) ); ?>" placeholder="<?php esc_attr_e( 'Username', 'blt-activity-log' ); ?>" />
			<?php endif; ?>

			<label class="screen-reader-text" for="blt-al-from"><?php esc_html_e( 'From date', 'blt-activity-log' ); ?></label>
			<input type="date" id="blt-al-from" name="from" value="<?php echo esc_attr( $args['from'] ); ?>" />
			<span aria-hidden="true">&ndash;</span>
			<label class="screen-reader-text" for="blt-al-to"><?php esc_html_e( 'To date', 'blt-activity-log' ); ?></label>
			<input type="date" id="blt-al-to" name="to" value="<?php echo esc_attr( $args['to'] ); ?>" />

			<label class="screen-reader-text" for="blt-al-search"><?php esc_html_e( 'Search item names', 'blt-activity-log' ); ?></label>
			<input type="search" id="blt-al-search" name="s" value="<?php echo esc_attr( $args['s'] ); ?>" placeholder="<?php esc_attr_e( 'Search items', 'blt-activity-log' ); ?>" />

			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'blt-activity-log' ); ?></button>
			<?php if ( $filtered ) : ?>
				<a href="<?php echo esc_url( $blt_reset_url ); ?>"><?php esc_html_e( 'Reset', 'blt-activity-log' ); ?></a>
			<?php endif; ?>

			<span class="blt-toolbar-spacer"></span>
			<span class="blt-activity-log-presets">
				<?php foreach ( $presets as $blt_preset_label => $blt_preset_url ) : ?>
					<a href="<?php echo esc_url( $blt_preset_url ); ?>"><?php echo esc_html( $blt_preset_label ); ?></a>
				<?php endforeach; ?>
			</span>
		</form>

		<div class="blt-card-body">
			<?php if ( $total > 0 ) : ?>
				<?php $table->display(); ?>
			<?php elseif ( $filtered ) : ?>
				<div class="blt-empty">
					<span class="blt-empty-title"><?php esc_html_e( 'No activity matches these filters', 'blt-activity-log' ); ?></span>
					<span><a href="<?php echo esc_url( $blt_reset_url ); ?>"><?php esc_html_e( 'Reset the filters', 'blt-activity-log' ); ?></a></span>
				</div>
			<?php else : ?>
				<div class="blt-empty">
					<span class="blt-empty-title"><?php esc_html_e( 'Nothing to show yet', 'blt-activity-log' ); ?></span>
					<span>
						<?php
						$blt_since = '' !== (string) $blt_started
							? BLT_Activity_Log_Formatter::site_time( (string) $blt_started, (string) get_option( 'date_format' ) )
							: wp_date( (string) get_option( 'date_format' ) );

						printf(
							/* translators: %s: the date logging started. */
							esc_html__( 'Logging started on %s. Changes will appear here as they happen.', 'blt-activity-log' ),
							esc_html( $blt_since )
						);
						?>
					</span>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
