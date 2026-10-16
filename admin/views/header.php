<?php
/**
 * Page header and tabs, shared by every screen.
 *
 * @package Blt_Activity_Log
 *
 * @var string                    $tab        Open tab: `log` or `settings`.
 * @var string                    $sub        Subtitle shown beside the title.
 * @var string                    $update_url Optional "Check for Updates" URL (Settings only).
 * @var int                       $last_check Optional time of the last update check.
 * @var BLT_Activity_Log_Admin    $this       The admin controller.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_update_url = isset( $update_url ) ? (string) $update_url : '';
$blt_last_check = isset( $last_check ) ? (int) $last_check : 0;
$blt_notice     = $this->notice();
?>
<div class="blt-admin-page-header">
	<h1>
		<?php
		// Pre-built, KSES-filtered SVG markup from the shared brand class.
		echo BLT_Family_Brand::inline_mark( BLT_ACTIVITY_LOG_DIR ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized in inline_mark().
		?>
		<?php esc_html_e( 'BLT Activity Log', 'blt-activity-log' ); ?>
		<span class="blt-admin-page-header-sub"><?php echo esc_html( $sub ); ?></span>
	</h1>
	<?php if ( '' !== $blt_update_url ) : ?>
		<div class="blt-admin-page-actions">
			<?php // The automatic check runs once a day at midnight site time; this link is an explicit request and runs at once. ?>
			<a class="button" href="<?php echo esc_url( $blt_update_url ); ?>"><?php esc_html_e( 'Check for Updates', 'blt-activity-log' ); ?></a>
			<?php if ( $blt_last_check > 0 ) : ?>
				<span class="blt-admin-page-header-meta">
					<?php
					printf(
						/* translators: %s: date and time of the last update check. */
						esc_html__( 'Last checked %s', 'blt-activity-log' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $blt_last_check ) )
					);
					?>
				</span>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>

<nav class="blt-settings-tabs" aria-label="<?php esc_attr_e( 'BLT Activity Log sections', 'blt-activity-log' ); ?>">
	<a class="blt-settings-tab<?php echo 'log' === $tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( BLT_Activity_Log_Admin::page_url() ); ?>"<?php echo 'log' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Log', 'blt-activity-log' ); ?></a>
	<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<a class="blt-settings-tab<?php echo 'settings' === $tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( BLT_Activity_Log_Admin::page_url( array( 'tab' => 'settings' ) ) ); ?>"<?php echo 'settings' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Settings', 'blt-activity-log' ); ?></a>
	<?php endif; ?>
</nav>

<?php if ( $blt_notice ) : ?>
	<div class="notice notice-<?php echo esc_attr( $blt_notice['type'] ); ?> inline" role="status">
		<p><?php echo esc_html( $blt_notice['message'] ); ?></p>
	</div>
<?php endif; ?>
