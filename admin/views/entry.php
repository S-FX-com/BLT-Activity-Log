<?php
/**
 * One entry's detail screen.
 *
 * @package Blt_Activity_Log
 *
 * @var object                      $row      Stored row.
 * @var array                       $details  Decoded details.
 * @var array                       $links    Related links.
 * @var string                      $back_url Link back to the log, filters kept.
 * @var string                      $tab      Open tab.
 * @var string                      $sub      Subtitle.
 * @var BLT_Activity_Log_Admin      $this     The admin controller.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_time_format = (string) get_option( 'time_format' );
$blt_changes     = ( ! empty( $details['changes'] ) && is_array( $details['changes'] ) ) ? $details['changes'] : array();
$blt_content     = ( ! empty( $details['content'] ) && is_array( $details['content'] ) ) ? $details['content'] : array();
$blt_bricks      = ( ! empty( $details['bricks'] ) && is_array( $details['bricks'] ) ) ? $details['bricks'] : array();
$blt_terms       = ( ! empty( $details['terms'] ) && is_array( $details['terms'] ) ) ? $details['terms'] : array();
$blt_versions    = ( ! empty( $details['versions'] ) && is_array( $details['versions'] ) ) ? $details['versions'] : array();
$blt_item_label  = BLT_Activity_Log_Formatter::item_label( $row );
$blt_empty_cell  = '<span class="blt-text-muted">&mdash;</span>';
?>
<div class="wrap blt-ui blt-activity-log blt-activity-log-entry">
	<?php require BLT_ACTIVITY_LOG_DIR . 'admin/views/header.php'; ?>

	<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to the log', 'blt-activity-log' ); ?></a></p>

	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php echo esc_html( BLT_Activity_Log_Formatter::headline( $row ) ); ?></h2>
			<p>
				<?php
				if ( (int) $row->occurrences > 1 ) {
					printf(
						/* translators: 1: how many times it was saved, 2: time of the first save, 3: time of the last save. */
						esc_html__( 'Saved %1$s times between %2$s and %3$s', 'blt-activity-log' ),
						esc_html( number_format_i18n( (int) $row->occurrences ) ),
						esc_html( BLT_Activity_Log_Formatter::site_time( (string) $row->created_at, $blt_time_format ) ),
						esc_html( BLT_Activity_Log_Formatter::site_time( (string) $row->last_at, $blt_time_format ) )
					);
				} else {
					echo esc_html( BLT_Activity_Log_Formatter::site_time( (string) $row->created_at ) );
				}
				?>
			</p>
			<div class="blt-card-header-badges">
				<span class="blt-badge"><?php echo esc_html( BLT_Activity_Log_Formatter::category_label( (string) $row->category ) ); ?></span>
				<span class="blt-badge blt-badge-on"><?php echo esc_html( BLT_Activity_Log_Formatter::action_label( (string) $row->category, (string) $row->action ) ); ?></span>
			</div>
		</div>
	</div>

	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Who and where', 'blt-activity-log' ); ?></h2>
		</div>
		<div class="blt-card-body">
			<div class="blt-field">
				<div class="blt-field-label"><?php esc_html_e( 'User', 'blt-activity-log' ); ?></div>
				<div>
					<?php if ( '' !== $links['user_url'] ) : ?>
						<a href="<?php echo esc_url( $links['user_url'] ); ?>"><?php echo esc_html( BLT_Activity_Log_Formatter::actor_label( $row ) ); ?></a>
					<?php else : ?>
						<?php echo esc_html( BLT_Activity_Log_Formatter::actor_label( $row ) ); ?>
						<?php if ( (int) $row->user_id > 0 && ! get_userdata( (int) $row->user_id ) ) : ?>
							<span class="blt-text-muted"><?php esc_html_e( '(deleted)', 'blt-activity-log' ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( '' !== (string) $row->user_role ) : ?>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Role at the time', 'blt-activity-log' ); ?></div>
					<div><?php echo esc_html( BLT_Activity_Log_Formatter::role_label( (string) $row->user_role ) ); ?></div>
				</div>
			<?php endif; ?>
			<div class="blt-field">
				<div class="blt-field-label"><?php esc_html_e( 'When', 'blt-activity-log' ); ?></div>
				<div>
					<?php echo esc_html( BLT_Activity_Log_Formatter::site_time( (string) $row->created_at ) ); ?>
					<p class="blt-field-desc"><?php echo esc_html( $row->created_at . ' UTC' ); ?></p>
				</div>
			</div>
			<div class="blt-field">
				<div class="blt-field-label"><?php esc_html_e( 'Source', 'blt-activity-log' ); ?></div>
				<div><?php echo esc_html( BLT_Activity_Log_Formatter::context_label( (string) $row->context ) ); ?></div>
			</div>
			<?php if ( '' !== (string) $row->ip ) : ?>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'IP address', 'blt-activity-log' ); ?></div>
					<div><code class="blt-chip"><?php echo esc_html( (string) $row->ip ); ?></code></div>
				</div>
			<?php endif; ?>
			<?php if ( '' !== (string) $row->user_agent ) : ?>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Browser', 'blt-activity-log' ); ?></div>
					<div class="blt-activity-log-wrap"><?php echo esc_html( (string) $row->user_agent ); ?></div>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( ! empty( $blt_changes ) ) : ?>
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'What changed', 'blt-activity-log' ); ?></h2>
			</div>
			<table class="widefat striped blt-activity-log-changes">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Field', 'blt-activity-log' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Before', 'blt-activity-log' ); ?></th>
						<th scope="col"><?php esc_html_e( 'After', 'blt-activity-log' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $blt_changes as $blt_change ) : ?>
						<?php
						if ( ! is_array( $blt_change ) || ! isset( $blt_change['field'] ) ) {
							continue;
						}

						$blt_field = (string) $blt_change['field'];
						?>
						<tr>
							<th scope="row"><?php echo esc_html( BLT_Activity_Log_Formatter::field_label( $blt_field ) ); ?></th>
							<?php if ( ! empty( $blt_change['redacted'] ) ) : ?>
								<td colspan="2">
									<span class="blt-badge blt-badge-off"><?php esc_html_e( 'Hidden', 'blt-activity-log' ); ?></span>
									<?php
									$blt_notes = array(
										'set'     => __( 'a password was set', 'blt-activity-log' ),
										'changed' => __( 'the password was changed', 'blt-activity-log' ),
										'removed' => __( 'the password was removed', 'blt-activity-log' ),
									);

									if ( isset( $blt_change['note'], $blt_notes[ $blt_change['note'] ] ) ) {
										echo ' <span class="blt-text-muted">' . esc_html( $blt_notes[ $blt_change['note'] ] ) . '</span>';
									} else {
										echo ' <span class="blt-text-muted">' . esc_html__( 'the value is never stored', 'blt-activity-log' ) . '</span>';
									}
									?>
								</td>
							<?php elseif ( ! empty( $blt_change['order_changed'] ) ) : ?>
								<td colspan="2"><?php esc_html_e( 'The order changed', 'blt-activity-log' ); ?></td>
							<?php elseif ( ! empty( $blt_change['edited'] ) ) : ?>
								<td colspan="2"><?php esc_html_e( 'Items were edited, moved or re-linked', 'blt-activity-log' ); ?></td>
							<?php else : ?>
								<?php foreach ( array( 'before', 'after' ) as $blt_side ) : ?>
									<?php $blt_value = BLT_Activity_Log_Formatter::value_label( $blt_field, isset( $blt_change[ $blt_side ] ) ? (string) $blt_change[ $blt_side ] : '' ); ?>
									<td class="blt-activity-log-wrap">
										<?php
										if ( '' === $blt_value ) {
											echo $blt_empty_cell; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
										} else {
											echo esc_html( $blt_value );
										}

										if ( isset( $blt_change['truncated'][ $blt_side ] ) ) {
											echo ' <span class="blt-text-muted">';
											printf(
												/* translators: %s: the full length of a shortened value, in characters. */
												esc_html__( '(truncated, %s characters)', 'blt-activity-log' ),
												esc_html( number_format_i18n( (int) $blt_change['truncated'][ $blt_side ] ) )
											);
											echo '</span>';
										}
										?>
									</td>
								<?php endforeach; ?>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( ! empty( $details['changes_omitted'] ) ) : ?>
				<div class="blt-card-body">
					<p class="blt-field-desc">
						<?php
						printf(
							/* translators: %s: number of further changes that were not stored. */
							esc_html__( '%s more changes were not stored, to keep the entry a reasonable size.', 'blt-activity-log' ),
							esc_html( number_format_i18n( (int) $details['changes_omitted'] ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $blt_content ) || ! empty( $blt_bricks ) ) : ?>
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Content', 'blt-activity-log' ); ?></h2>
				<p><?php esc_html_e( 'A summary of what changed inside the content. The content itself is not stored: WordPress revisions already keep it.', 'blt-activity-log' ); ?></p>
			</div>
			<div class="blt-card-body">
				<?php if ( ! empty( $blt_content['chars'] ) && is_array( $blt_content['chars'] ) ) : ?>
					<div class="blt-stats">
						<div class="blt-stat">
							<span class="blt-stat-label"><?php esc_html_e( 'Characters before', 'blt-activity-log' ); ?></span>
							<span class="blt-stat-value"><?php echo esc_html( number_format_i18n( (int) $blt_content['chars'][0] ) ); ?></span>
						</div>
						<div class="blt-stat">
							<span class="blt-stat-label"><?php esc_html_e( 'Characters after', 'blt-activity-log' ); ?></span>
							<span class="blt-stat-value"><?php echo esc_html( number_format_i18n( (int) $blt_content['chars'][1] ) ); ?></span>
						</div>
					</div>
				<?php endif; ?>

				<?php $blt_blocks_text = BLT_Activity_Log_Formatter::content_long( $blt_content ); ?>
				<?php if ( '' !== $blt_blocks_text ) : ?>
					<p class="blt-stack-top"><?php echo esc_html( $blt_blocks_text ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $blt_bricks ) ) : ?>
					<?php $blt_bricks_text = BLT_Activity_Log_Formatter::bricks_long( $blt_bricks ); ?>
					<p class="blt-stack-top">
						<strong><?php esc_html_e( 'Bricks', 'blt-activity-log' ); ?></strong>
						<?php
						echo esc_html(
							'' !== $blt_bricks_text
								? $blt_bricks_text
								: __( 'Elements were edited. None were added or removed.', 'blt-activity-log' )
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $blt_content ) ) : ?>
					<p class="blt-stack-top">
						<?php if ( '' !== $links['revision_url'] ) : ?>
							<a class="button" href="<?php echo esc_url( $links['revision_url'] ); ?>"><?php esc_html_e( 'Compare revisions', 'blt-activity-log' ); ?></a>
						<?php else : ?>
							<span class="blt-field-desc">
								<?php
								echo esc_html(
									! empty( $blt_content['revision_id'] )
										? __( 'That revision no longer exists, so only a summary is available.', 'blt-activity-log' )
										: __( 'No revision was saved for this change, so only a summary is available.', 'blt-activity-log' )
								);
								?>
							</span>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $blt_terms ) ) : ?>
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Terms', 'blt-activity-log' ); ?></h2>
			</div>
			<div class="blt-card-body">
				<?php foreach ( $blt_terms as $blt_taxonomy => $blt_moved ) : ?>
					<div class="blt-field">
						<div class="blt-field-label"><?php echo esc_html( BLT_Activity_Log_Formatter::taxonomy_label( (string) $blt_taxonomy ) ); ?></div>
						<div>
							<?php if ( ! empty( $blt_moved['added'] ) ) : ?>
								<div>
									<span class="blt-badge blt-badge-on"><?php esc_html_e( 'Added', 'blt-activity-log' ); ?></span>
									<?php echo esc_html( implode( ', ', array_map( 'strval', (array) $blt_moved['added'] ) ) ); ?>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $blt_moved['removed'] ) ) : ?>
								<div>
									<span class="blt-badge blt-badge-cancelled"><?php esc_html_e( 'Removed', 'blt-activity-log' ); ?></span>
									<?php echo esc_html( implode( ', ', array_map( 'strval', (array) $blt_moved['removed'] ) ) ); ?>
								</div>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $blt_versions ) ) : ?>
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Versions', 'blt-activity-log' ); ?></h2>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Version', 'blt-activity-log' ); ?></div>
					<div>
						<?php echo esc_html( BLT_Activity_Log_Formatter::versions_text( $blt_versions ) ); ?>
						<?php echo esc_html( BLT_Activity_Log_Formatter::method_suffix( $details ) ); ?>
					</div>
				</div>
				<?php if ( ! empty( $details['network_wide'] ) ) : ?>
					<div class="blt-field">
						<div class="blt-field-label"><?php esc_html_e( 'Scope', 'blt-activity-log' ); ?></div>
						<div><?php esc_html_e( 'Network-wide', 'blt-activity-log' ); ?></div>
					</div>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Related', 'blt-activity-log' ); ?></h2>
		</div>
		<div class="blt-card-body">
			<ul class="blt-activity-log-links">
				<?php if ( '' !== $links['item_url'] ) : ?>
					<li>
						<a href="<?php echo esc_url( $links['item_url'] ); ?>">
							<?php
							printf(
								/* translators: %s: the name of the item. */
								esc_html__( 'Edit %s', 'blt-activity-log' ),
								esc_html( $blt_item_label )
							);
							?>
						</a>
					</li>
				<?php elseif ( $links['item_gone'] ) : ?>
					<li>
						<?php echo esc_html( $blt_item_label ); ?>
						<span class="blt-text-muted"><?php esc_html_e( '(deleted)', 'blt-activity-log' ); ?></span>
					</li>
				<?php endif; ?>
				<?php if ( '' !== $links['item_activity'] ) : ?>
					<li><a href="<?php echo esc_url( $links['item_activity'] ); ?>"><?php esc_html_e( 'All activity for this item', 'blt-activity-log' ); ?></a></li>
				<?php endif; ?>
				<li><a href="<?php echo esc_url( $links['user_activity'] ); ?>"><?php esc_html_e( 'All activity by this user', 'blt-activity-log' ); ?></a></li>
			</ul>
		</div>
	</div>

	<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<div class="blt-card">
			<div class="blt-card-body">
				<details class="blt-activity-log-raw">
					<summary><?php esc_html_e( 'Raw record', 'blt-activity-log' ); ?></summary>
					<code class="blt-code"><?php echo esc_html( (string) wp_json_encode( array_merge( get_object_vars( $row ), array( 'details' => $details ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></code>
				</details>
			</div>
		</div>
	<?php endif; ?>
</div>
