<?php
/**
 * One plugin/theme row with its SafeSpot history.
 *
 * @package SafeSpot
 *
 * @var array $item     Item.
 * @var array $points   SafeSpot rows (newest first).
 * @var bool  $expanded Whether the history starts open.
 */

defined( 'ABSPATH' ) || exit;

$safespot_type      = $item['type'];
$safespot_slug      = $item['slug'];
$safespot_dom_id    = SafeSpot_Admin_Views::item_id( $safespot_type, $safespot_slug );
$safespot_installed = 'not_installed' !== $item['status'];
$safespot_count     = count( $points );
$safespot_size      = array_sum( wp_list_pluck( $points, 'file_size' ) );
$safespot_latest    = $safespot_count ? $points[0]['created_ts'] : 0;
$safespot_excluded  = SafeSpot_Settings::is_excluded( $safespot_type, $safespot_slug );
$safespot_hold      = SafeSpot_Settings::get_hold( $safespot_type, $safespot_slug );
$safespot_can_save  = $safespot_installed && SafeSpot_Permissions::can( 'save', $safespot_type );
$safespot_can_rest  = SafeSpot_Permissions::can( 'restore', $safespot_type );
$safespot_can_del   = SafeSpot_Permissions::can( 'delete', $safespot_type );
$safespot_search    = strtolower( $item['name'] . ' ' . $safespot_slug . ' ' . $item['author'] );
$safespot_prefixes  = implode( ', ', SafeSpot_Settings::get_prefixes( $safespot_type, $safespot_slug ) );

$safespot_status_class = array(
	'active'        => 'success',
	'network'       => 'success',
	'parent'        => 'info',
	'inactive'      => '',
	'not_installed' => 'warning',
);
?>
<tbody
	class="safespot-item<?php echo $expanded ? ' is-expanded' : ''; ?>"
	id="<?php echo esc_attr( $safespot_dom_id ); ?>"
	data-type="<?php echo esc_attr( $safespot_type ); ?>"
	data-slug="<?php echo esc_attr( $safespot_slug ); ?>"
	data-name="<?php echo esc_attr( $item['name'] ); ?>"
	data-version="<?php echo esc_attr( $item['version'] ); ?>"
	data-search="<?php echo esc_attr( $safespot_search ); ?>"
	data-count="<?php echo esc_attr( (string) $safespot_count ); ?>"
	data-status="<?php echo esc_attr( $item['status'] ); ?>"
	data-update="<?php echo esc_attr( $item['update'] ); ?>"
	data-prefixes="<?php echo esc_attr( $safespot_prefixes ); ?>"
>
	<tr class="safespot-item-row">
		<th scope="row" class="check-column">
			<?php if ( $safespot_can_save ) : ?>
				<label class="screen-reader-text" for="<?php echo esc_attr( $safespot_dom_id ); ?>-cb">
					<?php
					/* translators: %s: plugin or theme name. */
					echo esc_html( sprintf( __( 'Select %s', 'tbsh-safespot-restore-points' ), $item['name'] ) );
					?>
				</label>
				<input type="checkbox" class="safespot-select" id="<?php echo esc_attr( $safespot_dom_id ); ?>-cb" />
			<?php endif; ?>
		</th>
		<td class="safespot-col-name column-primary">
			<button type="button" class="safespot-toggle" data-safespot-action="toggle" aria-expanded="<?php echo $expanded ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $safespot_dom_id ); ?>-history">
				<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
				<span class="safespot-item-name"><?php echo esc_html( $item['name'] ); ?></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Show SafeSpot history', 'tbsh-safespot-restore-points' ); ?></span>
			</button>
			<div class="safespot-item-meta">
				<code><?php echo esc_html( $item['folder'] ); ?></code>
				<?php
				$safespot_label = SafeSpot_Items::status_label( $item );
				if ( '' !== $safespot_label && 'inactive' !== $item['status'] ) {
					echo SafeSpot_Admin_Views::badge( $safespot_label, $safespot_status_class[ $item['status'] ] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
				}
				if ( '' !== $item['parent'] ) {
					/* translators: %s: parent theme folder. */
					echo SafeSpot_Admin_Views::badge( sprintf( __( 'Child of %s', 'tbsh-safespot-restore-points' ), $item['parent'] ), 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
				}
				if ( '' !== $item['update'] ) {
					/* translators: %s: version number. */
					echo SafeSpot_Admin_Views::badge( sprintf( __( 'Update to %s available', 'tbsh-safespot-restore-points' ), $item['update'] ), 'update' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
				}
				if ( $safespot_hold ) {
					echo SafeSpot_Admin_Views::badge( __( 'Updates paused', 'tbsh-safespot-restore-points' ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
				}
				?>
			</div>
			<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'tbsh-safespot-restore-points' ); ?></span></button>
		</td>
		<td class="safespot-col-version" data-colname="<?php esc_attr_e( 'Version', 'tbsh-safespot-restore-points' ); ?>">
			<?php echo '' !== $item['version'] ? esc_html( $item['version'] ) : '—'; ?>
		</td>
		<td class="safespot-col-count" data-colname="<?php esc_attr_e( 'SafeSpots', 'tbsh-safespot-restore-points' ); ?>">
			<?php echo esc_html( number_format_i18n( $safespot_count ) ); ?>
		</td>
		<td class="safespot-col-latest" data-colname="<?php esc_attr_e( 'Latest SafeSpot', 'tbsh-safespot-restore-points' ); ?>">
			<?php if ( $safespot_latest ) : ?>
				<time datetime="<?php echo esc_attr( gmdate( 'c', $safespot_latest ) ); ?>" title="<?php echo esc_attr( SafeSpot_Points::human_date( $safespot_latest ) ); ?>"><?php echo esc_html( SafeSpot_Admin_Views::ago( $safespot_latest ) ); ?></time>
			<?php else : ?>
				<span class="safespot-muted"><?php esc_html_e( 'Never', 'tbsh-safespot-restore-points' ); ?></span>
			<?php endif; ?>
		</td>
		<td class="safespot-col-size" data-colname="<?php esc_attr_e( 'Storage', 'tbsh-safespot-restore-points' ); ?>">
			<?php echo $safespot_size ? esc_html( size_format( $safespot_size, 1 ) ) : '—'; ?>
		</td>
		<td class="safespot-col-auto" data-colname="<?php esc_attr_e( 'Auto', 'tbsh-safespot-restore-points' ); ?>">
			<?php if ( $safespot_installed ) : ?>
				<label class="safespot-switch">
					<input type="checkbox" role="switch" data-safespot-action="auto" <?php checked( ! $safespot_excluded ); ?> <?php disabled( ! $safespot_can_save ); ?> />
					<span class="safespot-switch__track" aria-hidden="true"></span>
					<span class="screen-reader-text">
						<?php
						/* translators: %s: plugin or theme name. */
						echo esc_html( sprintf( __( 'Automatic SafeSpots before updates for %s', 'tbsh-safespot-restore-points' ), $item['name'] ) );
						?>
					</span>
				</label>
			<?php endif; ?>
		</td>
		<td class="safespot-col-actions" data-colname="<?php esc_attr_e( 'Actions', 'tbsh-safespot-restore-points' ); ?>">
			<div class="safespot-row-actions">
				<?php if ( $safespot_can_save ) : ?>
					<button type="button" class="button button-primary safespot-save" data-safespot-action="save">
						<span class="dashicons dashicons-shield" aria-hidden="true"></span>
						<?php esc_html_e( 'Save SafeSpot', 'tbsh-safespot-restore-points' ); ?>
					</button>
				<?php endif; ?>
				<?php if ( $safespot_hold && $safespot_can_rest ) : ?>
					<button type="button" class="button-link safespot-resume" data-safespot-action="resume">
						<?php esc_html_e( 'Resume updates', 'tbsh-safespot-restore-points' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</td>
	</tr>
	<tr class="safespot-history-row" id="<?php echo esc_attr( $safespot_dom_id ); ?>-history" <?php echo $expanded ? '' : 'hidden'; ?>>
		<td colspan="8">
			<div class="safespot-history">
				<?php if ( ! $safespot_count ) : ?>
					<div class="safespot-empty safespot-empty--inline">
						<span class="dashicons dashicons-shield" aria-hidden="true"></span>
						<p><?php esc_html_e( 'No SafeSpots yet – save one before your next update.', 'tbsh-safespot-restore-points' ); ?></p>
					</div>
				<?php else : ?>
					<table class="safespot-history-table">
						<caption class="screen-reader-text">
							<?php
							/* translators: %s: plugin or theme name. */
							echo esc_html( sprintf( __( 'SafeSpot history for %s', 'tbsh-safespot-restore-points' ), $item['name'] ) );
							?>
						</caption>
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Version', 'tbsh-safespot-restore-points' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Saved', 'tbsh-safespot-restore-points' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Size', 'tbsh-safespot-restore-points' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Type', 'tbsh-safespot-restore-points' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Note', 'tbsh-safespot-restore-points' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Created by', 'tbsh-safespot-restore-points' ); ?></th>
								<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'tbsh-safespot-restore-points' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $points as $safespot_point ) : ?>
								<?php $safespot_f = SafeSpot_Points::format( $safespot_point ); ?>
								<tr
									class="safespot-point<?php echo $safespot_f['locked'] ? ' is-locked' : ''; ?>"
									data-id="<?php echo esc_attr( (string) $safespot_f['id'] ); ?>"
									data-version="<?php echo esc_attr( $safespot_f['version'] ); ?>"
									data-date="<?php echo esc_attr( $safespot_f['created_human'] ); ?>"
									data-note="<?php echo esc_attr( $safespot_f['note'] ); ?>"
									data-locked="<?php echo $safespot_f['locked'] ? '1' : '0'; ?>"
									data-settings="<?php echo $safespot_f['has_settings'] ? '1' : '0'; ?>"
								>
									<td data-colname="<?php esc_attr_e( 'Version', 'tbsh-safespot-restore-points' ); ?>">
										<strong><?php echo esc_html( '' !== $safespot_f['version'] ? $safespot_f['version'] : '—' ); ?></strong>
										<?php if ( '' !== $item['version'] && $safespot_f['version'] === $item['version'] ) : ?>
											<?php echo SafeSpot_Admin_Views::badge( __( 'Current', 'tbsh-safespot-restore-points' ), 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
										<?php endif; ?>
									</td>
									<td data-colname="<?php esc_attr_e( 'Saved', 'tbsh-safespot-restore-points' ); ?>">
										<time datetime="<?php echo esc_attr( $safespot_f['created_at'] ); ?>"><?php echo esc_html( $safespot_f['created_human'] ); ?></time>
									</td>
									<td data-colname="<?php esc_attr_e( 'Size', 'tbsh-safespot-restore-points' ); ?>"><?php echo esc_html( $safespot_f['size_human'] ); ?></td>
									<td data-colname="<?php esc_attr_e( 'Type', 'tbsh-safespot-restore-points' ); ?>">
										<?php echo SafeSpot_Admin_Views::badge( $safespot_f['source_label'], $safespot_f['is_auto'] ? 'auto' : 'manual' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
										<?php if ( $safespot_f['has_settings'] ) : ?>
											<?php echo SafeSpot_Admin_Views::badge( __( '+ settings', 'tbsh-safespot-restore-points' ), 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
										<?php endif; ?>
										<?php if ( $safespot_f['locked'] ) : ?>
											<span class="dashicons dashicons-lock safespot-lock-icon" aria-hidden="true"></span>
											<span class="screen-reader-text"><?php esc_html_e( 'Locked', 'tbsh-safespot-restore-points' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="safespot-note-cell" data-colname="<?php esc_attr_e( 'Note', 'tbsh-safespot-restore-points' ); ?>">
										<?php echo '' !== $safespot_f['note'] ? esc_html( $safespot_f['note'] ) : '<span class="safespot-muted">—</span>'; ?>
									</td>
									<td data-colname="<?php esc_attr_e( 'Created by', 'tbsh-safespot-restore-points' ); ?>"><?php echo esc_html( $safespot_f['created_by_name'] ); ?></td>
									<td class="safespot-point-actions">
										<?php if ( $safespot_can_rest ) : ?>
											<button type="button" class="button button-small" data-safespot-action="restore">
												<span class="dashicons dashicons-backup" aria-hidden="true"></span>
												<?php esc_html_e( 'Restore', 'tbsh-safespot-restore-points' ); ?>
											</button>
										<?php endif; ?>
										<a class="button button-small" href="<?php echo esc_url( $safespot_f['download_url'] ); ?>">
											<span class="dashicons dashicons-download" aria-hidden="true"></span>
											<span class="screen-reader-text">
												<?php
												/* translators: %s: version. */
												echo esc_html( sprintf( __( 'Download SafeSpot %s', 'tbsh-safespot-restore-points' ), $safespot_f['version'] ) );
												?>
											</span>
										</a>
										<button type="button" class="button button-small" data-safespot-action="note" aria-label="<?php esc_attr_e( 'Edit note', 'tbsh-safespot-restore-points' ); ?>" title="<?php esc_attr_e( 'Edit note', 'tbsh-safespot-restore-points' ); ?>">
											<span class="dashicons dashicons-edit" aria-hidden="true"></span>
										</button>
										<button type="button" class="button button-small" data-safespot-action="lock" aria-pressed="<?php echo $safespot_f['locked'] ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( $safespot_f['locked'] ? __( 'Unlock (allow automatic cleanup)', 'tbsh-safespot-restore-points' ) : __( 'Lock (protect from automatic cleanup)', 'tbsh-safespot-restore-points' ) ); ?>" title="<?php echo esc_attr( $safespot_f['locked'] ? __( 'Unlock', 'tbsh-safespot-restore-points' ) : __( 'Lock', 'tbsh-safespot-restore-points' ) ); ?>">
											<span class="dashicons <?php echo $safespot_f['locked'] ? 'dashicons-lock' : 'dashicons-unlock'; ?>" aria-hidden="true"></span>
										</button>
										<?php if ( $safespot_can_del ) : ?>
											<button type="button" class="button button-small safespot-delete" data-safespot-action="delete" aria-label="<?php esc_attr_e( 'Delete SafeSpot', 'tbsh-safespot-restore-points' ); ?>" title="<?php esc_attr_e( 'Delete', 'tbsh-safespot-restore-points' ); ?>">
												<span class="dashicons dashicons-trash" aria-hidden="true"></span>
											</button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</td>
	</tr>
</tbody>
