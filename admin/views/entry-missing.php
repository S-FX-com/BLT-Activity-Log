<?php
/**
 * An entry that no longer exists (pruned, cleared, or a bad link).
 *
 * @package Blt_Activity_Log
 *
 * @var string                      $back_url Link back to the log.
 * @var string                      $tab      Open tab.
 * @var string                      $sub      Subtitle.
 * @var BLT_Activity_Log_Admin      $this     The admin controller.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap blt-ui blt-activity-log">
	<?php require BLT_ACTIVITY_LOG_DIR . 'admin/views/header.php'; ?>

	<div class="blt-card">
		<div class="blt-card-body">
			<div class="blt-empty">
				<span class="blt-empty-title"><?php esc_html_e( 'That entry does not exist', 'blt-activity-log' ); ?></span>
				<span><?php esc_html_e( 'It may have been removed by the retention setting, or cleared from the log.', 'blt-activity-log' ); ?></span>
				<span><a href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Back to the log', 'blt-activity-log' ); ?></a></span>
			</div>
		</div>
	</div>
</div>
