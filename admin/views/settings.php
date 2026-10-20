<?php
/**
 * The Settings tab.
 *
 * @package Blt_Activity_Log
 *
 * @var array                           $settings   Settings, merged over defaults.
 * @var array                           $summary    Whole-log summary.
 * @var array                           $state      State option (last prune, start date).
 * @var BLT_Activity_Log_Module[]       $modules    Modules a site owner can switch.
 * @var bool                            $locked     Whether retention is fixed by wp-config.
 * @var string                          $update_url Nonced "Check for Updates" URL.
 * @var int                             $last_check Time of the last update check.
 * @var array                           $clear      Clear log stage: stage, scope, before, count.
 * @var string                          $tab        Open tab.
 * @var string                          $sub        Subtitle.
 * @var BLT_Activity_Log_Admin          $this       The admin controller.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_field       = 'blt_activity_log';
$blt_scope       = 'all' === $settings['settings_scope'] ? 'all' : 'core';
$blt_date_format = (string) get_option( 'date_format' );
$blt_excluded    = is_array( $settings['excluded_post_types'] ) ? implode( ', ', $settings['excluded_post_types'] ) : '';
$blt_last_prune  = (int) $state['last_prune_at'];
$blt_retention   = BLT_Activity_Log_Settings::retention_days();
?>
<div class="wrap blt-ui blt-activity-log blt-activity-log-settings">
	<?php require BLT_ACTIVITY_LOG_DIR . 'admin/views/header.php'; ?>

	<form method="post" action="<?php echo esc_url( BLT_Activity_Log_Admin::page_url( array( 'tab' => 'settings' ) ) ); ?>">
		<?php wp_nonce_field( BLT_Activity_Log_Admin::NONCE_SETTINGS, 'blt_activity_log_nonce' ); ?>
		<input type="hidden" name="<?php echo esc_attr( $blt_field ); ?>[_modules]" value="1" />
		<input type="hidden" name="<?php echo esc_attr( $blt_field ); ?>[_booleans]" value="1" />

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'What to log', 'blt-activity-log' ); ?></h2>
				<p><?php esc_html_e( 'Choose which kinds of activity are recorded. Changes to this plugin\'s own settings are always recorded.', 'blt-activity-log' ); ?></p>
			</div>
			<div class="blt-card-body">
				<div class="blt-toggle-stack">
					<?php foreach ( $modules as $blt_id => $blt_module ) : ?>
						<label class="blt-toggle">
							<input type="checkbox" name="<?php echo esc_attr( $blt_field ); ?>[modules][<?php echo esc_attr( $blt_id ); ?>]" value="1" <?php checked( ! empty( $settings['modules'][ $blt_id ] ) ); ?> />
							<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
							<span class="blt-toggle-text">
								<span class="blt-toggle-label"><?php echo esc_html( $blt_module->label() ); ?></span>
								<span class="blt-toggle-desc"><?php echo esc_html( $blt_module->description() ); ?></span>
							</span>
						</label>
						<?php if ( 'session' === $blt_id ) : ?>
							<label class="blt-toggle blt-activity-log-nested">
								<input type="checkbox" name="<?php echo esc_attr( $blt_field ); ?>[modules][session_failed]" value="1" <?php checked( ! empty( $settings['modules']['session_failed'] ) ); ?> />
								<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
								<span class="blt-toggle-text">
									<span class="blt-toggle-label"><?php esc_html_e( 'Failed sign-ins', 'blt-activity-log' ); ?></span>
									<span class="blt-toggle-desc"><?php esc_html_e( 'Off by default: a brute-force attack would flood the log. Locking people out is the job of a security plugin. The username is recorded as typed, and people sometimes type a password into that box.', 'blt-activity-log' ); ?></span>
								</span>
							</label>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-al-excluded"><?php esc_html_e( 'Skip these post types', 'blt-activity-log' ); ?></label></div>
					<div>
						<input type="text" id="blt-al-excluded" class="regular-text" name="<?php echo esc_attr( $blt_field ); ?>[excluded_post_types]" value="<?php echo esc_attr( $blt_excluded ); ?>" placeholder="shop_order, scheduled-action" />
						<p class="blt-field-desc"><?php esc_html_e( 'Post type names, separated by commas, left out of the log in addition to the ones that are never logged (such as revisions).', 'blt-activity-log' ); ?></p>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Settings changes', 'blt-activity-log' ); ?></h2>
				<p><?php esc_html_e( 'Which options count when the Settings module is on.', 'blt-activity-log' ); ?></p>
			</div>
			<div class="blt-card-body">
				<div class="blt-select-cards" role="radiogroup" aria-label="<?php esc_attr_e( 'Which settings changes are logged', 'blt-activity-log' ); ?>">
					<label class="blt-select-card<?php echo 'core' === $blt_scope ? ' is-selected' : ''; ?>">
						<input type="radio" name="<?php echo esc_attr( $blt_field ); ?>[settings_scope]" value="core" <?php checked( 'core', $blt_scope ); ?> />
						<span class="blt-select-card-check" aria-hidden="true"></span>
						<span class="blt-select-card-name"><?php esc_html_e( 'WordPress settings screens', 'blt-activity-log' ); ?></span>
						<span class="blt-select-card-desc"><?php esc_html_e( 'Options on the General, Writing, Reading, Discussion, Media, Permalinks and Privacy screens. Quiet and focused.', 'blt-activity-log' ); ?></span>
					</label>
					<label class="blt-select-card<?php echo 'all' === $blt_scope ? ' is-selected' : ''; ?>">
						<input type="radio" name="<?php echo esc_attr( $blt_field ); ?>[settings_scope]" value="all" <?php checked( 'all', $blt_scope ); ?> />
						<span class="blt-select-card-check" aria-hidden="true"></span>
						<span class="blt-select-card-name"><?php esc_html_e( 'Any option changed by a signed-in user', 'blt-activity-log' ); ?></span>
						<span class="blt-select-card-desc"><?php esc_html_e( 'Also catches other plugins\' settings forms. Noisier: plugins write options often. Changes by cron and visitors are not logged.', 'blt-activity-log' ); ?></span>
					</label>
				</div>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Privacy', 'blt-activity-log' ); ?></h2>
				<p><?php esc_html_e( 'What is stored about the person behind each entry.', 'blt-activity-log' ); ?></p>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-al-ip"><?php esc_html_e( 'IP address', 'blt-activity-log' ); ?></label></div>
					<div>
						<select id="blt-al-ip" name="<?php echo esc_attr( $blt_field ); ?>[ip_mode]">
							<option value="full" <?php selected( $settings['ip_mode'], 'full' ); ?>><?php esc_html_e( 'Store in full', 'blt-activity-log' ); ?></option>
							<option value="anonymized" <?php selected( $settings['ip_mode'], 'anonymized' ); ?>><?php esc_html_e( 'Anonymize (remove the last part)', 'blt-activity-log' ); ?></option>
							<option value="off" <?php selected( $settings['ip_mode'], 'off' ); ?>><?php esc_html_e( 'Do not store', 'blt-activity-log' ); ?></option>
						</select>
						<p class="blt-field-desc"><?php esc_html_e( 'Applies to new entries. Entries already in the log keep what they have.', 'blt-activity-log' ); ?></p>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Browser', 'blt-activity-log' ); ?></div>
					<div>
						<label class="blt-toggle">
							<input type="checkbox" name="<?php echo esc_attr( $blt_field ); ?>[store_user_agent]" value="1" <?php checked( ! empty( $settings['store_user_agent'] ) ); ?> />
							<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
							<span class="blt-toggle-text">
								<span class="blt-toggle-label"><?php esc_html_e( 'Store the browser (user agent)', 'blt-activity-log' ); ?></span>
								<span class="blt-toggle-desc"><?php esc_html_e( 'Shortened to 255 characters.', 'blt-activity-log' ); ?></span>
							</span>
						</label>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Retention', 'blt-activity-log' ); ?></h2>
				<p><?php esc_html_e( 'Old entries are removed automatically, once a day.', 'blt-activity-log' ); ?></p>
			</div>
			<div class="blt-card-body">
				<div class="blt-stats">
					<div class="blt-stat">
						<span class="blt-stat-label"><?php esc_html_e( 'Entries', 'blt-activity-log' ); ?></span>
						<span class="blt-stat-value"><?php echo esc_html( number_format_i18n( $summary['total'] ) ); ?></span>
					</div>
					<div class="blt-stat">
						<span class="blt-stat-label"><?php esc_html_e( 'Oldest entry', 'blt-activity-log' ); ?></span>
						<span class="blt-stat-value blt-activity-log-stat-date"><?php echo esc_html( null !== $summary['oldest'] ? BLT_Activity_Log_Formatter::site_time( $summary['oldest'], $blt_date_format ) : '—' ); ?></span>
					</div>
					<div class="blt-stat">
						<span class="blt-stat-label"><?php esc_html_e( 'Last prune', 'blt-activity-log' ); ?></span>
						<span class="blt-stat-value blt-activity-log-stat-date"><?php echo esc_html( $blt_last_prune > 0 ? wp_date( $blt_date_format, $blt_last_prune ) : '—' ); ?></span>
						<?php if ( $blt_last_prune > 0 ) : ?>
							<span class="blt-stat-sub">
								<?php
								printf(
									/* translators: %s: number of entries removed by the last prune. */
									esc_html( _n( '%s entry removed', '%s entries removed', (int) $state['last_prune_rows'], 'blt-activity-log' ) ),
									esc_html( number_format_i18n( (int) $state['last_prune_rows'] ) )
								);
								?>
							</span>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-al-retention"><?php esc_html_e( 'Keep entries for', 'blt-activity-log' ); ?></label></div>
					<div>
						<input type="number" id="blt-al-retention" class="small-text" min="<?php echo esc_attr( (string) BLT_Activity_Log_Settings::MIN_RETENTION_DAYS ); ?>" max="<?php echo esc_attr( (string) BLT_Activity_Log_Settings::MAX_RETENTION_DAYS ); ?>" name="<?php echo esc_attr( $blt_field ); ?>[retention_days]" value="<?php echo esc_attr( (string) $blt_retention ); ?>" <?php disabled( $locked ); ?> />
						<?php esc_html_e( 'days', 'blt-activity-log' ); ?>
						<?php if ( $locked ) : ?>
							<p class="blt-field-desc">
								<span class="blt-badge blt-badge-on"><?php esc_html_e( 'Locked', 'blt-activity-log' ); ?></span>
								<?php
								printf(
									/* translators: %s: the constant name. */
									esc_html__( 'Set by %s in wp-config.php. Change it there.', 'blt-activity-log' ),
									'<code>BLT_ACTIVITY_LOG_RETENTION_DAYS</code>'
								);
								?>
							</p>
						<?php else : ?>
							<p class="blt-field-desc"><?php esc_html_e( 'From 1 to 365 days. To lock this for compliance, define BLT_ACTIVITY_LOG_RETENTION_DAYS in wp-config.php.', 'blt-activity-log' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Dump', 'blt-activity-log' ); ?></div>
					<div>
						<a class="button" href="<?php echo esc_url( $dump_url ); ?>"><?php esc_html_e( 'Download a full dump', 'blt-activity-log' ); ?></a>
						<p class="blt-field-desc"><?php esc_html_e( 'Every entry as a CSV, with no filters and every column, including the full details. Entries older than the retention period are deleted automatically, so download a dump first if you need to keep them longer. A dump holds IP addresses and browsers, so keep it as carefully as the log itself. A cell that starts with = + - @ gets a leading apostrophe so a spreadsheet cannot run it; remove it if you import the file.', 'blt-activity-log' ); ?></p>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Updates', 'blt-activity-log' ); ?></h2>
				<p><?php esc_html_e( 'BLT Activity Log checks GitHub for a new version once a day, at midnight site time.', 'blt-activity-log' ); ?></p>
			</div>
			<div class="blt-card-body">
				<?php if ( '' !== $update_url ) : ?>
					<a class="button" href="<?php echo esc_url( $update_url ); ?>"><?php esc_html_e( 'Check for Updates', 'blt-activity-log' ); ?></a>
				<?php endif; ?>
				<p class="blt-field-desc">
					<?php
					printf(
						/* translators: %s: the plugin version. */
						esc_html__( 'Installed version: %s', 'blt-activity-log' ),
						esc_html( BLT_ACTIVITY_LOG_VERSION )
					);
					?>
				</p>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Advanced', 'blt-activity-log' ); ?></h2>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'On uninstall', 'blt-activity-log' ); ?></div>
					<div>
						<label class="blt-toggle">
							<input type="checkbox" name="<?php echo esc_attr( $blt_field ); ?>[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?> />
							<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
							<span class="blt-toggle-text">
								<span class="blt-toggle-label"><?php esc_html_e( 'Delete all plugin data when the plugin is deleted', 'blt-activity-log' ); ?></span>
								<span class="blt-toggle-desc"><?php esc_html_e( 'The log, the settings and the capability. Off by default, so deleting and reinstalling the plugin loses nothing.', 'blt-activity-log' ); ?></span>
							</span>
						</label>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-settings-footer">
			<button type="submit" name="blt_activity_log_settings_submit" value="1" class="button button-primary blt-save-button"><?php esc_html_e( 'Save changes', 'blt-activity-log' ); ?></button>
		</div>
	</form>

	<form method="post" action="<?php echo esc_url( BLT_Activity_Log_Admin::page_url( array( 'tab' => 'settings' ) ) ); ?>" id="blt-activity-log-clear">
		<?php wp_nonce_field( BLT_Activity_Log_Admin::NONCE_CLEAR, 'blt_activity_log_clear_nonce' ); ?>

		<div class="blt-card blt-activity-log-danger">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Clear log', 'blt-activity-log' ); ?></h2>
				<p><?php esc_html_e( 'Permanently delete entries. A record that the log was cleared is kept, so a cleared log is never silently empty.', 'blt-activity-log' ); ?></p>
				<div class="blt-card-header-badges">
					<span class="blt-badge blt-badge-cancelled"><?php esc_html_e( 'Cannot be undone', 'blt-activity-log' ); ?></span>
				</div>
			</div>
			<div class="blt-card-body">
				<?php if ( 'preview' === $clear['stage'] ) : ?>
					<input type="hidden" name="clear_scope" value="<?php echo esc_attr( $clear['scope'] ); ?>" />
					<input type="hidden" name="clear_before" value="<?php echo esc_attr( $clear['before'] ); ?>" />
					<input type="hidden" name="clear_max_id" value="<?php echo esc_attr( (string) $clear['max_id'] ); ?>" />

					<p class="blt-text-danger">
						<?php
						if ( 'before' === $clear['scope'] ) {
							printf(
								/* translators: 1: number of entries, 2: a date. */
								esc_html( _n( '%1$s entry older than %2$s will be permanently deleted.', '%1$s entries older than %2$s will be permanently deleted.', $clear['count'], 'blt-activity-log' ) ),
								esc_html( number_format_i18n( $clear['count'] ) ),
								esc_html( mysql2date( $blt_date_format, $clear['before'] ) )
							);
						} else {
							printf(
								/* translators: %s: number of entries. */
								esc_html( _n( 'All %s entry will be permanently deleted.', 'All %s entries will be permanently deleted.', $clear['count'], 'blt-activity-log' ) ),
								esc_html( number_format_i18n( $clear['count'] ) )
							);
						}
						?>
					</p>
					<p class="blt-field-desc"><a href="<?php echo esc_url( $dump_url ); ?>"><?php esc_html_e( 'Download a full dump first', 'blt-activity-log' ); ?></a> <?php esc_html_e( 'if you may want these entries again.', 'blt-activity-log' ); ?></p>
					<label>
						<input type="checkbox" name="clear_confirm" value="1" />
						<?php esc_html_e( 'I understand this cannot be undone.', 'blt-activity-log' ); ?>
					</label>
					<p class="blt-stack-top">
						<button type="submit" name="blt_activity_log_clear_confirm" value="1" class="button button-primary"><?php esc_html_e( 'Delete these entries', 'blt-activity-log' ); ?></button>
						<a class="button" href="<?php echo esc_url( BLT_Activity_Log_Admin::page_url( array( 'tab' => 'settings' ) ) . '#blt-activity-log-clear' ); ?>"><?php esc_html_e( 'Cancel', 'blt-activity-log' ); ?></a>
					</p>
				<?php else : ?>
					<div class="blt-toggle-stack">
						<label>
							<input type="radio" name="clear_scope" value="all" checked />
							<?php esc_html_e( 'All entries', 'blt-activity-log' ); ?>
						</label>
						<label>
							<input type="radio" name="clear_scope" value="before" />
							<?php esc_html_e( 'Entries older than', 'blt-activity-log' ); ?>
							<input type="date" name="clear_before" value="" aria-label="<?php esc_attr_e( 'Delete entries older than this date', 'blt-activity-log' ); ?>" />
						</label>
					</div>
					<p class="blt-stack-top">
						<button type="submit" name="blt_activity_log_clear_preview" value="1" class="button"><?php esc_html_e( 'Preview what will be deleted', 'blt-activity-log' ); ?></button>
					</p>
					<p class="blt-field-desc"><?php esc_html_e( 'Nothing is deleted until you confirm on the next screen.', 'blt-activity-log' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	</form>
</div>
